#!/bin/bash
# Fige l'adresse IP du Pi et y fait suivre le site (HTTPS, adresse de l'application, pare-feu).
#   sudo bash ~/gestion_stock/current/scripts/poste/ip-fixe.sh                          # fige l'adresse actuelle
#   sudo bash ~/gestion_stock/current/scripts/poste/ip-fixe.sh 10.5.35.176/26            # nouvelle adresse
#   sudo bash ~/gestion_stock/current/scripts/poste/ip-fixe.sh 10.5.35.176/26 10.5.35.129  # ... et passerelle
#   sudo bash ~/gestion_stock/current/scripts/poste/ip-fixe.sh --dhcp                    # revenir au DHCP
#
# Filet de sécurité : le changement tourne en arrière-plan (la session SSH peut couper quand l'adresse change).
# Si la passerelle ne répond pas dans les 60 secondes, l'ancienne configuration est remise toute seule.
# Journal : /var/log/gestion-stock-ip.log
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo "Lancez ce script avec sudo." >&2; exit 1; }
LOG=/var/log/gestion-stock-ip.log

# 1. Relance détachée de la session SSH, puis affichage du journal tant que la connexion tient
if [[ -z "${GS_DETACHED:-}" ]]; then
    : > "$LOG"
    systemd-run --quiet --unit="gestion-stock-ip-$(date +%s)" --setenv=GS_DETACHED=1 \
        --setenv=GS_ADMIN="${SUDO_USER:-cuto}" /bin/bash "$(readlink -f "$0")" "$@"
    echo "Changement lancé (journal : $LOG). Si l'adresse change, cette connexion SSH va se couper :"
    echo "reconnectez-vous ensuite sur la nouvelle adresse."
    echo
    timeout 100 tail -n +1 -f "$LOG" || true
    exit 0
fi
exec >> "$LOG" 2>&1
say() { echo "[$(date +%T)] $*"; }

ADMIN=${GS_ADMIN:-cuto}
ADMIN_HOME=$(getent passwd "$ADMIN" | cut -d: -f6)
ip2int() { local IFS=.; read -r a b c d <<< "$1"; echo $(( (a << 24) | (b << 16) | (c << 8) | d )); }
int2ip() { echo "$(( ($1 >> 24) & 255 )).$(( ($1 >> 16) & 255 )).$(( ($1 >> 8) & 255 )).$(( $1 & 255 ))"; }
network() { local ip=${1%/*} p=${1#*/}; local m=$(( (0xFFFFFFFF << (32 - p)) & 0xFFFFFFFF )); echo $(( $(ip2int "$ip") & m )); }

dev=$(ip -4 route show default | awk '{print $5; exit}')
[[ -n "$dev" ]] || { say "Pas de route par défaut : réseau absent ?"; exit 1; }
con=$(nmcli -g GENERAL.CONNECTION device show "$dev")
mac=$(cat "/sys/class/net/$dev/address")
old_addr=$(ip -4 -o addr show dev "$dev" scope global | awk '{print $4; exit}')
old_gw=$(ip -4 route show default dev "$dev" | awk '{print $3; exit}')
dns=$(nmcli -g IP4.DNS device show "$dev" | sed 's/ | /,/g; s/ /,/g')
search=$(nmcli -g IP4.DOMAIN device show "$dev" | sed 's/ | /,/g')
# Configuration enregistrée, pour revenir en arrière
saved=()
for k in ipv4.method ipv4.addresses ipv4.gateway ipv4.dns ipv4.dns-search ipv4.ignore-auto-dns; do
    saved+=("$k" "$(nmcli -g "$k" con show "$con" | sed 's/ | /,/g')")
done

restore() {
    say "Retour à l'ancienne configuration ($old_addr)."
    nmcli con mod "$con" "${saved[@]}"
    nmcli con up "$con" > /dev/null || true
}

if [[ "${1:-}" == "--dhcp" ]]; then
    new_addr="" new_gw=""
    nmcli con mod "$con" ipv4.method auto ipv4.addresses "" ipv4.gateway "" ipv4.dns "" ipv4.dns-search "" ipv4.ignore-auto-dns no
    say "Passage au DHCP sur $dev ($con)."
