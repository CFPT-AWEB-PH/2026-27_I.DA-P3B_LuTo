#!/bin/bash
# Active une version déjà copiée dans $BASE/releases/<version> (appelé par deploy.sh).
# Étapes : .env partagé, sauvegarde préalable, construction, migrations, démarrage, contrôle.
set -euo pipefail
source "$(dirname "$0")/lib.sh"

release=${1:?Usage : activate-release.sh <version>}
dir=$BASE/releases/$release
[[ -d "$dir" ]] || die "version introuvable : $dir"

[[ -f "$SHARED/.env" ]] || bash "$dir/scripts/init-env.sh"
ln -sfn "$SHARED/.env" "$dir/.env"
cd "$dir"
export APP_VERSION=$release

previous=$(readlink "$BASE/current" 2>/dev/null || true)

if [[ -n "$(docker compose ps -q --status running backup 2>/dev/null)" ]]; then
    log "Sauvegarde avant mise à jour"
    backup_now "pre-deploy-${release##*-}" >/dev/null
fi

log "Construction des images ($release)"
docker compose build --pull

log "Démarrage de MariaDB"
docker compose up -d mariadb
wait_healthy mariadb 240

log "Migrations"
docker compose run --rm --no-deps php php bin/migrate.php

log "Démarrage de tous les services"
docker compose up -d --remove-orphans
for service in php caddy backup; do
    wait_healthy "$service" 120
done

log "Contrôle de /api/health"
health=$(curl -fsS --max-time 10 http://127.0.0.1/api/health) || die "/api/health ne répond pas"
echo "$health"
grep -q '"status":"ok"' <<< "$health" || die "état inattendu"

[[ -n "$previous" ]] && echo "$previous" > "$dir/.previous"
ln -sfn "$dir" "$BASE/current"

# Conserver les 5 dernières versions pour pouvoir revenir en arrière
ls -1dt "$BASE"/releases/*/ | tail -n +6 | xargs -r rm -rf

log "Version $release active."
