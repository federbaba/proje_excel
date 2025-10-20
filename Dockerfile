# PHP'nin sağlam 8.1 sürümünü Apache web sunucusuyla kullan.
FROM php:8.1-apache-alpine

# Gerekli PHP uzantılarını (MySQL/PDO) kur ve Composer'ı indir/kur.
RUN docker-php-ext-install mysqli pdo pdo_mysql && \
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Proje dosyalarını Docker içindeki web sunucusu dizinine kopyala.
COPY . /var/www/html/