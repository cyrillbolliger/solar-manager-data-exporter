FROM php:8.4-apache

# install & enable xdebug
RUN pecl install xdebug && docker-php-ext-enable xdebug

# change uid and gid of apache to docker user uid/gid
RUN usermod -u 1000 www-data && groupmod -g 1000 www-data

# reconfigure the virtual hosts to use the ones defined in ./apache-vhost.conf
COPY ./dev/apache-vhost.conf /etc/apache2/sites-available/custom-vhost.conf
RUN a2dissite 000-default.conf && a2ensite custom-vhost.conf

# activate mod rewrite
RUN a2enmod rewrite

# set the working dir
WORKDIR /var/www/html

# run as www-data
USER www-data:www-data