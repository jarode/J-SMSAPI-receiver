# --- Bitrix24 PHP SDK Production Dockerfile ---
FROM php:8.2-apache

# Instalacja wymaganych rozszerzeń i narzędzi
RUN apt-get update \
    && apt-get install -y git unzip libzip-dev libcurl4-openssl-dev libicu-dev zlib1g-dev \
    && docker-php-ext-install zip curl intl bcmath

# Instalacja Composera (oficjalny sposób, bezpieczny i szybki)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Skopiuj tylko pliki composera i zainstaluj zależności (lepszy cache)
COPY composer.json composer.lock /var/www/html/
WORKDIR /var/www/html
RUN composer install --no-dev --optimize-autoloader

# Skopiuj resztę aplikacji
COPY . /var/www/html

# Uprawnienia do katalogów na logi/cache
RUN mkdir -p /var/www/html/var/log /var/www/html/tmp \
    && chown -R www-data:www-data /var/www/html/var \
    && chown -R www-data:www-data /var/www/html/tmp \
    && chown -R www-data:www-data /var/www/html

# Włącz mod_rewrite jeśli używasz "ładnych" URL-i
RUN a2enmod rewrite

# Ustaw katalog publiczny jako DocumentRoot (jeśli używasz public/)
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf

# Otwórz port
EXPOSE 80

# --- END --- 