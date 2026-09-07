#!/bin/bash
set -e

echo "⏳ En attente de MariaDB (${DB_HOST:-db}:3306)..."
until php -r "
    \$pdo = new PDO(
        'mysql:host=' . getenv('DB_HOST') . ';port=3306',
        getenv('DB_USER'),
        getenv('DB_PASS')
    );
    echo 'OK';
" 2>/dev/null | grep -q OK; do
  sleep 2
  echo "   … MariaDB pas encore prêt, on réessaie"
done
echo "✅ MariaDB prêt !"

exec "$@"