else
    new_addr=${1:-$old_addr}
    [[ "$new_addr" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+/[0-9]+$ ]] || new_addr="$new_addr/${old_addr#*/}"
    [[ "$new_addr" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+/[0-9]+$ ]] || { say "Adresse invalide : ${1:-}"; exit 1; }
    new_gw=${2:-}
    if [[ -z "$new_gw" ]]; then
        if [[ $(network "$new_addr") -eq $(network "$old_addr") ]]; then new_gw=$old_gw
        else new_gw=$(int2ip $(( $(network "$new_addr") + 1 ))); fi
    fi
    [[ -n "$dns" ]] || dns="$old_gw"
    say "Interface $dev ($con), MAC $mac"
    say "Avant : $old_addr, passerelle $old_gw"
    say "Après : $new_addr, passerelle $new_gw, DNS $dns ${search:+(recherche $search)}"
    if [[ $(network "$new_addr") -ne $(network "$old_addr") ]]; then
        say "ATTENTION : $new_addr n'est pas sur le même réseau que $old_addr. Cela ne marche que si la prise du Pi"
        say "est sur ce réseau (VLAN) ; sinon, retour automatique dans 60 secondes."
    fi
    nmcli con mod "$con" ipv4.method manual ipv4.addresses "$new_addr" ipv4.gateway "$new_gw" \
        ipv4.dns "$dns" ipv4.dns-search "$search" ipv4.ignore-auto-dns yes
fi

nmcli con up "$con" > /dev/null || { say "La connexion n'a pas pu être appliquée."; restore; exit 1; }

# 2. Contrôle : la passerelle doit répondre dans les 60 secondes, sinon retour en arrière
ok=""
for _ in $(seq 30); do
    gw=$(ip -4 route show default dev "$dev" | awk '{print $3; exit}')
    if [[ -n "$gw" ]] && ping -c 1 -W 1 "$gw" > /dev/null 2>&1; then ok=1; break; fi
    sleep 2
done
if [[ -z "$ok" ]]; then
    say "ÉCHEC : la passerelle ${new_gw:-DHCP} ne répond pas depuis cette prise réseau."
    restore
    say "Le Pi est revenu sur $old_addr. Rien d'autre n'a été modifié."
    exit 1
fi
addr=$(ip -4 -o addr show dev "$dev" scope global | awk '{print $4; exit}')
ip=${addr%/*}
say "Réseau OK : $addr, passerelle $gw joignable."

# 3. Le site suit la nouvelle adresse : certificat HTTPS, liens de connexion, pare-feu
env_file="$ADMIN_HOME/gestion_stock/shared/.env"
if [[ -f "$env_file" ]] && grep -q '^HTTPS_IP=' "$env_file" && [[ "$(sed -n 's/^HTTPS_IP=//p' "$env_file")" != "$ip" ]]; then
    sed -i "s|^HTTPS_IP=.*|HTTPS_IP=$ip|; s|^APP_URL=.*|APP_URL=https://$ip|" "$env_file"
    (cd "$ADMIN_HOME/gestion_stock/current" && sudo -u "$ADMIN" docker compose up -d caddy php > /dev/null 2>&1) \
        && say "Site : https://$ip (certificat refait pour cette adresse)." \
        || say "ATTENTION : redémarrage du site à vérifier (docker compose up -d dans ~/gestion_stock/current)."
fi
if command -v ufw > /dev/null && ufw status | grep -q '^Status: active'; then
    net="$(int2ip "$(network "$addr")")/${addr#*/}"
    ufw allow proto tcp from "$net" to any port 22 comment 'SSH depuis le réseau local' > /dev/null
fi
sleep 3
if curl -fsS -o /dev/null --max-time 5 http://localhost/api/health; then say "Le site répond."; fi
say "Terminé. Faites réserver $ip pour la MAC $mac auprès du service informatique."
