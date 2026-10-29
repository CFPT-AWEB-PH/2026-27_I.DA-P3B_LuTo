#!/usr/bin/env bash
# Installation en une commande (PC Linux / macOS / Windows Git Bash, Raspberry Pi) :
#   ./install.sh
# 1. crée .env à partir de .env.example avec des mots de passe aléatoires (si absent) ;
# 2. construit et démarre l'application avec Docker ;
# 3. attend que le site réponde et affiche son adresse.
set -euo pipefail
cd "$(dirname "$0")"

command -v docker >/dev/null || { echo "❌ Docker n'est pas installé : https://docs.docker.com/get-docker/"; exit 1; }
docker info >/dev/null 2>&1 || { echo "❌ Docker est installé mais pas démarré (lancer Docker Desktop)."; exit 1; }

mdp() { openssl rand -hex 16 2>/dev/null || LC_ALL=C tr -dc 'a-zA-Z0-9' </dev/urandom | head -c 32; }

if [ ! -f .env ]; then
    sed -e "s/^DB_ROOT_PASSWORD=.*/DB_ROOT_PASSWORD=$(mdp)/" \
        -e "s/^DB_PASS=.*/DB_PASS=$(mdp)/" .env.example > .env
    echo "✅ .env créé avec des mots de passe aléatoires"
else
    echo "ℹ️  .env existe déjà : il est conservé"
fi

docker compose up -d --build

PORT=$(grep -E '^APP_PORT=' .env | cut -d= -f2 || true)
URL="http://localhost:${PORT:-8080}"
echo "⏳ Démarrage de l'application…"
for _ in $(seq 1 60); do
    if curl -fs -o /dev/null "$URL/login.php"; then
        echo "✅ Application prête : $URL"
        echo "   Compte par défaut : admin / admin123 (changement imposé à la 1re connexion)"
        exit 0
    fi
    sleep 2
done
echo "⚠️  Le site ne répond pas encore. Voir les journaux : docker compose logs -f web"
exit 1
