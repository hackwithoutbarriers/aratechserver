FROM php:8.2-apache

# Installer SQLite
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/*

# Extensions PHP
RUN docker-php-ext-install pdo pdo_sqlite

# Activer mod_rewrite (utile pour d'éventuelles réécritures)
RUN a2enmod rewrite

# Copier les fichiers du projet
COPY . /var/www/html/

# Créer une configuration Apache pour bloquer l'accès direct aux fichiers sensibles
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
