# PHP FPM imajını kullan (Alpine sürümünü kaldırarak en günceli kullan)
FROM php:8.1-fpm-alpine

# Bağımlılıkları, Caddy'yi kur
# Caddy artık resmi olarak Alpine'da yüklü.
# Yeni Alpine sürümünde kütüphane isimleri değişmiş olabilir, onları ekledik.
RUN apk add --no-cache caddy libpq-dev && \
    docker-php-ext-install pdo pdo_mysql mysqli

# Composer'ı global olarak kur
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Proje dosyalarını FPM'in çalıştığı dizine kopyala
COPY . /var/www/html

# Çalışma dizinini ayarla
WORKDIR /var/www/html

# Composer bağımlılıklarını yükle (Çalışma dizinine geçtikten sonra)
RUN composer install --no-dev --optimize-autoloader

# FPM'i web sunucusuna bağlayan varsayılan Caddy ayarını yap
RUN echo "{$CADDY_ROOT}/ { \n \
    root /var/www/html \n \
    php_fastcgi unix//var/run/php-fpm.sock \n \
    file_server \n \
}" > /etc/caddy/Caddyfile

# Caddy ve PHP FPM'i aynı anda başlat
CMD php-fpm -D && caddy run --config /etc/caddy/Caddyfile --adapter caddyfile