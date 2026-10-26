FROM php:8.2-apache

# Extensions PHP nécessaires (gd : redimensionner les logos envoyés dans Admin › Apparence)
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo pdo_mysql gd \
    && rm -rf /var/lib/apt/lists/*

# Configuration de production : PHP (erreurs masquées, limites) et Apache (durci)
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && a2enmod headers \
    && a2dismod -f autoindex status || true
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-securite.ini"
COPY docker/security.conf /etc/apache2/conf-enabled/zz-securite.conf
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# Code de l'application : appartient à root, en lecture seule pour Apache (www-data)
COPY . /var/www/html/
RUN chown -R root:root /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;

# Script d'entrée (attend MariaDB avant de démarrer Apache)
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
CMD ["apache2-foreground"]
