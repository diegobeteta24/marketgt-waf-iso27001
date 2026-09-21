# =============================================================================
# MarketGT - Capa 5 (Aplicacion Laravel)
# Un solo contenedor con nginx interno + php-fpm, gestionado por s6-overlay.
# El codigo se COPIA y las dependencias se instalan EN BUILD, para que el
# contenedor pueda vivir en una red docker `internal: true` (sin Internet).
#
# Contexto de build: la raiz del repo  ->  docker compose ya lo hace por ti.
# =============================================================================

# Etapa 1: dependencias de PHP (composer con Internet, solo aqui)
FROM composer:2 AS vendor
WORKDIR /build
COPY app/composer.json app/composer.lock ./
RUN composer install \
      --no-dev --no-scripts --no-autoloader \
      --prefer-dist --no-interaction --no-progress

# Etapa 2: assets de frontend (Vite)
FROM node:24-alpine AS assets
WORKDIR /build
COPY app/package.json app/package-lock.json ./
RUN npm ci
COPY app/ ./
RUN npm run build

# Etapa 3: imagen final
# serversideup/php:8.5-fpm-nginx trae nginx + php-fpm + s6, corre como
# usuario no privilegiado y escucha en 8080 (8443 si SSL_MODE != off).
FROM serversideup/php:8.5-fpm-nginx

USER root
RUN install-php-extensions pdo_mysql bcmath gd intl zip opcache
USER www-data

WORKDIR /var/www/html
COPY --chown=www-data:www-data app/ /var/www/html/
COPY --from=vendor --chown=www-data:www-data /build/vendor /var/www/html/vendor
COPY --from=assets --chown=www-data:www-data /build/public/build /var/www/html/public/build

RUN composer dump-autoload --optimize --no-dev --no-interaction

# nginx interno sirve /var/www/html/public
ENV NGINX_WEBROOT=/var/www/html/public \
    SSL_MODE=off \
    PHP_OPCACHE_ENABLE=1 \
    AUTORUN_ENABLED=true
