FROM openswoole/swoole:latest-alpine

WORKDIR /var/www/html/

RUN apk add --no-cache ${PHPIZE_DEPS}
RUN pecl install pcov && docker-php-ext-enable pcov
COPY --from=composer /usr/bin/composer /usr/bin/composer

# Copy the source files
COPY . .

# Install Composer dependencies
RUN composer install

CMD [ "php", "-r", "while(true){sleep(1000);}" ]
