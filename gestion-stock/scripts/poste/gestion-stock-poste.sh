#!/bin/bash
# Bascule l'écran du Pi entre le poste verrouillé (compte « poste ») et le bureau d'administration.
#   sudo gestion-stock-poste maintenance   # bureau normal de l'administrateur (redémarre l'affichage)
#   sudo gestion-stock-poste verrouiller   # retour au poste verrouillé
#   gestion-stock-poste etat
set -euo pipefail
CONF=/etc/lightdm/lightdm.conf
ADMIN=$(cat /etc/gestion-stock/admin 2>/dev/null || echo cuto)

set_autologin() {
    for kv in "autologin-user=$1" "autologin-session=$2"; do
        key=${kv%%=*}
        if grep -q "^#\?$key=" "$CONF"; then sed -i "s|^#\?$key=.*|$kv|" "$CONF"; else sed -i "/^\[Seat:\*\]/a $kv" "$CONF"; fi
    done
}
need_root() { [[ $EUID -eq 0 ]] || { echo "Avec sudo : sudo gestion-stock-poste $1" >&2; exit 1; }; }

case ${1:-etat} in
    verrouiller)
        need_root "$1"
        set_autologin poste gestion-stock-poste
        systemctl restart lightdm
        echo "Poste verrouillé : l'écran n'affiche plus que l'application."
        ;;
    maintenance)
        need_root "$1"
        set_autologin "$ADMIN" rpd-labwc
        systemctl restart lightdm
        echo "Bureau d'administration ($ADMIN) à l'écran. Pour reverrouiller : sudo gestion-stock-poste verrouiller"
        ;;
    etat)
        if grep -q "^autologin-user=poste" "$CONF"; then echo "Poste verrouillé."; else echo "Mode maintenance (bureau de $ADMIN)."; fi
        ;;
    *)
        echo "Usage : sudo gestion-stock-poste maintenance | verrouiller | etat" >&2
        exit 2
        ;;
esac
