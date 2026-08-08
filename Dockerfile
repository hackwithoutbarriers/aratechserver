FROM php:8.2-apache

# Installer SQLite + PostgreSQL
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    libpq-dev \
    && rm -rf /var/lib/apt/lists/*

# Extensions PHP
RUN docker-php-ext-install pdo pdo_sqlite pdo_pgsql

# Activer mod_rewrite
RUN a2enmod rewrite

# Copier les fichiers
COPY . /var/www/html/

# Blocage des fichiers sensibles
RUN echo '<FilesMatch "^(config|db|RouterosAPI)\.php$">' \
    > /etc/apache2/conf-available/block-sensitive.conf \
    && echo '    Require all denied' \
    >> /etc/apache2/conf-available/block-sensitive.conf \
    && echo '</FilesMatch>' \
    >> /etc/apache2/conf-available/block-sensitive.conf \
    && a2enconf block-sensitive

# Permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 80
