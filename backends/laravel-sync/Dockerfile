FROM php:8.4-fpm

ARG UID=1000
ARG GID=1000

# Dependências de sistema e extensões PHP exigidas pelo Laravel 12
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libicu-dev \
        libonig-dev \
        libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mbstring \
        bcmath \
        intl \
        zip \
        gd \
        exif \
        pcntl \
        opcache \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Composer 2 (imagem oficial)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configurações PHP do projeto
COPY docker/php/local.ini /usr/local/etc/php/conf.d/local.ini
COPY docker/php/xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini

# Usuário não-root com UID/GID do desenvolvedor (evita conflito de permissões)
RUN groupadd -g "${GID}" laravel \
    && useradd -u "${UID}" -g laravel -m -s /bin/bash laravel

WORKDIR /var/www/html

USER laravel

CMD ["php-fpm"]
