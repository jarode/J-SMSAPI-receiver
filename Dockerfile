# --- Bitrix24 PHP SDK Production Dockerfile ---
FROM php:8.2-apache

# Instalacja zależności systemowych i PHP
RUN apt-get update \
    && apt-get install -y git unzip libzip-dev libcurl4-openssl-dev \
    && docker-php-ext-install zip curl

# Instalacja Composera
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Skopiuj pliki aplikacji
COPY . /var/www/html

# Ustaw katalog publiczny jako DocumentRoot
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf

# Uprawnienia do logów i config
RUN mkdir -p /var/www/html/var/log && chown -R www-data:www-data /var/www/html/var

# Instalacja zależności PHP
WORKDIR /var/www/html
RUN composer install --no-dev --optimize-autoloader

# Otwórz port
EXPOSE 80

# --- END --- 