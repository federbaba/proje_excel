# PHP FPM imajını kullan
FROM php:8.1-fpm-alpine

# Bağımlılıkları ve Uzantıları kur.
# libpq-dev'i çıkardım, loglarda mysql uzantıları kurduğunuz için.
RUN apk update && \
    apk add --no-cache caddy zlib-dev libpng-dev && \
    docker-php-ext-install gd pdo pdo_mysql mysqli

# Composer'ı global olarak kur
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Proje dosyalarını kopyala
COPY . /var/www/html

# Çalışma dizinini ayarla
WORKDIR /var/www/html

# Composer bağımlılıklarını yükle.
RUN composer install --no-dev --optimize-autoloader --ignore-platform-reqs

# KESİN ÇÖZÜM: Caddy konfigürasyon dosyasını printf ile temiz bir şekilde oluştur.
# \n yeni satır, \t tab karakteri ekler.
RUN printf ":80 {\n\troot * /var/www/html\n\tphp_fastcgi unix//var/run/php-fpm.sock\n\tfile_server\n}" > /etc/caddy/Caddyfile

# Caddy ve PHP FPM'i aynı anda başlat.
# caddy run komutu, Caddy'nin ana process (PID 1) olmasını ve konteynerin ayakta kalmasını sağlar.
# Not: Caddyfile'ı belirtmek için CMD'ye gerek yoktur, varsayılan olarak /etc/caddy/Caddyfile'ı okur.
CMD php-fpm -D && caddy run