#!/bin/bash
# Lance les tests d'intégration de l'API dans un conteneur PHP éphémère, contre la pile en service.
# À lancer sur le Pi : bash ~/gestion_stock/current/scripts/run-tests.sh <email-administrateur>
set -euo pipefail
source "$(dirname "$0")/lib.sh"

admin=${1:?Usage : run-tests.sh <email-administrateur>}
cd "$BASE/current"

status=0
for test in backend/tests/*_test.php; do
    log "$(basename "$test")"
    docker compose run --rm --no-deps -T \
        -v "$PWD/backend/tests:/app/tests:ro" \
        -e TEST_ADMIN_EMAIL="$admin" \
        php php "/app/tests/$(basename "$test")" || status=1
done
exit $status
