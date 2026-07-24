FROM php:8.2-apache

# Installer les dépendances système pour SQLite
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/*

# Installer les extensions PHP
RUN docker-php-ext-install pdo pdo_sqlite

# Activer Apache Rewrite
RUN a2enmod rewrite

# Copier les fichiers du projet
COPY . /var/www/html/

# Permissions pour la base de données
RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

EXPOSE 80
