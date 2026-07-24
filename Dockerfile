FROM php:8.2-apache

# Installer les extensions nécessaires (SQLite, etc.)
RUN docker-php-ext-install pdo pdo_sqlite

# Activer le module Apache Rewrite si besoin
RUN a2enmod rewrite

# Copier les fichiers du projet dans le dossier web d'Apache
COPY . /var/www/html/

# Donner les droits d'écriture pour la base de données SQLite
RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

# Exposer le port 80 pour le web
EXPOSE 80
