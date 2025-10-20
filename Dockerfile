# PHP FPM imajını kullan
FROM php:8.1-fpm-alpine

# Gerekli kütüphaneleri ve Caddy'yi kur
RUN apk update && \
    apk add --no-cache caddy libpq-dev zlib-dev libpng-dev

# PHP uzantılarını kur (gd, pdo, pdo_mysql, mysqli)
RUN docker-php-ext-install gd pdo pdo_mysql mysqli

# Composer'ı global olarak kur
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Proje dosyalarını /var/www/html dizinine kopyala
COPY . /var/www/html

# Çalışma dizinini ayarla
WORKDIR /var/www/html

# Composer bağımlılıklarını yükle.
RUN composer install --no-dev --optimize-autoloader --ignore-platform-reqs

# KESİN ÇÖZÜM: Caddy konfigürasyon dosyasını GÜVENİLİR ECHO komutlarıyla oluştur.
# Bu, printf kaynaklı tüm yorumlama hatalarını ortadan kaldırır.
RUN echo "{" > /etc/caddy/Caddyfile && \
    echo "    admin off" >> /etc/caddy/Caddyfile && \
    echo "}" >> /etc/caddy/Caddyfile && \
    echo "" >> /etc/caddy/Caddyfile && \
    echo ":80 {" >> /etc/caddy/Caddyfile && \
    echo "    root * /var/www/html" >> /etc/caddy/Caddyfile && \
    echo "    php_fastcgi unix//var/run/php-fpm.sock" >> /etc/caddy/Caddyfile && \
    echo "    file_server" >> /etc/caddy/Caddyfile && \
    echo "}" >> /etc/caddy/Caddyfile

# Caddy ve PHP FPM'i aynı anda başlat
CMD php-fpm -D && caddy run