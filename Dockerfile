FROM php:8.3-apache

# Install PHP extensions + curl for proxying to Ollama
RUN apt-get update && apt-get install -y --no-install-recommends \
        libsqlite3-dev \
        libcurl4-openssl-dev \
        curl \
    && docker-php-ext-install \
        pdo_sqlite \
        curl \
        fileinfo \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite for clean routing
RUN a2enmod rewrite

# Apache config: allow .htaccess overrides, set document root
RUN sed -i 's|/var/www/html|/var/www/html|g' /etc/apache2/sites-available/000-default.conf && \
    sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

# Tune PHP for streaming / large uploads.
# curl.cainfo tells PHP's libcurl to use the mounted internal CA cert —
# CURL_CA_BUNDLE env var only works for the curl CLI, not libcurl.
RUN { \
    echo 'output_buffering = Off'; \
    echo 'implicit_flush = On'; \
    echo 'upload_max_filesize = 50M'; \
    echo 'post_max_size = 55M'; \
    echo 'max_execution_time = 300'; \
    echo 'max_input_time = 300'; \
    echo 'curl.cainfo = /certs/cluster-home-root.crt'; \
    echo 'openssl.cafile = /certs/cluster-home-root.crt'; \
    echo 'zlib.output_compression = Off'; \
} > /usr/local/etc/php/conf.d/cluster-chat.ini

# Remap www-data to uid/gid 1000 to match the NFS-mounted docker-data ownership
RUN usermod  -u 1000 www-data && \
    groupmod -g 1000 www-data && \
    find /var/www /var/log/apache2 /var/run/apache2 /var/lock/apache2 \
         -user 33 -exec chown -h www-data:www-data {} \; 2>/dev/null || true

# Pre-create data volume with correct perms
RUN mkdir -p /data/uploads && chown -R www-data:www-data /data

# Copy app source
COPY --chown=www-data:www-data src/ /var/www/html/

EXPOSE 80
