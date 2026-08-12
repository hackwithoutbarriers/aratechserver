FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libpq-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo pdo_pgsql
RUN a2enmod rewrite headers

COPY . /var/www/html/

RUN printf '%s\n' \
    '<FilesMatch "^(config|db|RouterosAPI)\\.php$|^\\.env($|\\.)|^composer\\.(json|lock)$">' \
    '    Require all denied' \
    '</FilesMatch>' \
    '' \
    '<DirectoryMatch "^/var/www/html/(\\.git|tests|docs|database|mikrotik)(/|$)">' \
    '    Require all denied' \
    '</DirectoryMatch>' \
    '' \
    'Header always unset Access-Control-Allow-Origin' \
    'Header always unset Access-Control-Allow-Credentials' \
    'Header always unset Access-Control-Allow-Methods' \
    'Header always unset Access-Control-Allow-Headers' \
    > /etc/apache2/conf-available/security-phase1.conf \
    && a2enconf security-phase1

RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \\; \
    && find /var/www/html -type f -exec chmod 644 {} \;

EXPOSE 80
