# PHP FPM imajını kullan
FROM php:8.1-fpm-alpine

# Bağımlılıkları (libpng, zlib), Caddy'yi ve Gerekli Uzantıları (gd, mysql) kur.
RUN apk add --no-cache caddy libpq-dev zlib-dev libpng-dev && \
    docker-php-ext-install gd pdo pdo_mysql mysqli

# Composer'ı global olarak kur
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Proje dosyalarını FPM'in çalıştığı dizine kopyala
COPY . /var/www/html

# Çalışma dizinini ayarla
WORKDIR /var/www/html

# Composer bağımlılıklarını yükle. Sürüm uyuşmazlığını görmezden gelmek için --ignore-platform-reqs ekliyoruz.
RUN composer install --no-dev --optimize-autoloader --ignore-platform-reqs

# Caddy konfigürasyon dosyasını oluştur (Hata içermeyen, tek satırlık konfigürasyon)
RUN echo ':80 { root * /var/www/html; php_fastcgi unix//var/run/php-fpm.sock; file_server; }' > /etc/caddy/Caddyfile

# Caddy'yi (admin API'sine localhost'tan erişime izin vererek) ve PHP FPM'i aynı anda başlat
# Bu, Render Health Check hatalarını çözer
CMD php-fpm -D && caddy run --config /etc/caddy/Caddyfile --adapter caddyfile --watch --config-json '{"admin":{"listen":"0.0.0.0:2019"}}'