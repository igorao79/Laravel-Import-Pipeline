FROM php:8.3-cli

# Системные зависимости
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    libsqlite3-dev \
    && docker-php-ext-install pdo_mysql pdo_sqlite mbstring exif pcntl bcmath gd zip \
    && rm -rf /var/lib/apt/lists/*

# Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Копируем composer файлы первыми для кеширования слоёв
COPY composer.json ./
RUN composer install --no-scripts --no-autoloader --prefer-dist

# Копируем остальной код
COPY . .

# Финализируем composer
RUN composer dump-autoload --optimize

# Генерируем ключ если не задан
RUN php artisan key:generate --force 2>/dev/null || true

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
