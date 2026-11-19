# Railway: Apache + PHP 8.3 sirviendo app/public. En el servidor propio se usa IIS
# y este archivo no interviene. La configuración sale de las variables FIN_*
# (ver README, «Railway»); documentos y sesiones van al volumen montado en /data.
FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql \
 && a2dismod -f mpm_event mpm_worker >/dev/null 2>&1 ; a2enmod mpm_prefork remoteip headers \
 && { \
      echo 'upload_max_filesize = 12M'; \
      echo 'post_max_size = 64M'; \
      echo 'max_file_uploads = 20'; \
      echo 'memory_limit = 256M'; \
      echo 'expose_php = Off'; \
      echo 'date.timezone = America/Argentina/Buenos_Aires'; \
    } > /usr/local/etc/php/conf.d/financiera.ini \
 && { \
      echo '<VirtualHost *:${PORT}>'; \
      echo '  DocumentRoot /var/www/financiera/app/public'; \
      echo '  DirectoryIndex index.php'; \
      echo '  <Directory /var/www/financiera/app/public>'; \
      echo '    Options -Indexes'; \
      echo '    AllowOverride None'; \
      echo '    Require all granted'; \
      echo '  </Directory>'; \
      echo '  <FilesMatch "^\.|\.(ini|config|md)$">'; \
      echo '    Require all denied'; \
      echo '  </FilesMatch>'; \
      echo '  RemoteIPHeader X-Forwarded-For'; \
      echo '  SetEnvIf X-Forwarded-Proto https HTTPS=on'; \
      echo '  ServerSignature Off'; \
      echo '</VirtualHost>'; \
    } > /etc/apache2/sites-available/000-default.conf \
 && echo 'Listen ${PORT}' > /etc/apache2/ports.conf \
 && echo 'ServerName localhost' >> /etc/apache2/apache2.conf \
 && echo 'ServerTokens Prod' >> /etc/apache2/apache2.conf \
 && echo 'PassEnv PORT FIN_DB_HOST FIN_DB_PORT FIN_DB_NAME FIN_DB_USER FIN_DB_PASS FIN_DOCS_DIR FIN_DOCS_MAX_MB FIN_BCRA_CACHE_HORAS FIN_BCRA_CAINFO FIN_SESION_MINUTOS FIN_SESION_DIR FIN_DEBUG' >> /etc/apache2/apache2.conf

COPY app /var/www/financiera/app

ENV PORT=8080
# Al arrancar: las carpetas del volumen (llega vacío y de root) con permiso para Apache.
CMD ["sh", "-c", "rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.* ; mkdir -p \"${FIN_DOCS_DIR:-/data/documentos}\" \"${FIN_SESION_DIR:-/data/sesiones}\" && chown -R www-data:www-data \"${FIN_DOCS_DIR:-/data/documentos}\" \"${FIN_SESION_DIR:-/data/sesiones}\" && exec apache2-foreground"]
