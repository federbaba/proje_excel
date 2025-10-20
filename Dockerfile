# PHP FPM imajını kullan
FROM php:8.1-fpm-alpine

# Bağımlılıkları ve Composer'ı kur
RUN apk add --no-cache caddy && \
    docker-php-ext-install mysqli pdo pdo_mysql && \
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Proje dosyalarını FPM'in çalıştığı dizine kopyala
COPY . /var/www/html

# FPM'i web sunucusuna bağlayan varsayılan Caddy ayarını yap
RUN echo "{$CADDY_ROOT}/ { \n \
    root /var/www/html \n \
    php_fastcgi unix//var/run/php-fpm.sock \n \
    file_server \n \
}" > /etc/caddy/Caddyfile

# Çalışma dizinini ayarla
WORKDIR /var/www/html

# Caddy ve PHP FPM'i aynı anda başlat (Çok önemli!)
CMD php-fpm -D && /usr/bin/caddy run --config /etc/caddy/Caddyfile --adapter caddyfile