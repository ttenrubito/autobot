FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    git curl libpng-dev libonig-dev libxml2-dev zip unzip \
 && apt-get clean \
 && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo_mysql mysqli mbstring exif pcntl bcmath gd
RUN a2enmod rewrite headers

WORKDIR /var/www/html
COPY . .

RUN mkdir -p /var/www/html/storage/logs \
 && chown -R www-data:www-data /var/www/html \
 && chmod -R 755 /var/www/html

ENV PORT=8080

# Valid ports.conf
RUN sh -c 'printf "Listen %s\n\n<IfModule ssl_module>\n    Listen 443\n</IfModule>\n\n<IfModule mod_gnutls.c>\n    Listen 443\n</IfModule>\n" "$PORT" > /etc/apache2/ports.conf'

# Use the hardened vhost config that includes Alias for /api and /assets
COPY docker/apache-config.conf /etc/apache2/sites-available/000-default.conf

# Ensure common modules are enabled
RUN a2enmod rewrite headers alias mime

EXPOSE 8080
CMD ["apache2-foreground"]
