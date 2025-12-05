# VTM Option - Dockerfile for Coolify Deployment
# PHP 8.1 with Apache for easy .htaccess compatibility

FROM php:8.1-apache

# Set working directory
WORKDIR /var/www/html

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    cron \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql mysqli mbstring exif pcntl bcmath gd

# Enable Apache mod_rewrite for .htaccess support
RUN a2enmod rewrite

# Configure Apache
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html

# Create storage directories with proper permissions
RUN mkdir -p /var/www/html/storage/sessions && \
    chown -R www-data:www-data /var/www/html/storage && \
    chmod -R 755 /var/www/html/storage

# Copy application files
COPY --chown=www-data:www-data . /var/www/html/

# Set up cron job for trading loop
# Note: Adjust path if needed based on your deployment
RUN echo "* * * * * www-data /usr/local/bin/php /var/www/html/cron/trading_loop.php >> /var/log/cron.log 2>&1" | crontab -u www-data

# Create log directory
RUN mkdir -p /var/log/php && \
    chown -R www-data:www-data /var/log/php

# PHP Configuration
RUN echo "upload_max_filesize = 10M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "post_max_size = 10M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/memory.ini && \
    echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/execution.ini && \
    echo "max_input_time = 300" >> /usr/local/etc/php/conf.d/execution.ini

# Enable OPcache for production performance
RUN echo "opcache.enable=1" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.memory_consumption=128" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.interned_strings_buffer=8" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.max_accelerated_files=10000" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.revalidate_freq=2" >> /usr/local/etc/php/conf.d/opcache.ini

# Expose port 80
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=40s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

# Start Apache and cron
CMD ["sh", "-c", "cron && apache2-foreground"]

