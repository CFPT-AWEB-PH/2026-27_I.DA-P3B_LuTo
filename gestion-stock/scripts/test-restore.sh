#!/bin/bash
# Vérifie qu'une sauvegarde est restaurable SANS toucher à la production :
# restauration dans une base temporaire, comparaison des nombres de lignes, suppression.
#   test-restore.sh [fichier.sql.gz]   (par défaut : nouvelle sauvegarde immédiate)
set -euo pipefail
source "$(dirname "$0")/lib.sh"

cd "$BASE/current"
db=$(grep '^DB_NAME=' .env | cut -d= -f2)
test_db="${db}_restore_test"

file=${1:-}
if [[ -z "$file" ]]; then
    log "Nouvelle sauvegarde"
    file=$(backup_now restore-test)
fi
echo "  $file"

counts() {
    local schema=$1
    for table in $(db_root -N -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$schema' ORDER BY table_name"); do
        echo "$table $(db_root -N -e "SELECT COUNT(*) FROM \`$schema\`.\`$table\`")"
    done
}

trap 'db_root -e "DROP DATABASE IF EXISTS \`$test_db\`" || true' EXIT
db_root -e "DROP DATABASE IF EXISTS \`$test_db\`; CREATE DATABASE \`$test_db\`"
log "Restauration dans $test_db"
gunzip -c "$file" | db_root "$test_db"

expected=$(counts "$db")
actual=$(counts "$test_db")
echo "$actual" | sed 's/^/  /'
if [[ -z "${1:-}" && "$expected" != "$actual" ]]; then
    diff <(echo "$expected") <(echo "$actual") >&2 || true
    die "le contenu restauré diffère de la base actuelle"
fi
[[ -n "$actual" ]] || die "aucune table restaurée"
log "Test de restauration réussi ($(echo "$actual" | wc -l) tables)."
