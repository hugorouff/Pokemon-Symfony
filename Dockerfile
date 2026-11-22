FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

ENV APP_ENV=prod APP_DEBUG=0
WORKDIR /var/www/html

RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|' /etc/apache2/sites-available/000-default.conf \
    && printf '%s\n' '<Directory /var/www/html/public>' '    Options -Indexes' '    FallbackResource /index.php' '</Directory>' > /etc/apache2/conf-available/pokemon.conf \
    && a2enconf pokemon \
    && printf '%s\n' 'upload_max_filesize=16M' 'post_max_size=20M' > "$PHP_INI_DIR/conf.d/pokemon.ini"

COPY . .

RUN mkdir -p var/cache var/log var/sessions \
    && APP_SECRET=docker-build-only php bin/console cache:clear --env=prod --no-warmup \
    && chown -R www-data:www-data var roms
