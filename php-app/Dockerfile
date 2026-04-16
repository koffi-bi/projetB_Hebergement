# Utilise une image Docker officielle avec PHP et Apache
FROM php:8.2-apache

# Installe les extensions nécessaires (PDO MySQL, etc.)
RUN docker-php-ext-install pdo pdo_mysql

# Active le module Apache mod_rewrite pour les jolies URLs
RUN a2enmod rewrite

# Copie TOUS les fichiers de ton dossier php-app dans le dossier du serveur web
COPY . /var/www/html/

# Donne les bonnes permissions
RUN chown -R www-data:www-data /var/www/html

# Expose le port 80 (port web standard)
EXPOSE 80