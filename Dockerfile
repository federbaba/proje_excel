# PHP'nin resmi ve hafif Alpine versiyonunu kullan
FROM php:8.2-apache-alpine

# Composer'ı ve gerekli PHP uzantılarını yükle
RUN docker-php-ext-install mysqli pdo pdo_mysql && \
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Proje dosyalarını kopyala
COPY . /var/www/html/