#!/bin/bash
# Revient à la version précédente du code (à lancer sur le Pi).
#   rollback.sh            : version enregistrée dans current/.previous
#   rollback.sh <version>  : version précise de $BASE/releases
# La base n'est PAS modifiée : si une migration doit être annulée, restaurez la
# sauvegarde "pre-deploy" correspondante avec restore.sh.
set -euo pipefail
source "$(dirname "$0")/lib.sh"

target=${1:-}
if [[ -z "$target" ]]; then
    [[ -f "$BASE/current/.previous" ]] || die "aucune version précédente enregistrée"
    target=$(basename "$(cat "$BASE/current/.previous")")
fi
dir=$BASE/releases/$target
[[ -d "$dir" ]] || die "version introuvable : $dir"

cd "$dir"
export APP_VERSION=$target
log "Retour à $target"
docker compose up -d --remove-orphans
for service in mariadb php caddy backup; do
    wait_healthy "$service" 120
done
curl -fsS --max-time 10 http://127.0.0.1/api/health; echo
ln -sfn "$dir" "$BASE/current"
tag_current "$target"
log "Version $target active. Sauvegardes disponibles :"
ls -1t "$SHARED/backups/manual/" | head -5
