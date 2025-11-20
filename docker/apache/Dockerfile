FROM php:8.3-apache AS server

LABEL org.opencontainers.image.description="Creates a environment to serve PHP files for the Remote Client API."

WORKDIR /var/www/html

USER root

RUN apt-get update -y -qq && \
  a2enmod rewrite && \
  a2enmod headers

RUN rm -f /etc/apache2/sites-available/000-default.conf

RUN <<'EOF' cat >> /etc/apache2/sites-available/000-default.conf
<VirtualHost *:80>
    DocumentRoot /var/www/html
    ErrorDocument 404 /index.php

    ErrorLog ${APACHE_LOG_DIR}/error.log
    CustomLog ${APACHE_LOG_DIR}/access.log combined

    <IfModule mod_headers.c>
        Header set Access-Control-Allow-Origin "*"
        Header set Access-Control-Allow-Headers "X-Application"
    </IfModule>
    <Directory /var/www/html>
        Options +FollowSymLinks -MultiViews
        AllowOverride None
        Require all granted

        <IfModule mod_rewrite.c>
            RewriteEngine On
            RewriteRule ^(.*)\.bmp$ $1\.png [NC,QSA]
        </IfModule>

        <IfModule mod_speling.c>
            CheckSpelling On
            CheckCaseOnly On
        </IfModule>
    </Directory>

    <IfModule mod_gzip.c>
        mod_gzip_on Yes
        mod_gzip_dechunk Yes
        mod_gzip_item_include file \.(act|spr|pal|txt|xml|lua|lub|gnd|gat|rsw|rsm)$
    </IfModule>

    <IfModule mod_mime.c>
        AddType application/ro-action          .act
        AddType application/ro-sprite          .spr
        AddType application/ro-palette         .pal
        AddType application/ro-ground          .gnd
        AddType application/ro-altitude        .gat
        AddType application/ro-world           .rsw
        AddType application/ro-model           .rsm
    </IfModule>

    <IfModule mod_deflate.c>
        AddOutputFilterByType DEFLATE \
            application/ro-action \
            application/ro-sprite \
            application/ro-palette \
            application/ro-ground \
            application/ro-altitude \
            application/ro-world \
            application/ro-model \
            text/plain \
            application/xml
    </IfModule>

</VirtualHost>
EOF

EXPOSE 80

USER www-data
