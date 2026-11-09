#!/bin/bash
# Sauvegardes MariaDB, exécuté dans le conteneur "backup".
#   backup.sh loop          : sauvegarde quotidienne à BACKUP_TIME, avec rotation
#   backup.sh once [label]  : sauvegarde immédiate dans /backups/manual
set -euo pipefail

DIR=/backups
KEEP_DAILY=${BACKUP_KEEP_DAILY:-14}
KEEP_WEEKLY=${BACKUP_KEEP_WEEKLY:-8}
KEEP_MANUAL=20
AT=${BACKUP_TIME:-02:30}

# Le mot de passe passe par un fichier d'options (jamais en argument visible dans ps)
CNF=$(mktemp)
chmod 600 "$CNF"
trap 'rm -f "$CNF"' EXIT
printf '[client]\nhost=mariadb\nuser=root\npassword="%s"\n' "$MARIADB_ROOT_PASSWORD" > "$CNF"

# Sur stderr : la sortie standard de dump() ne contient que le chemin du fichier
log() { echo "$(date '+%F %T') [backup] $*" >&2; }

dump() {
    local dest=$1 label=$2
    mkdir -p "$DIR/$dest"
    local out="$DIR/$dest/${DB_NAME}_$(date +%Y%m%d-%H%M%S)_${label}.sql.gz"
    mariadb-dump --defaults-extra-file="$CNF" --single-transaction --quick \
        --routines --events --triggers --hex-blob "$DB_NAME" | gzip -6 > "$out.part"
    gzip -t "$out.part"
    mv "$out.part" "$out"
    log "OK $(du -h "$out" | cut -f1) -> $out"
    echo "$out"
}

prune() {
    local dest=$1 keep=$2
    ls -1t "$DIR/$dest"/*.sql.gz 2>/dev/null | tail -n +$((keep + 1)) | xargs -r rm -f
}

case "${1:-loop}" in
    once)
        label=$(echo "${2:-manual}" | tr -cd 'A-Za-z0-9_-')
        dump manual "${label:-manual}" >/dev/null
        prune manual "$KEEP_MANUAL"
        ;;
    loop)
        log "Planification quotidienne à $AT (conservation : ${KEEP_DAILY} j, ${KEEP_WEEKLY} sem.)"
        last=""
        while true; do
            touch /tmp/backup-heartbeat
            today=$(date +%F)
            if [[ "$(date +%H:%M)" == "$AT" && "$last" != "$today" ]]; then
                if file=$(dump daily daily); then
                    if [[ "$(date +%u)" == 7 ]]; then
                        mkdir -p "$DIR/weekly"
                        cp "$file" "$DIR/weekly/"
                    fi
                    prune daily "$KEEP_DAILY"
                    prune weekly "$KEEP_WEEKLY"
                    last=$today
                else
                    log "ÉCHEC de la sauvegarde quotidienne"
                fi
            fi
            sleep 30
        done
        ;;
    *)
        echo "Usage : backup.sh loop | once [label]" >&2
        exit 2
        ;;
esac
