FROM php:8.3.36-apache

#install required libraries and tools
RUN set -eu && apt-get update
RUN apt-get -y install unzip libpng-dev libjpeg-dev libfreetype6-dev libzip-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd mysqli zip

WORKDIR /var/www/html

#set environment variables
ENV OPENCART_FILE opencart.zip
ENV OPENCART_URL https://github.com/opencart/opencart/releases/download/3.0.5.1/opencart-3.0.5.1.zip

#install opencart
RUN curl -sSL ${OPENCART_URL} -o ${OPENCART_FILE} \
    && unzip ${OPENCART_FILE} 'upload/*' \
    && mv /var/www/html/upload/* /var/www/html/ \
    && rm -r /var/www/html/upload/ \
    && mv config-dist.php config.php \
    && mv admin/config-dist.php admin/config.php \
    && rm ${OPENCART_FILE} \
    && chown -R www-data:www-data /var/www

#copy paystack opencart plugin
COPY . /var/www/html/
