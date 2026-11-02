#!/bin/bash
# Crée le fichier .env de production sur le Pi avec des mots de passe aléatoires.
# Ne remplace jamais un .env existant.
set -euo pipefail
source "$(dirname "$0")/lib.sh"

target=${1:-$SHARED/.env}
[[ -e "$target" ]] && die "$target existe déjà, rien n'a été modifié."

secret() { tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 40; }

umask 077
mkdir -p "$(dirname "$target")" "$SHARED/backups"
sed -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$(secret)|" \
    -e "s|^DB_ROOT_PASSWORD=.*|DB_ROOT_PASSWORD=$(secret)|" \
    -e "s|^BACKUP_HOST_DIR=.*|BACKUP_HOST_DIR=$SHARED/backups|" \
    "$(dirname "$0")/../.env.example" > "$target"
chmod 600 "$target"
log "Fichier $target créé (droits 600). Complétez GOOGLE_* et BOOTSTRAP_ADMIN_EMAIL à la phase 3."
