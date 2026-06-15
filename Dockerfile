FROM php:8.2-apache

# Install PDO MySQL extension
RUN docker-php-ext-install pdo_mysql

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Copy project files to default Apache directory
COPY . /var/www/html/

# Expose port 80
EXPOSE 80
