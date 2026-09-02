FROM php:8.2-apache

# pdo_mysql = the only DB driver we need (brief forbids ORMs, we use raw PDO).
# zip/unzip are only here so Composer can install dev dependencies.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev \
    && docker-php-ext-install pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

# Composer is copied from its official image instead of curl|sh (reproducible, pinned).
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# Apache must serve public/ only. Everything above it (app/, config/, .env)
# must be unreachable over HTTP -- that is the entire point of a front controller.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf \
    && a2enmod rewrite

# Directory listing off: with autoindex enabled, /uploads/ would let anyone enumerate
# every uploaded file, which defeats the unguessable-filename rule in PRD-01.
# AllowOverride All lets public/.htaccess route everything to the front controller.
RUN printf '%s\n' \
      '<Directory ${APACHE_DOCUMENT_ROOT}>' \
      '    Options -Indexes +FollowSymLinks' \
      '    AllowOverride All' \
      '    Require all granted' \
      '</Directory>' \
      '<Directory ${APACHE_DOCUMENT_ROOT}/uploads>' \
      '    php_admin_flag engine off' \
      '    Options -Indexes -ExecCGI' \
      '    AllowOverride None' \
      '</Directory>' \
      'ServerTokens Prod' \
      'ServerSignature Off' \
      > /etc/apache2/conf-available/zz-app.conf \
    && a2enconf zz-app

# Do not leak PHP errors to the browser (ERR-01 + §8.2 critical failure).
RUN { \
      echo 'display_errors=Off'; \
      echo 'log_errors=On'; \
      echo 'error_log=/dev/stderr'; \
      echo 'upload_max_filesize=4M'; \
      echo 'post_max_size=8M'; \
    } > /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html
