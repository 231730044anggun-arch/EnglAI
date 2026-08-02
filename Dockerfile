FROM php:8.2-apache

# Install PDO MySQL extension
RUN docker-php-ext-install pdo pdo_mysql

# Enable Apache rewrite module
RUN a2enmod rewrite

# Set working directory
COPY . /var/www/html/

# Ensure write permissions for storage directory
RUN mkdir -p /var/www/html/storage/private/speaking /var/www/html/storage/logs \
    && chown -R www-data:www-data /var/www/html/storage

# Expose port 80
EXPOSE 80
