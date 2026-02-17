FROM php:8.2-apache

# Instalar extensiones necesarias
RUN apt-get update && apt-get install -y \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql zip gd \
    && rm -rf /var/lib/apt/lists/*

# Configurar PHP para uploads grandes
RUN echo "upload_max_filesize = 100M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 100M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/uploads.ini

# Habilitar mod_rewrite
RUN a2dismod mpm_event && a2enmod mpm_prefork && a2enmod rewrite

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copiar aplicación
COPY . /var/www/html/

# Instalar dependencias PHP
RUN cd /var/www/html && composer install --no-dev --optimize-autoloader

# Permisos
RUN mkdir -p /var/www/html/storage/zip_cache \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 /var/www/html/storage

# Permitir .htaccess
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Script de inicio (puerto dinámico para Railway)
RUN echo '#!/bin/bash\n\
rm -f /etc/apache2/mods-enabled/mpm_event.* 2>/dev/null\n\
sed -i "s/80/${PORT:-8080}/g" /etc/apache2/sites-available/000-default.conf\n\
sed -i "s/Listen 80/Listen ${PORT:-8080}/g" /etc/apache2/ports.conf\n\
apache2-foreground' > /start.sh && chmod +x /start.sh

EXPOSE 8080

CMD ["/bin/bash", "/start.sh"]
