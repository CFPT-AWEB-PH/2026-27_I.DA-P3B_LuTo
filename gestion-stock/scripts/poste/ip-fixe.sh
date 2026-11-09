#!/bin/bash
# Fige l'adresse IP du Pi (aujourd'hui donnée par DHCP) : le certificat HTTPS et les favoris en dépendent.
#   sudo bash ~/gestion_stock/current/scripts/poste/ip-fixe.sh              # garde l'adresse actuelle
#   sudo bash ~/gestion_stock/current/scripts/poste/ip-fixe.sh 10.5.35.38/26
#   sudo bash ~/gestion_stock/current/scripts/poste/ip-fixe.sh --dhcp       # revenir au DHCP
# Passerelle, DNS et domaine de recherche sont repris de la configuration actuelle.
# À faire aussi : demander au service informatique de réserver cette adresse (adresse MAC affichée ci-dessous),
# sinon le serveur DHCP pourrait la donner à une autre machine.
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo "Lancez ce script avec sudo." >&2; exit 1; }

dev=$(ip -4 route show default | awk '{print $5; exit}')
[[ -n "$dev" ]] || { echo "Pas de route par défaut : réseau absent ?" >&2; exit 1; }
con=$(nmcli -g GENERAL.CONNECTION device show "$dev")
mac=$(cat "/sys/class/net/$dev/address")

if [[ "${1:-}" == "--dhcp" ]]; then
    nmcli con mod "$con" ipv4.method auto ipv4.addresses "" ipv4.gateway "" ipv4.dns "" ipv4.dns-search "" ipv4.ignore-auto-dns no
    nmcli device reapply "$dev" > /dev/null 2>&1 || nmcli con up "$con" > /dev/null
    echo "Retour au DHCP sur $dev ($con)."
    exit 0
fi

addr=${1:-$(ip -4 -o addr show dev "$dev" scope global | awk '{print $4; exit}')}
[[ "$addr" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+/[0-9]+$ ]] || { echo "Adresse attendue sous la forme 10.5.35.38/26 (reçu : $addr)" >&2; exit 1; }
gw=$(ip -4 route show default dev "$dev" | awk '{print $3; exit}')
dns=$(nmcli -g IP4.DNS device show "$dev" | sed 's/ | /,/g; s/ /,/g')
search=$(nmcli -g IP4.DOMAIN device show "$dev" | sed 's/ | /,/g')
[[ -n "$dns" ]] || dns="$gw"

echo "Interface      : $dev ($con), MAC $mac"
echo "Adresse fixe   : $addr"
echo "Passerelle     : $gw"
echo "DNS            : $dns ${search:+(recherche : $search)}"

nmcli con mod "$con" ipv4.method manual ipv4.addresses "$addr" ipv4.gateway "$gw" \
    ipv4.dns "$dns" ipv4.dns-search "$search" ipv4.ignore-auto-dns yes
# Appliquer sans couper la connexion (même adresse) ; sinon reconnexion rapide
nmcli device reapply "$dev" > /dev/null 2>&1 || nmcli con up "$con" > /dev/null

sleep 2
echo
echo "Fait : $(ip -4 -o addr show dev "$dev" scope global | awk '{print $4}') (méthode : $(nmcli -g ipv4.method con show "$con"))"
echo "Demandez au service informatique de réserver ${addr%/*} pour la MAC $mac (ou de l'exclure du DHCP)."
