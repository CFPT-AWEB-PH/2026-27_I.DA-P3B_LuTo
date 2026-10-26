#!/usr/bin/env bash
# =============================================================================
# install_wifi.sh : transforme le Raspberry Pi en point d'accès Wi-Fi ISOLÉ
#
#   - Portail de connexion : en rejoignant le Wi-Fi, une page demande un
#     IDENTIFIANT + MOT DE PASSE (comptes gérés dans l'app : Admin › Wi-Fi).
#     Tant qu'un appareil ne s'est pas identifié, il n'accède à rien.
#   - Réseau fermé : pas d'internet, pas d'accès au réseau local du Pi,
#     seuls DNS/DHCP, le portail et l'application sont joignables depuis le Wi-Fi
#   - HTTPS avec une autorité de certification locale (PWA installable)
#   (La puce Wi-Fi intégrée du Raspberry ne gère pas le WPA2-Entreprise en point
#    d'accès : l'identification se fait donc par ce portail.)
#
# Usage (depuis le dossier du projet, Raspberry Pi OS Bookworm ou Bullseye) :
#   sudo ./raspberry/install_wifi.sh                      installation interactive
#   sudo ./raspberry/install_wifi.sh --ssid "Tournoi" --pays CH --canal 6
#   sudo ./raspberry/install_wifi.sh --desinstaller       revenir en arrière
#
# Options : --ssid NOM  --pays CODE  --canal N  --interface wlan0
#           --domaine sabre.lan  --sans-docker  --oui (aucune question)
#           --mot-de-passe-wifi MDP   mot de passe commun du Wi-Fi (WPA2) avant le portail
#           --wifi-ouvert             Wi-Fi sans mot de passe, seul le portail identifie (défaut)
#           --session-heures N        durée d'une connexion au portail (12 h par défaut)
#           --wifi-seulement   l'app n'est plus joignable depuis le réseau local (LAN)
#           --lan              l'app reste joignable en LAN : http://<IP-du-Pi>:8080 (par défaut)
# Le Pi doit avoir internet par ETHERNET pendant l'installation (paquets, images).
# =============================================================================
set -euo pipefail

PROJET="$(cd "$(dirname "$(readlink -f "$0")")/.." && pwd)"
CONF=/etc/sabre/ap.env
PKI=/etc/sabre/pki
RESEAU=10.42.0
IP_AP=$RESEAU.1
IP_SONDE=4.3.2.1     # adresse publique factice des noms inconnus (jamais réellement jointe)

bleu() { printf '\033[1;36m%s\033[0m\n' "$*"; }
ok()   { printf '  \033[32m✔\033[0m %s\n' "$*"; }
warn() { printf '  \033[33m!\033[0m %s\n' "$*"; }
die()  { printf '\033[31mErreur :\033[0m %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "lancez ce script avec sudo : sudo $0 $*"

# ── Paramètres (installation précédente, puis options) ──────────────────────
[[ -r $CONF ]] && . "$CONF"
SSID=${SSID:-Sabre-Arbitrage}; PAYS=${PAYS:-CH}; CANAL=${CANAL:-6}
IFACE=${IFACE:-wlan0}; DOMAINE=${DOMAINE:-sabre.lan}; LAN=${LAN:-1}
WIFI_MDP=${WIFI_MDP:-}; SESSION_HEURES=${SESSION_HEURES:-12}; PORTAIL_PORT=8082
OUI=0; SANS_DOCKER=0; DESINSTALLER=0; SSID_OPTION=0
while (( $# )); do
    case $1 in
        --ssid) SSID=$2; SSID_OPTION=1; shift ;;
        --pays) PAYS=${2^^}; shift ;;
        --canal) CANAL=$2; shift ;;
        --interface) IFACE=$2; shift ;;
        --domaine) DOMAINE=${2,,}; shift ;;
        --sans-docker) SANS_DOCKER=1 ;;
        --oui|-y) OUI=1 ;;
        --lan) LAN=1 ;;
        --mot-de-passe-wifi) WIFI_MDP=$2; shift ;;
        --wifi-ouvert) WIFI_MDP="" ;;
        --session-heures) SESSION_HEURES=$2; shift ;;
        --wifi-seulement) LAN=0 ;;
        --desinstaller) DESINSTALLER=1 ;;
        -h|--help) sed -n '2,28p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) die "option inconnue : $1 (voir --help)" ;;
    esac
    shift
