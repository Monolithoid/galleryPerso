FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive

# vips lit JPEG, PNG, WebP, HEIC (iPhone), AVIF, TIFF et applique l'orientation EXIF ;
# ffmpeg prépare les vidéos que les navigateurs ne savent pas lire.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        apache2 \
        ca-certificates \
        ffmpeg \
        libapache2-mod-php8.3 \
        libheif-plugin-libde265 \
        libvips-tools \
        php8.3-cli \
        php8.3-gd \
        php8.3-mbstring \
        php8.3-xml \
        php8.3-zip \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite \
    && rm -f /var/www/html/index.html \
    && printf "ServerName localhost\n" > /etc/apache2/conf-enabled/server-name.conf

COPY docker/apache/site.conf /etc/apache2/conf-enabled/siteimageperso.conf

WORKDIR /var/www/html

COPY . /var/www/html
RUN rm -rf /var/www/html/docker \
    /var/www/html/Dockerfile \
    /var/www/html/docker-compose.yml \
    /var/www/html/docker-compose.yaml \
    /var/www/html/.dockerignore

RUN chown -R www-data:www-data /var/www/html

COPY docker/docker-entrypoint.sh /usr/local/bin/siteimageperso-entrypoint
RUN chmod +x /usr/local/bin/siteimageperso-entrypoint

EXPOSE 80

ENTRYPOINT ["siteimageperso-entrypoint"]
CMD ["apache2ctl", "-D", "FOREGROUND"]
