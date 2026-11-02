#!/bin/bash
# Déploie le dernier commit sur le Raspberry Pi (à lancer depuis le poste de développement).
# Seul le contenu committé est envoyé : l'arbre de travail doit être propre.
set -euo pipefail

HOST=${DEPLOY_HOST:-cuto@10.5.35.39}
BASE=${DEPLOY_BASE:-/home/cuto/gestion_stock}

cd "$(git rev-parse --show-toplevel)"
if [[ -n "$(git status --porcelain)" ]]; then
    echo "ERREUR : modifications non committées. Committez avant de déployer." >&2
    exit 1
fi

release="$(date -u +%Y%m%d-%H%M%S)-$(git rev-parse --short HEAD)"
echo "==> Envoi de la version $release vers $HOST"
git archive --format=tar HEAD | ssh "$HOST" "mkdir -p '$BASE/releases/$release' && tar -xf - -C '$BASE/releases/$release'"
ssh "$HOST" "bash '$BASE/releases/$release/scripts/activate-release.sh' '$release'"