done

# ── Fichier .env du projet ───────────────────────────────────────────────────
env_set() {   # env_set CLE VALEUR : ajoute ou remplace une ligne CLE=VALEUR dans .env
    local f="$PROJET/.env"
    [[ -f $f ]] || return 0
    if grep -qE "^$1=" "$f"; then sed -i "s|^$1=.*|$1=$2|" "$f"; else printf '%s=%s\n' "$1" "$2" >> "$f"; fi
}
env_get() { grep -E "^$1=" "$PROJET/.env" 2>/dev/null | tail -1 | cut -d= -f2- || true; }

compose() { (cd "$PROJET" && docker compose "$@"); }

# =============================================================================
# DÉSINSTALLATION
# =============================================================================
if (( DESINSTALLER )); then
    bleu "Désinstallation du point d'accès Wi-Fi"
    systemctl disable --now sabre-wifi-sync.timer sabre-portail.service sabre-ap-firewall.service hostapd dnsmasq sabre-ap-ip.service 2>/dev/null || true
    nft delete table inet sabre_ap 2>/dev/null || true
    rm -f /etc/systemd/system/sabre-ap-ip.service /etc/systemd/system/sabre-ap-firewall.service \
          /etc/systemd/system/sabre-wifi-sync.service /etc/systemd/system/sabre-wifi-sync.timer \
          /etc/systemd/system/sabre-portail.service /usr/local/sbin/sabre-portail \
          /etc/dnsmasq.d/sabre-ap.conf /etc/NetworkManager/conf.d/sabre-ap.conf /usr/local/sbin/sabre-wifi
    [[ -f /etc/hostapd/hostapd.conf.avant-sabre ]] && mv -f /etc/hostapd/hostapd.conf.avant-sabre /etc/hostapd/hostapd.conf
    [[ -f /etc/dhcpcd.conf ]] && sed -i '/# sabre-ap$/d' /etc/dhcpcd.conf
    systemctl daemon-reload
    systemctl restart NetworkManager 2>/dev/null || systemctl restart dhcpcd 2>/dev/null || true
    ip addr flush dev "$IFACE" 2>/dev/null || true
    if command -v docker >/dev/null && [[ -f $PROJET/.env ]]; then
        compose --profile pi rm -sf proxy >/dev/null 2>&1 || true
        env_set APP_BIND 0.0.0.0
        prof=$(env_get COMPOSE_PROFILES | tr ',' '\n' | grep -vx pi | paste -sd, -)
        if [[ -n $prof ]]; then env_set COMPOSE_PROFILES "$prof"; else sed -i '/^COMPOSE_PROFILES=/d' "$PROJET/.env"; fi
        (( SANS_DOCKER )) || compose up -d
    fi
    ok "Point d'accès retiré. L'app écoute de nouveau sur le réseau local (port $(env_get APP_PORT))."
    ok "Conservés : l'autorité de certification ($PKI) et les comptes de secours (/etc/sabre)."
    exit 0
fi

