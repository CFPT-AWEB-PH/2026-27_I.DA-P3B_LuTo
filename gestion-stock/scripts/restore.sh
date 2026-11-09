#!/bin/bash
# Restaure une sauvegarde dans la base de production (à lancer sur le Pi).
# Une sauvegarde de l'état actuel est faite juste avant. Confirmation obligatoire.
#   restore.sh <fichier.sql.gz>
set -euo pipefail
source "$(dirname "$0")/lib.sh"

file=${1:?Usage : restore.sh <fichier.sql.gz>}
[[ -f "$file" ]] || die "fichier introuvable : $file"
gzip -t "$file" || die "archive corrompue : $file"

cd "$BASE/current"
db=$(grep '^DB_NAME=' .env | cut -d= -f2)
user=$(grep '^DB_USER=' .env | cut -d= -f2)

echo "La base « $db » va être REMPLACÉE par le contenu de :"
echo "  $file"
read -r -p "Tapez RESTAURER pour confirmer : " answer
[[ "$answer" == RESTAURER ]] || die "annulé"

log "Sauvegarde de sécurité de l'état actuel"
safety=$(backup_now pre-restore)
echo "  $safety"

log "Arrêt de l'application pendant la restauration"
docker compose stop caddy php

log "Restauration"
db_root -e "DROP DATABASE \`$db\`; CREATE DATABASE \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON \`$db\`.* TO '$user'@'%';"
gunzip -c "$file" | db_root "$db"

docker compose up -d php caddy
wait_healthy php
wait_healthy caddy
curl -fsS --max-time 10 http://127.0.0.1/api/health; echo
log "Restauration terminée. En cas de problème : restore.sh $safety"
