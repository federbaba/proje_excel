# PHP FPM imajını kullan
FROM php:8.1-fpm-alpine

# Gerekli kütüphaneleri ve Caddy'yi kur
RUN apk add --no-cache caddy libpq-dev zlib-dev libpng-dev

# PHP uzantılarını kur (gd, pdo, pdo_mysql, mysqli)
RUN docker-php-ext-install gd pdo pdo_mysql mysqli

# Composer'ı global olarak kur
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Proje dosyalarını /var/www/html dizinine kopyala
COPY . /var/www/html

# Çalışma dizinini ayarla
WORKDIR /var/www/html

# Composer bağımlılıklarını yükle. (Platform gereksinimlerini görmezden gelerek)
RUN composer install --no-dev --optimize-autoloader --ignore-platform-reqs

# Caddy konfigürasyon dosyasını oluştur (Tek satırlık, hatasız, $PORT kullanılıyor, phpMyAdmin'i sunmak için /phpmyadmin/ yolu dahil)
# NOT: phpMyAdmin klasörünün projenizin kök dizininde (GitHub'da) olması GEREKİR.
RUN echo ':\$PORT { root * /var/www/html; php_fastcgi unix//var/run/php-fpm.sock; file_server; handle /phpmyadmin* { root * /var/www/html; php_fastcgi unix//var/run/php-fpm.sock; file_server; } }' > /etc/caddy/Caddyfile

# Caddy ve PHP FPM'i aynı anda başlat
# Bu komut, önceki CLI hatalarını çözmek için en basit formdadır.
CMD php-fpm -D && caddy run --config /etc/caddy/Caddyfile --adapter caddyfile