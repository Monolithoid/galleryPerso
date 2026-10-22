FROM alpine:3.22

# vips lit JPEG, PNG, WebP, HEIC (iPhone), AVIF, TIFF et applique l'orientation EXIF ;
# ffmpeg prépare les vidéos que les navigateurs ne savent pas lire.
RUN apk add --no-cache \
    apache2 \
    ffmpeg \
    php83 \
    php83-apache2 \
    php83-exif \
    php83-fileinfo \
    php83-gd \
    php83-mbstring \
    php83-opcache \
    php83-session \
    php83-xml \
    php83-zip \
    su-exec \
    vips-heif \
    vips-tools

RUN ln -sf /usr/bin/php83 /usr/bin/php

RUN mkdir -p /run/apache2 /var/www/html

RUN sed -i 's|^DocumentRoot ".*"|DocumentRoot "/var/www/html"|' /etc/apache2/httpd.conf \
    && sed -i 's|^<Directory "/var/www/localhost/htdocs">|<Directory "/var/www/html">|' /etc/apache2/httpd.conf \
    && sed -i 's|#LoadModule rewrite_module modules/mod_rewrite.so|LoadModule rewrite_module modules/mod_rewrite.so|' /etc/apache2/httpd.conf \
    && printf "ServerName localhost\n" > /etc/apache2/conf.d/server-name.conf

COPY docker/apache/site.conf /etc/apache2/conf.d/siteimageperso.conf

WORKDIR /var/www/html

COPY . /var/www/html
# Fichiers de développement et exemples de configuration : rien à servir
RUN rm -rf /var/www/html/docker \
    /var/www/html/tests \
    /var/www/html/apache2 \
    /var/www/html/caddy \
    /var/www/html/Dockerfile \
    /var/www/html/docker-compose.yml \
    /var/www/html/docker-compose.yaml \
    /var/www/html/.dockerignore

RUN chown -R apache:apache /var/www/html

COPY docker/docker-entrypoint.sh /usr/local/bin/siteimageperso-entrypoint
RUN chmod +x /usr/local/bin/siteimageperso-entrypoint

EXPOSE 80

ENTRYPOINT ["siteimageperso-entrypoint"]
CMD ["httpd", "-D", "FOREGROUND"]
