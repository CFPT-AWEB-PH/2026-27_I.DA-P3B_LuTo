# Fonctions communes aux scripts exécutés sur le Raspberry Pi.
# Arborescence : $BASE/releases/<version>, $BASE/current -> version active, $BASE/shared/{.env,backups}

BASE=${GS_BASE:-$HOME/gestion_stock}
SHARED=$BASE/shared

log() { echo "==> $*"; }
die() { echo "ERREUR : $*" >&2; exit 1; }

# Attend qu'un service Compose soit "healthy" (délai en secondes en 2e argument)
wait_healthy() {
    local service=$1 timeout=${2:-180} id status
    for ((i = 0; i < timeout; i += 3)); do
        id=$(docker compose ps -q "$service" 2>/dev/null || true)
        if [[ -n "$id" ]]; then
            status=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$id")
            [[ "$status" == healthy ]] && return 0
            [[ "$status" == unhealthy ]] && break
        fi
        sleep 3
    done
    docker compose logs --tail 40 "$service" >&2 || true
    die "le service $service n'est pas opérationnel (état : ${status:-absent})"
}

# Désigne les images d'une version comme "current" (utilisées par les commandes docker compose manuelles)
tag_current() {
    local release=$1
    for image in gestion_stock-php gestion_stock-caddy; do
        docker tag "$image:$release" "$image:current"
    done
}

# Exécute une commande SQL en root dans le conteneur MariaDB, sans exposer le mot de passe dans ps
db_root() {
    docker compose exec -T mariadb sh -c 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb -uroot "$@"' sh "$@"
}

# Sauvegarde immédiate ; affiche le chemin du fichier côté hôte
backup_now() {
    local label=$1
    docker compose exec -T backup /bin/bash /usr/local/bin/backup.sh once "$label" >&2
    ls -1t "$SHARED/backups/manual/"*.sql.gz | head -1
}
