FROM php:8.2-apache

# Install MariaDB and PDO MySQL extension
RUN apt-get update && apt-get install -y mariadb-server mariadb-client && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install pdo pdo_mysql

# Enable Apache rewrite module
RUN a2enmod rewrite

# Set working directory
COPY . /var/www/html/

# Ensure write/execute permissions for entrypoint and storage directory
RUN chmod +x /var/www/html/entrypoint.sh \
    && cp /var/www/html/entrypoint.sh /usr/local/bin/entrypoint.sh \
    && mkdir -p /var/www/html/storage/private/speaking /var/www/html/storage/logs \
    && chown -R www-data:www-data /var/www/html/storage

# Expose port 80
EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]
