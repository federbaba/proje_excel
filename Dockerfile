# PHP FPM imajını kullan
FROM php:8.1-fpm-alpine

# Gerekli kütüphaneleri ve UZANTILARI kur
# GD, MySQL/PDO uzantılarını ve libpq (PostgreSQL için) kuruyoruz.
RUN apk add --no-cache caddy libpq-dev && \
    docker-php-ext-install gd pdo pdo_mysql mysqli

# Composer'ı global olarak kur
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Proje dosyalarını FPM'in çalıştığı dizine kopyala
COPY . /var/www/html

# Çalışma dizinini ayarla
WORKDIR /var/www/html

# Composer bağımlılıklarını yükle. Sürüm uyuşmazlığını geçici olarak görmezden gelmek için --ignore-platform-reqs ekliyoruz.
RUN composer install --no-dev --optimize-autoloader --ignore-platform-reqs

# FPM'i web sunucusuna bağlayan varsayılan Caddy ayarını yap
RUN echo "{$CADDY_ROOT}/ { \n \
    root /var/www/html \n \
    php_fastcgi unix//var/run/php-fpm.sock \n \
    file_server \n \
}" > /etc/caddy/Caddyfile

# Caddy ve PHP FPM'i aynı anda başlat
CMD php-fpm -D && caddy run --config /etc/caddy/Caddyfile --adapter caddyfile