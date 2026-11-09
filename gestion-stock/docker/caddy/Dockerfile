FROM caddy:2.10-alpine
COPY docker/caddy/Caddyfile /etc/caddy/Caddyfile
COPY frontend/ /srv/frontend/

# Service worker : version du déploiement et liste des fichiers à précharger
ARG APP_VERSION=dev
RUN cd /srv/frontend \
    && list=$(find . -type f ! -name sw.js ! -name '*.md' ! -name LICENSE | sed 's|^\.||' | sort | awk '{printf "\"%s\",", $0}') \
    && sed -i "s|/\*__PRECACHE__\*/|${list}|; s|__APP_VERSION__|${APP_VERSION}|" sw.js
