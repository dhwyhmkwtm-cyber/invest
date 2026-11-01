# ロリポップ (PHP 8 + Apache + .htaccess) に近い構成
FROM php:8.3-apache
RUN apt-get update \
 && apt-get install -y --no-install-recommends libzip-dev \
 && docker-php-ext-install pdo_mysql zip \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod headers \
 && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
 && echo 'ServerName localhost' > /etc/apache2/conf-enabled/servername.conf \
 && echo 'date.timezone = Asia/Tokyo' > /usr/local/etc/php/conf.d/tz.ini
COPY entrypoint.sh /usr/local/bin/mailer-entrypoint
RUN chmod +x /usr/local/bin/mailer-entrypoint
ENTRYPOINT ["mailer-entrypoint"]
