#!/usr/bin/env bash
# =============================================================================
# install_ecran_client.sh : Raspberry supplémentaire, connecté au Wi-Fi de la salle
#
#   - Le connecte au Wi-Fi (NetworkManager) et passe le portail tout seul
#   - Son fond d'écran vient d'Admin › Raspberry : compte affiché, image de fond
#     (relu toutes les 15 s, redessiné seulement si quelque chose a changé)
#
# Ne se lance pas à la main : la commande toute faite d'Admin › Raspberry le télécharge
# (api/raspberry_installation.php) avec tout ce qu'il faut.
# Internet est nécessaire la première fois, pour installer les paquets.
# =============================================================================
set -euo pipefail

PROJET="$(cd "$(dirname "$(readlink -f "$0")")/.." && pwd)"
ok()  { printf '  \033[32m✔\033[0m %s\n' "$*"; }
die() { printf '\033[31mErreur :\033[0m %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "lancez ce script avec sudo."
SSID=""; CLE_WIFI=""; SERVEUR=""; WIFI_ID=""; WIFI_MDP=""; JETON=""
while [[ $# -gt 0 ]]; do
    case $1 in
        --ssid)     SSID=$2 ;;
        --cle-wifi) CLE_WIFI=$2 ;;
        --serveur)  SERVEUR=$2 ;;
        --wifi-id)  WIFI_ID=$2 ;;
        --wifi-mdp) WIFI_MDP=$2 ;;
        --jeton)    JETON=$2 ;;
        *) die "option inconnue : $1" ;;
    esac
    shift 2
done
[[ -n $SSID && -n $SERVEUR && -n $WIFI_ID && -n $WIFI_MDP && -n $JETON ]] \
    || die "options manquantes : copiez la commande complète depuis Admin › Raspberry."
command -v nmcli >/dev/null || die "NetworkManager (nmcli) est nécessaire."
ECRAN_USER=${SUDO_USER:-$(stat -c %U "$PROJET")}

if ! python3 -c 'import qrcode, PIL' 2>/dev/null || [[ ! -f /usr/share/fonts/truetype/dejavu/DejaVuSans.ttf ]]; then
    { apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq python3-qrcode python3-pil fonts-dejavu-core >/dev/null; } \
        || die "installation des paquets impossible : reliez ce Raspberry à internet (câble réseau ou autre Wi-Fi), puis relancez la commande."
fi
ok "paquets prêts"

# Wi-Fi de la salle : sans internet, donc jamais route par défaut (l'Ethernet éventuel la garde)
nmcli connection delete sabre-salle >/dev/null 2>&1 || true
nmcli connection add type wifi con-name sabre-salle ifname wlan0 ssid "$SSID" \
    connection.autoconnect yes connection.autoconnect-retries 0 \
    ipv4.never-default yes ipv4.ignore-auto-dns yes ipv6.method disabled >/dev/null
if [[ -n $CLE_WIFI ]]; then
    nmcli connection modify sabre-salle wifi-sec.key-mgmt wpa-psk wifi-sec.psk "$CLE_WIFI"
fi
nmcli connection up sabre-salle >/dev/null 2>&1 || echo "  Wi-Fi « $SSID » pas encore joignable : le Pi s'y connectera dès qu'il le verra."
ok "Wi-Fi « $SSID » configuré"

install -d -m 755 /etc/sabre /var/lib/sabre-ecran
install -m 755 "$PROJET/raspberry/sabre-fond-ecran.py" /usr/local/sbin/sabre-fond-ecran
printf 'ECRAN_USER="%s"\n' "$ECRAN_USER" > /etc/sabre/ecran.env
( umask 077; printf 'SERVEUR=%q\nJETON=%q\nWIFI_ID=%q\nWIFI_MDP=%q\n' "$SERVEUR" "$JETON" "$WIFI_ID" "$WIFI_MDP" > /etc/sabre/client.env )

cat > /etc/systemd/system/sabre-fond-ecran.service <<'EOF'
[Unit]
Description=Sabre : fond d'écran avec les infos de connexion (Admin › Raspberry)

[Service]
Type=oneshot
ExecStart=/usr/bin/python3 /usr/local/sbin/sabre-fond-ecran
EOF
cat > /etc/systemd/system/sabre-fond-ecran.timer <<'EOF'
[Unit]
Description=Sabre : fond d'écran à jour avec Admin › Raspberry (toutes les 15 s)

[Timer]
OnBootSec=25s
OnUnitActiveSec=15s
AccuracySec=1s

[Install]
WantedBy=timers.target
EOF
systemctl daemon-reload
systemctl enable --now sabre-fond-ecran.timer >/dev/null
ok "fond d'écran : mis à jour toutes les 15 s"
echo "  Vérifier : journalctl -u sabre-fond-ecran -n 20"
