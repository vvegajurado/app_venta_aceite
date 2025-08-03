# my_app_docker/apache/Dockerfile
FROM php:8.2-apache

# Actualizar el sistema y asegurarse de que las herramientas básicas estén disponibles
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpng-dev \
    libjpeg-dev \
    libwebp-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    # Agrega aquí cualquier otra librería del sistema que tus extensiones PHP puedan necesitar
    && rm -rf /var/lib/apt/lists/*

# Instalar extensiones PHP necesarias
RUN docker-php-ext-configure gd --with-jpeg --with-webp
RUN docker-php-ext-install -j$(nproc) mysqli pdo_mysql gd zip bcmath

# Habilitar el módulo rewrite de Apache (común para frameworks o URLs amigables)
RUN a2enmod rewrite

# Establecer el directorio de trabajo del documento raíz de Apache
WORKDIR /var/www/html

# El código de la aplicación se montará como volumen en docker-compose