# =============================================================================
# VÉRIFICATIONS
# =============================================================================
[[ $SSID =~ ^[A-Za-z0-9\ ._-]{1,32}$ ]]        || die "SSID invalide (1 à 32 caractères : lettres, chiffres, espace . _ -)."
[[ $PAYS =~ ^[A-Z]{2}$ ]]                      || die "code pays invalide (ex. CH, FR, BE)."
[[ $CANAL =~ ^([1-9]|1[0-3])$ ]]               || die "canal invalide (1 à 13)."
[[ $IFACE =~ ^[a-z0-9]+$ ]]                    || die "interface invalide."
[[ $DOMAINE =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$ ]] || die "domaine invalide (ex. sabre.lan)."
[[ -z $WIFI_MDP || $WIFI_MDP =~ ^[A-Za-z0-9._@#%+=-]{8,63}$ ]] || die "mot de passe Wi-Fi invalide (8 à 63 caractères : lettres, chiffres, . _ @ # % + = -)."
[[ $SESSION_HEURES =~ ^[0-9]{1,3}$ ]] && (( SESSION_HEURES >= 1 )) || die "durée de session invalide (en heures)."
[[ -f $PROJET/docker-compose.yml ]]            || die "lancez le script depuis le dossier du projet (docker-compose.yml introuvable)."
ip link show "$IFACE" >/dev/null 2>&1          || die "interface Wi-Fi « $IFACE » introuvable (ip link)."

bleu "Point d'accès Wi-Fi isolé : $SSID"
echo "  Interface $IFACE · pays $PAYS · canal $CANAL · adresse $IP_AP · app https://$DOMAINE"

if ip route show default 2>/dev/null | grep -q "dev $IFACE"; then
    warn "Le Pi accède actuellement à internet PAR LE WI-FI ($IFACE)."
    warn "Cette connexion va être coupée : branchez un câble Ethernet (ou un 2e adaptateur Wi-Fi)."
    if (( ! OUI )); then read -rp "  Continuer quand même ? [o/N] " r; [[ ${r,,} == o* ]] || exit 1; fi
fi
if [[ ! -f $PROJET/.env ]]; then
    warn ".env introuvable : copiez .env.example en .env et remplissez les mots de passe avant de lancer l'app."
fi

# =============================================================================
# 1. PAQUETS
# =============================================================================
bleu "1/7 Paquets"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq hostapd dnsmasq nftables openssl iw rfkill python3 >/dev/null
ok "hostapd, dnsmasq, nftables, openssl"
# dnsmasq ne doit PAS devenir le DNS du Pi lui-même (il ne connaît que le domaine local)
if [[ -f /etc/default/dnsmasq ]]; then
    grep -q '^DNSMASQ_EXCEPT=' /etc/default/dnsmasq || echo 'DNSMASQ_EXCEPT="lo"' >> /etc/default/dnsmasq
fi

# =============================================================================
# 2. CONFIGURATION ET AUTORITÉ DE CERTIFICATION
# =============================================================================
bleu "2/7 Certificats (autorité locale)"
install -d -m 755 /etc/sabre
cat > "$CONF" <<EOF
# Point d'accès Wi-Fi du tournoi (généré par install_wifi.sh)
SSID="$SSID"
PAYS="$PAYS"
CANAL="$CANAL"
IFACE="$IFACE"
DOMAINE="$DOMAINE"
IP_AP="$IP_AP"
PROJET="$PROJET"
LAN="$LAN"
WIFI_MDP="$WIFI_MDP"
SESSION_HEURES="$SESSION_HEURES"
PORTAIL_PORT="$PORTAIL_PORT"
EOF
chmod 600 "$CONF"
install -d -m 700 "$PKI/ca"; install -d -m 750 "$PKI/web"; install -d -m 755 "$PKI/public"

if [[ ! -f $PKI/ca/ca.key ]]; then
    # Autorité limitée par « name constraints » : elle ne peut signer QUE pour le domaine
    # du tournoi et le réseau du Wi-Fi. Même installée sur un téléphone, elle ne permet
    # pas d'usurper un autre site.
    openssl req -x509 -new -nodes -newkey rsa:3072 -sha256 -days 3650 \
        -keyout "$PKI/ca/ca.key" -out "$PKI/ca/ca.crt" \
        -subj "/O=Sabre Arbitrage/CN=Sabre Arbitrage" \
        -addext "basicConstraints=critical,CA:TRUE,pathlen:0" \
        -addext "keyUsage=critical,keyCertSign,cRLSign" \
        -addext "nameConstraints=critical,permitted;DNS:$DOMAINE,permitted;IP:$RESEAU.0/255.255.255.0" 2>/dev/null
    chmod 600 "$PKI/ca/ca.key"
    ok "autorité créée (valable 10 ans, limitée à $DOMAINE et $RESEAU.0/24)"
else
    ok "autorité existante conservée"
fi
if ! openssl x509 -in "$PKI/ca/ca.crt" -noout -text 2>/dev/null | grep -q "DNS:$DOMAINE"; then
    warn "L'autorité existante ne couvre pas $DOMAINE : supprimez $PKI pour la recréer (les appareils devront réinstaller le certificat)."
fi

# Certificat du serveur (web + Wi-Fi) : recréé s'il manque, expire dans < 60 j ou si le domaine a changé
besoin_cert=1
if [[ -f $PKI/web/server.crt ]] \
   && openssl x509 -in "$PKI/web/server.crt" -noout -checkend $((60*86400)) >/dev/null 2>&1 \
   && openssl x509 -in "$PKI/web/server.crt" -noout -ext subjectAltName 2>/dev/null | grep -q "DNS:$DOMAINE"; then
    besoin_cert=0
fi
if (( besoin_cert )); then
    tmp=$(mktemp -d)
    openssl req -new -nodes -newkey rsa:2048 -keyout "$PKI/web/server.key" -out "$tmp/server.csr" \
        -subj "/O=Sabre Arbitrage/CN=$DOMAINE" 2>/dev/null
    cat > "$tmp/ext.cnf" <<EOF
basicConstraints=critical,CA:FALSE
keyUsage=critical,digitalSignature,keyEncipherment
extendedKeyUsage=serverAuth
subjectAltName=DNS:$DOMAINE,IP:$IP_AP
EOF
    # 800 jours : limite acceptée par iOS / macOS pour un certificat serveur
    openssl x509 -req -in "$tmp/server.csr" -CA "$PKI/ca/ca.crt" -CAkey "$PKI/ca/ca.key" -CAcreateserial \
        -CAserial "$PKI/ca/ca.srl" -days 800 -sha256 -extfile "$tmp/ext.cnf" -out "$PKI/web/server.crt" 2>/dev/null
    rm -rf "$tmp"
    chmod 640 "$PKI/web/server.key"
    ok "certificat serveur émis pour $DOMAINE et $IP_AP (800 jours)"
else
    ok "certificat serveur encore valide"
fi
cp -f "$PKI/ca/ca.crt" "$PKI/public/sabre-ca.crt"; chmod 644 "$PKI/public/sabre-ca.crt"

# =============================================================================
# 3. INTERFACE WI-FI (sortie de NetworkManager / dhcpcd, adresse fixe)
# =============================================================================
bleu "3/7 Interface $IFACE"
rfkill unblock wlan 2>/dev/null || true
if command -v raspi-config >/dev/null; then raspi-config nonint do_wifi_country "$PAYS" >/dev/null 2>&1 || true; fi
if systemctl is-active --quiet NetworkManager; then
    install -d /etc/NetworkManager/conf.d
    printf '[keyfile]\nunmanaged-devices=interface-name:%s\n' "$IFACE" > /etc/NetworkManager/conf.d/sabre-ap.conf
    nmcli device set "$IFACE" managed no 2>/dev/null || true
    systemctl reload NetworkManager 2>/dev/null || true
    ok "NetworkManager ne gère plus $IFACE"
fi
if [[ -f /etc/dhcpcd.conf ]] && ! grep -q "# sabre-ap$" /etc/dhcpcd.conf; then
    echo "denyinterfaces $IFACE  # sabre-ap" >> /etc/dhcpcd.conf
    systemctl restart dhcpcd 2>/dev/null || true
    ok "dhcpcd ignore $IFACE"
fi

cat > /etc/systemd/system/sabre-ap-ip.service <<EOF
[Unit]
Description=Sabre : adresse fixe du point d'accès Wi-Fi ($IFACE)
After=sys-subsystem-net-devices-$IFACE.device NetworkManager.service dhcpcd.service
Wants=sys-subsystem-net-devices-$IFACE.device
Before=hostapd.service dnsmasq.service

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStartPre=-/usr/sbin/rfkill unblock wlan
ExecStart=/sbin/ip link set $IFACE up
ExecStart=/sbin/ip addr replace $IP_AP/24 dev $IFACE
ExecStop=-/sbin/ip addr del $IP_AP/24 dev $IFACE

[Install]
WantedBy=multi-user.target
EOF
ok "adresse $IP_AP/24"

# =============================================================================
# 4. HOSTAPD : point d'accès (ouvert ou WPA2 à mot de passe commun)
# =============================================================================
bleu "4/7 Wi-Fi (hostapd)"
if [[ -f /etc/hostapd/hostapd.conf && ! -f /etc/hostapd/hostapd.conf.avant-sabre ]] && ! grep -q 'Sabre' /etc/hostapd/hostapd.conf; then
    cp /etc/hostapd/hostapd.conf /etc/hostapd/hostapd.conf.avant-sabre
fi
if [[ -n $WIFI_MDP ]]; then
    SECURITE="# WPA2 avec un mot de passe commun ; l'identification personnelle se fait ensuite sur le portail
wpa=2
wpa_key_mgmt=WPA-PSK
rsn_pairwise=CCMP
wpa_passphrase=$WIFI_MDP"
else
    SECURITE="# Wi-Fi ouvert : l'identification (identifiant + mot de passe) se fait sur le portail"
fi
cat > /etc/hostapd/hostapd.conf <<EOF2
# Point d'accès du tournoi Sabre Arbitrage (généré par install_wifi.sh : NE PAS MODIFIER)
interface=$IFACE
driver=nl80211
ctrl_interface=/var/run/hostapd
ctrl_interface_group=0

ssid=$SSID
utf8_ssid=1
country_code=$PAYS
ieee80211d=1
hw_mode=g
channel=$CANAL
ieee80211n=1
wmm_enabled=1
auth_algs=1
max_num_sta=64

$SECURITE
EOF2
chmod 600 /etc/hostapd/hostapd.conf
rm -f /etc/hostapd/sabre-eap-users "$PKI/web/fullchain.crt"      # ancienne version (WPA2-Entreprise)
[[ -f /etc/default/hostapd ]] && sed -i 's|^#\?DAEMON_CONF=.*|DAEMON_CONF="/etc/hostapd/hostapd.conf"|' /etc/default/hostapd
install -m 755 "$PROJET/raspberry/sabre-wifi" /usr/local/sbin/sabre-wifi
install -m 755 "$PROJET/raspberry/sabre-portail.py" /usr/local/sbin/sabre-portail
install -d -m 700 /var/lib/sabre-wifi
touch /etc/sabre/wifi-secours; chmod 600 /etc/sabre/wifi-secours
if [[ -n $WIFI_MDP ]]; then ok "SSID « $SSID », WPA2 (mot de passe : $WIFI_MDP) + portail"
else ok "SSID « $SSID », ouvert + portail de connexion"; fi

# =============================================================================
# 5. DHCP + DNS (dnsmasq) : seul le domaine du tournoi existe
# =============================================================================
bleu "5/7 DHCP et DNS"
cat > /etc/dnsmasq.d/sabre-ap.conf <<EOF
# Wi-Fi du tournoi (généré par install_wifi.sh)
interface=$IFACE
except-interface=lo
bind-dynamic
domain-needed
bogus-priv
# Aucun serveur DNS externe : ce réseau n'a pas internet.
# TOUS les autres noms répondent une adresse PUBLIQUE factice ($IP_SONDE) que le
# pare-feu renvoie vers le portail. Android ignore ses tests de connexion quand
# la réponse est une adresse privée (10.x, 192.168.x) : il afficherait alors
# « pas d'internet » au lieu d'ouvrir la page « Se connecter au réseau ».
no-resolv
no-hosts
local=/lan/
address=/$DOMAINE/$IP_AP
address=/#/$IP_SONDE
# Windows vérifie aussi que ce nom donne cette adresse précise : sinon il conclut
# « pas d'internet » au lieu d'ouvrir la page de connexion
address=/dns.msftncsi.com/131.107.255.255
dhcp-range=$RESEAU.20,$RESEAU.250,255.255.255.0,12h
dhcp-option=option:router,$IP_AP
dhcp-option=option:dns-server,$IP_AP
dhcp-option=option:domain-name,lan
dhcp-authoritative
EOF
ok "DHCP $RESEAU.20 → .250, DNS : tout → $IP_AP (portail)"

# =============================================================================
# 6. PARE-FEU : le Wi-Fi ne mène qu'à l'application
# =============================================================================
bleu "6/7 Pare-feu"

# Ports du proxy HTTPS. Les téléphones arrivent toujours sur 80/443 (http(s)://sabre.lan) ;
# si un autre programme du Pi occupe déjà 80 ou 443, le proxy écoute sur 8081/8443
# et le pare-feu redirige le trafic Wi-Fi vers ces ports (rien à changer côté téléphones).
port_occupe() {   # affiche le processus qui écoute sur ce port (hors proxy Sabre), sinon rien
    local l; l=$(ss -Htlnp "sport = :$1" 2>/dev/null | head -1)
    [[ -z $l ]] && return 0
    docker ps --format '{{.Names}} {{.Ports}}' 2>/dev/null | grep -q "^sabre_proxy .*:$1->" && return 0
    echo "$l" | grep -o 'users:(([^)]*' | sed 's/users:((//'
}
# Le proxy et le portail Sabre déjà lancés (installation précédente) ne comptent pas comme « occupants »
docker rm -f sabre_proxy >/dev/null 2>&1 || true
systemctl stop sabre-portail 2>/dev/null || true
[[ -z $(port_occupe $PORTAIL_PORT) ]] || die "le port $PORTAIL_PORT (portail) est déjà utilisé sur le Pi : $(port_occupe $PORTAIL_PORT)"
HTTP_PORT=80; HTTPS_PORT=443
for p in 80 443; do
    occ=$(port_occupe $p)
    [[ -z $occ ]] && continue
    pid=$(echo "$occ" | grep -o 'pid=[0-9]*' | head -1 | cut -d= -f2)
    unite=$( [[ -n $pid ]] && ps -o unit= -p "$pid" 2>/dev/null | tr -d ' ' || true)
    warn "Le port $p est déjà utilisé sur le Pi : $occ${unite:+ (service $unite)}"
    if [[ $unite == *.service && $unite != docker.service && $unite != containerd.service ]] && (( ! OUI )); then
        read -rp "  Arrêter et désactiver $unite pour libérer le port $p ? [o/N] " r
        if [[ ${r,,} == o* ]]; then systemctl disable --now "$unite"; sleep 1; ok "$unite arrêté"; continue; fi
    fi
    HTTP_PORT=8081; HTTPS_PORT=8443
done
if [[ $HTTP_PORT != 80 ]]; then
    for p in $HTTP_PORT $HTTPS_PORT; do [[ -z $(port_occupe $p) ]] || die "les ports 80/443 et $p sont occupés : libérez-en un."; done
    warn "Proxy sur les ports $HTTP_PORT/$HTTPS_PORT ; le Wi-Fi y est redirigé automatiquement (80 → $HTTP_PORT, 443 → $HTTPS_PORT)."
fi
if (( LAN )); then
    BLOCAGE_LAN_INPUT=""
    BLOCAGE_LAN="        # Réseau local -> conteneurs : autorisé (app joignable en LAN sur le port de .env, APP_PORT)"
else
    BLOCAGE_LAN_INPUT="        # Proxy HTTPS : réservé au Wi-Fi (option --wifi-seulement)
        iifname != \"lo\" iifname != \"$IFACE\" tcp dport { $HTTP_PORT, $HTTPS_PORT } drop"
    BLOCAGE_LAN="        # Réseau local / internet -> conteneurs : interdit (l'app n'est publiée QUE sur le Wi-Fi)
        oifname \"docker0\" iifname != \"docker0\" iifname != \"br-*\" drop
        oifname \"br-*\" iifname != \"docker0\" iifname != \"br-*\" drop"
fi
cat > /etc/sabre/ap-firewall.nft <<EOF
# Pare-feu du Wi-Fi du tournoi (généré par install_wifi.sh)
table inet sabre_ap
delete table inet sabre_ap
table inet sabre_ap {
    # Appareils identifiés sur le portail (adresse IP + MAC), ajoutés par sabre-portail
    set autorises {
        type ipv4_addr . ether_addr
        flags timeout
    }

    # Web depuis le Wi-Fi : appareils identifiés -> application ; les autres -> portail
    chain prerouting {
        type nat hook prerouting priority dstnat - 10; policy accept;
        iifname "$IFACE" ip saddr . ether saddr @autorises tcp dport 80 redirect to :$HTTP_PORT
        # HTTPS : seulement vers le Pi lui-même. Vers un autre site (4.3.2.1 = tous les noms
        # inconnus), la connexion est refusée aussitôt : le navigateur se rabat tout de suite
        # sur http:// au lieu d'afficher une erreur de certificat.
        iifname "$IFACE" ip saddr . ether saddr @autorises ip daddr $IP_AP tcp dport 443 redirect to :$HTTPS_PORT
        iifname "$IFACE" tcp dport 80 redirect to :$PORTAIL_PORT
        # Appareils qui interrogent un DNS écrit en dur (8.8.8.8, 1.1.1.1…) : renvoyés vers le Pi
        iifname "$IFACE" udp dport 53 redirect to :53
        iifname "$IFACE" tcp dport 53 redirect to :53
    }

    # Depuis le Wi-Fi, le Pi n'offre que DHCP, DNS, le portail et (une fois identifié) l'app.
    # Pas de SSH, pas de base de données.
    chain input {
        type filter hook input priority filter - 10; policy accept;
$BLOCAGE_LAN_INPUT
        iifname "$IFACE" ct state established,related accept
        iifname "$IFACE" udp dport { 53, 67 } accept
        iifname "$IFACE" tcp dport { 53, $PORTAIL_PORT } accept
        iifname "$IFACE" ip saddr . ether saddr @autorises tcp dport { $HTTP_PORT, $HTTPS_PORT } accept
        iifname "$IFACE" tcp dport { 80, 443, $HTTP_PORT, $HTTPS_PORT } reject with tcp reset
        iifname "$IFACE" icmp type echo-request limit rate 5/second accept
        iifname "$IFACE" icmpv6 type { echo-request, nd-neighbor-solicit, nd-neighbor-advert, nd-router-solicit } accept
        # Tout le reste est REFUSÉ tout de suite (et non ignoré) : le téléphone n'attend pas
        # (DNS chiffré port 853, QUIC…), il bascule aussitôt et détecte le portail plus vite.
        iifname "$IFACE" meta l4proto tcp reject with tcp reset
        iifname "$IFACE" reject with icmpx type port-unreachable
    }

    chain forward {
        type filter hook forward priority filter - 10; policy accept;
        ct state established,related accept
        # Wi-Fi -> app (conteneurs Docker) : seulement les appareils identifiés.
        # Wi-Fi -> internet / réseau local : jamais.
        iifname "$IFACE" ip saddr . ether saddr @autorises oifname "docker0" accept
        iifname "$IFACE" ip saddr . ether saddr @autorises oifname "br-*" accept
        iifname "$IFACE" meta l4proto tcp reject with tcp reset
        iifname "$IFACE" reject with icmpx type admin-prohibited
$BLOCAGE_LAN
    }
}
EOF
cat > /etc/systemd/system/sabre-ap-firewall.service <<EOF
[Unit]
Description=Sabre : pare-feu du point d'accès Wi-Fi
Before=hostapd.service dnsmasq.service docker.service
Wants=network-pre.target
After=network-pre.target

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/usr/sbin/nft -f /etc/sabre/ap-firewall.nft
ExecStop=/usr/sbin/nft delete table inet sabre_ap

[Install]
WantedBy=multi-user.target
EOF
nft -c -f /etc/sabre/ap-firewall.nft || die "règles de pare-feu refusées par nft."
ok "Wi-Fi : portail obligatoire, puis uniquement l'app ; ni internet, ni réseau local, ni SSH"
if (( LAN )); then ok "réseau local (LAN) : l'app reste joignable sur http://<IP-du-Pi>:$(env_get APP_PORT)"
else ok "réseau local (LAN) : l'app n'est plus joignable (option --wifi-seulement)"; fi

# Portail de connexion (identifiant + mot de passe)
cat > /etc/systemd/system/sabre-portail.service <<'EOF'
[Unit]
Description=Sabre : portail de connexion du Wi-Fi
After=sabre-ap-ip.service sabre-ap-firewall.service
Wants=sabre-ap-ip.service

[Service]
ExecStart=/usr/bin/python3 /usr/local/sbin/sabre-portail
Restart=always
RestartSec=2
# Droits minimaux : modifier le pare-feu, rien d'autre
CapabilityBoundingSet=CAP_NET_ADMIN CAP_NET_BIND_SERVICE
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=read-only
ReadWritePaths=/var/lib/sabre-wifi
PrivateTmp=yes

[Install]
WantedBy=multi-user.target
EOF

# Synchronisation des comptes (Admin › Wi-Fi) toutes les 15 secondes
cat > /etc/systemd/system/sabre-wifi-sync.service <<'EOF'
[Unit]
Description=Sabre : synchronise les comptes Wi-Fi avec l'application
After=docker.service

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/sabre-wifi sync
EOF
cat > /etc/systemd/system/sabre-wifi-sync.timer <<'EOF'
[Unit]
Description=Sabre : synchronisation des comptes Wi-Fi (toutes les 15 s)

[Timer]
OnBootSec=20s
OnUnitActiveSec=15s
AccuracySec=1s

[Install]
WantedBy=timers.target
EOF

# =============================================================================
# 7. DÉMARRAGE
# =============================================================================
bleu "7/7 Démarrage"
systemctl daemon-reload
systemctl unmask hostapd >/dev/null 2>&1 || true
systemctl enable sabre-ap-firewall.service sabre-ap-ip.service >/dev/null
# restart (et pas --now) : sinon les règles d'une installation précédente resteraient chargées
systemctl restart sabre-ap-firewall.service sabre-ap-ip.service
systemctl enable dnsmasq hostapd >/dev/null
systemctl restart dnsmasq
systemctl restart hostapd || { journalctl -u hostapd -n 30 --no-pager; die "hostapd ne démarre pas (voir ci-dessus)."; }
systemctl enable sabre-portail.service >/dev/null
systemctl restart sabre-portail.service
systemctl enable --now sabre-wifi-sync.timer >/dev/null
ok "point d'accès « $SSID » et portail de connexion en service"

# Compte de secours : permet la toute première connexion (avant d'avoir créé
# des comptes dans l'app), ou de dépanner si la base de données est arrêtée.
if [[ ! -s /etc/sabre/wifi-secours ]]; then
    echo
    bleu "Compte de secours du portail (local au Raspberry)"
    if (( OUI )); then /usr/local/sbin/sabre-wifi secours-ajouter admin --generer
    else
        read -rp "  Identifiant [admin] : " id_secours
        /usr/local/sbin/sabre-wifi secours-ajouter "${id_secours:-admin}"
    fi
fi

# Application : écoute uniquement en local, publiée par le proxy HTTPS du Wi-Fi
if [[ -f $PROJET/.env ]]; then
    if (( LAN )); then env_set APP_BIND 0.0.0.0; else env_set APP_BIND 127.0.0.1; fi
    env_set PROXY_HTTP_PORT "$HTTP_PORT"
    env_set PROXY_HTTPS_PORT "$HTTPS_PORT"
    env_set SABRE_DOMAINE "$DOMAINE"
    prof=$(env_get COMPOSE_PROFILES)
    [[ ,$prof, == *,pi,* ]] || env_set COMPOSE_PROFILES "${prof:+$prof,}pi"
    # Puce Bluetooth présente : récepteur des sabres (service ble_receiver) démarré aussi
    prof=$(env_get COMPOSE_PROFILES)
    if [[ -d /sys/class/bluetooth/hci0 && ,$prof, != *,ble,* ]]; then env_set COMPOSE_PROFILES "$prof,ble"; fi
    ok ".env : APP_BIND=$(env_get APP_BIND), proxy $HTTP_PORT/$HTTPS_PORT, COMPOSE_PROFILES=$(env_get COMPOSE_PROFILES)"
    if (( ! SANS_DOCKER )) && command -v docker >/dev/null; then
        bleu "Lancement de l'application (docker compose up -d --build)"
        compose up -d --build
    fi
fi
# Nom du Wi-Fi donné avec --ssid : enregistré aussi dans l'app (Admin › Wi-Fi), sinon
# la synchronisation remettrait l'ancien nom choisi dans l'interface
if (( SSID_OPTION )); then /usr/local/sbin/sabre-wifi definir-ssid "$SSID" || true; fi
/usr/local/sbin/sabre-wifi sync || true

echo
bleu "Terminé"
cat <<EOF
  Wi-Fi        : $SSID   ($( [[ -n $WIFI_MDP ]] && echo "mot de passe : $WIFI_MDP" || echo "ouvert" ))
  Connexion    : la page du portail s'ouvre seule (sinon http://$DOMAINE) : identifiant + mot de passe
  Application  : ensuite http://$DOMAINE (directement, rien à installer)
  Facultatif   : http://$DOMAINE/certificat pour la version HTTPS (plein écran sur Android)
  Comptes      : Admin › Wi-Fi dans l'app (appliqués en ~15 s), ainsi que le nom du Wi-Fi
  Secours      : sudo sabre-wifi secours-ajouter <identifiant>
  État         : sudo sabre-wifi statut
  Appareils    : sudo sabre-wifi sessions
  Retour arrière : sudo bash $PROJET/raspberry/install_wifi.sh --desinstaller
EOF
