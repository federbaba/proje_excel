# PHP FPM imajını kullan
# Bu imajda PHP-FPM varsayılan olarak 9000 portunda TCP soketi yerine
# /var/run/php-fpm.sock Unix soketini kullanır.
FROM php:8.1-fpm-alpine

# Bağımlılıkları (libpng, zlib), Caddy'yi ve Gerekli Uzantıları (gd, mysql) kur.
# libpq-dev PostgreSQL içindir, eğer kullanmıyorsanız çıkarabilirsiniz.
RUN apk update && \
    apk add --no-cache caddy zlib-dev libpng-dev && \
    docker-php-ext-install gd pdo pdo_mysql mysqli

# Composer'ı global olarak kur
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Proje dosyalarını FPM'in çalıştığı dizine kopyala
COPY . /var/www/html

# Çalışma dizinini ayarla
WORKDIR /var/www/html

# Composer bağımlılıklarını yükle.
RUN composer install --no-dev --optimize-autoloader --ignore-platform-reqs

# Caddy konfigürasyon dosyasını oluştur. (Önceki hatayı çözmek için tek bir satırda ve doğru \n kaçışları ile.)
# Render'ın varsayılan portu için :80 kullanıyoruz.
RUN echo ":80 { \n    root * /var/www/html \n    php_fastcgi unix//var/run/php-fpm.sock \n    file_server \n}" > /etc/caddy/Caddyfile

# Caddy ve PHP FPM'i aynı anda başlat.
# php-fpm -D ile arka planda (Daemon) çalıştırılır.
# caddy run komutu, Caddy'nin ana process olmasını ve konteynerin ayakta kalmasını sağlar.
CMD php-fpm -D && caddy run --config /etc/caddy/Caddyfile --adapter caddyfile