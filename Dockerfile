FROM php:8.2-apache

# Installer les dépendances système requises pour SQLite
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/*

# Installer les extensions PHP pdo et pdo_sqlite
RUN docker-php-ext-install pdo pdo_sqlite

# Activer le module Apache Rewrite
RUN a2enmod rewrite

# Copier les fichiers du projet dans le dossier web d'Apache
COPY . /var/www/html/

# Donner les droits d'écriture pour le dossier data (SQLite)
RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

EXPOSE 80
