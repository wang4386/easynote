FROM php:8.3-fpm-alpine

# Install nginx and required PHP extensions
RUN apk add --no-cache nginx \
    && docker-php-ext-install opcache \
    && mkdir -p /run/nginx

# Copy nginx config
COPY docker/nginx.conf /etc/nginx/http.d/default.conf

# Copy application files
COPY index.php config.php robots.txt .htaccess /var/www/html/
COPY assets/ /var/www/html/assets/

# Create notes directory
RUN mkdir -p /var/www/html/_notes

# PHP production optimizations
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.memory_consumption=64'; \
    echo 'opcache.interned_strings_buffer=8'; \
    echo 'opcache.max_accelerated_files=4000'; \
    echo 'opcache.validate_timestamps=0'; \
    echo 'expose_php=Off'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

WORKDIR /var/www/html

EXPOSE 80

# Fix permissions on startup (handles volume mounts), then start services
CMD ["sh", "-c", "chown -R www-data:www-data /var/www/html/_notes && php-fpm -D && nginx -g 'daemon off;'"]
