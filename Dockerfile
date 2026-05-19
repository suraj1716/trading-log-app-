FROM php:8.2-cli

# System deps
RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
    libpq-dev \
    nodejs \
    npm

# PostgreSQL extensions
RUN docker-php-ext-install pdo pdo_pgsql pgsql

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy app
COPY . .

# Install PHP deps
RUN composer install --no-dev --optimize-autoloader

# Install frontend deps
RUN npm install

# Build Vite
RUN npm run build

# Laravel optimizations
RUN php artisan config:clear
RUN php artisan route:clear
RUN php artisan view:clear

EXPOSE 10000

CMD php artisan serve --host=0.0.0.0 --port=$PORT
