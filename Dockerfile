FROM php:8.2-apache

# Install PDO MySQL extension and system utilities
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    git \
    unzip \
    && docker-php-ext-install pdo pdo_mysql opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Configure Apache DocumentRoot to point to /public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Configure Apache Directory permissions for mod_rewrite
RUN echo '<Directory /var/www/html/public/>\n\
    Options -Indexes +FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/finsync.conf \
    && a2enconf finsync

WORKDIR /var/www/html

# Copy project files
COPY . /var/www/html/

# Set permissions for web server
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && mkdir -p /var/www/html/storage && chown -R www-data:www-data /var/www/html/storage

# Production PHP settings
RUN { \
    echo 'opcache.memory_consumption=128'; \
    echo 'opcache.interned_strings_buffer=8'; \
    echo 'opcache.max_accelerated_files=4000'; \
    echo 'opcache.revalidate_freq=2'; \
    echo 'upload_max_filesize=20M'; \
    echo 'post_max_size=25M'; \
    echo 'memory_limit=256M'; \
} > /usr/local/etc/php/conf.d/finsync-custom.ini

EXPOSE 80

CMD ["apache2-foreground"]

