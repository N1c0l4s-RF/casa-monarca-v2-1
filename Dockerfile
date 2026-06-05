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
    echo "session.use_strict_mode = 1" >> /usr/local/etc/php/php.ini && \
    echo "upload_max_filesize = 25M" >> /usr/local/etc/php/php.ini && \
    echo "post_max_size = 26M"        >> /usr/local/etc/php/php.ini && \
    echo "memory_limit = 128M"        >> /usr/local/etc/php/php.ini

# Directorio para archivos subidos (PDFs). Se monta como volume en compose.
RUN mkdir -p /var/uploads && chown -R www-data:www-data /var/uploads && chmod 750 /var/uploads

# Copiar configuración Apache
COPY .docker/apache.conf /etc/apache2/sites-available/000-default.conf

# Copiar código fuente
COPY src/auth/ /var/www/html/auth/
COPY src/api/ /var/www/html/api/
COPY src/config/ /var/www/html/config/
COPY src/modules/ /var/www/html/modules/
COPY frontend/ /var/www/html/

# Permisos
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/ || exit 1
