FROM php:8.2-apache

# Instal ekstensi PostgreSQL (pdo_pgsql), GD (JPEG/PNG/WebP), dan mbstring
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev \
        libpng-dev \
        libjpeg-dev \
        libwebp-dev \
        libonig-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) pdo pdo_pgsql gd mbstring \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . /var/www/html/

# Pastikan folder upload material tersedia dan dapat ditulisi oleh Apache (www-data)
RUN mkdir -p /var/www/html/assets/uploads/material \
    && chown -R www-data:www-data /var/www/html/assets/uploads \
    && chmod -R 775 /var/www/html/assets/uploads

# Aktifkan AllowOverride All pada DocumentRoot
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Render.com menyuntikkan port dinamis lewat variabel lingkungan PORT (default 8080)
ENV PORT=8080
EXPOSE 8080

CMD ["sh", "-c", "PORT_VAL=${PORT:-8080}; sed -i \"s/Listen [0-9]*/Listen ${PORT_VAL}/g\" /etc/apache2/ports.conf && sed -i \"s/<VirtualHost \\*:[0-9]*>/<VirtualHost *:${PORT_VAL}>/g\" /etc/apache2/sites-available/000-default.conf && exec apache2-foreground"]

