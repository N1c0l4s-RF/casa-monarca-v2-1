FROM php:8.2-apache

# Instalar extensiones PHP necesarias
RUN docker-php-ext-install pdo pdo_mysql

# Habilitar módulos Apache
RUN a2enmod rewrite headers

# Instalar OpenSSL (generalmente ya viene, pero asegurar)
RUN apt-get update && apt-get install -y libssl-dev && rm -rf /var/lib/apt/lists/*

# Configurar PHP
RUN echo "session.gc_maxlifetime = 3600" >> /usr/local/etc/php/php.ini && \
    echo "session.cookie_httponly = 1" >> /usr/local/etc/php/php.ini && \
    echo "session.use_strict_mode = 1" >> /usr/local/etc/php/php.ini

# Copiar configuración Apache
COPY .docker/apache.conf /etc/apache2/sites-available/000-default.conf

# Copiar código fuente
COPY src/ /var/www/html/src/
COPY frontend/ /var/www/html/

# Permisos
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/ || exit 1
