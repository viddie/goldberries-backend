FROM php:8.4-apache

# Install required libraries
RUN apt-get update && apt-get install -y \
  cron \
  libcurl4-openssl-dev \
  libpq-dev \
  libzip-dev \
  libpng-dev \
  libjpeg62-turbo-dev \
  libfreetype6-dev \
  libonig-dev \
  gettext \
  unzip \
  git \
  && docker-php-ext-configure gd \
  --with-freetype \
  --with-jpeg \
  && docker-php-ext-install -j$(nproc) \
  exif \
  gd \
  gettext \
  mbstring \
  pgsql \
  pdo_pgsql \
  zip \
  && a2enmod rewrite \
  && rm -rf /var/lib/apt/lists/*

# PHP configuration
COPY php.ini /usr/local/etc/php/conf.d/custom.ini

# Apache configuration
COPY apache.conf /etc/apache2/sites-available/000-default.conf

# Optional but handy
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Setup cron
COPY cronfile /etc/cron.d/app-cron
RUN chmod 0644 /etc/cron.d/app-cron

WORKDIR /var/www/html