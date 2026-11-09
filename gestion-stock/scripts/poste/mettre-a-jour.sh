#!/bin/bash
# Mise à jour quotidienne du système (lancée par gestion-stock-maj.timer, journal : journalctl -u gestion-stock-maj).
# Redémarre seulement si des paquets ont changé ET qu'on est la nuit : un rattrapage au démarrage en
# pleine journée met à jour sans couper le poste ; le redémarrage attendra la nuit suivante.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
APT=(apt-get -q -y -o DPkg::Lock::Timeout=900 -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold)

before=$(dpkg-query -W -f '${Package} ${Version}\n' | md5sum)
"${APT[@]}" update
"${APT[@]}" full-upgrade
"${APT[@]}" autoremove --purge
apt-get clean
# Images Docker inutilisées (anciennes versions) : libère la carte SD
docker image prune -f > /dev/null 2>&1 || true
after=$(dpkg-query -W -f '${Package} ${Version}\n' | md5sum)

PENDING=/var/lib/gestion-stock-redemarrage-en-attente
if [[ "$before" != "$after" ]]; then
    echo "Paquets mis à jour."
    touch "$PENDING"
else
    echo "Système déjà à jour."
fi
[[ -f "$PENDING" ]] || exit 0
hour=$(date +%-H)
if (( hour >= 1 && hour < 6 )); then
    echo "Redémarrage pour appliquer les mises à jour."
    rm -f "$PENDING"
    systemctl reboot
else
    echo "Redémarrage reporté à la prochaine mise à jour de nuit (poste en service)."
fi
