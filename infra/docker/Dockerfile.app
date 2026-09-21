# =============================================================================
# MarketGT · Capa 5 (Aplicación Laravel)
#
# Un solo contenedor con nginx interno y php-fpm. Las dependencias de PHP se
# resuelven durante la construcción, de modo que el contenedor puede vivir en
# una red sin salida a Internet.
#
# Contexto de construcción: la raíz del repositorio. El archivo de composición
# ya lo indica, no hace falta pasarlo a mano.
# =============================================================================

# ─── Etapa 1 · Dependencias de PHP ───────────────────────────────────────────
# Es la única etapa que necesita Internet. Se instala a partir del archivo de
# bloqueo, de manera que la construcción es reproducible: la misma versión
# exacta de cada paquete, hoy y el día de la presentación.
FROM composer:2 AS vendor
WORKDIR /build
COPY app/composer.json app/composer.lock ./
RUN composer install \
      --no-dev --no-scripts --no-autoloader \
      --prefer-dist --no-interaction --no-progress

# ─── Etapa 2 · Imagen final ──────────────────────────────────────────────────
# serversideup/php trae nginx, php-fpm y su supervisor, corre como usuario sin
# privilegios y escucha en el puerto 8080.
#
# NOTA SOBRE LOS RECURSOS DE INTERFAZ. Aquí había una etapa que ejecutaba la
# compilación de Vite dentro de la imagen. Se retiró: el compilador de estilos
# recorre todas las plantillas del proyecto y su consumo de memoria excede lo
# disponible en una máquina de dos núcleos y cuatro gigabytes, de modo que el
# proceso terminaba sin mensaje claro y la construcción fallaba.
#
# Los recursos compilados se versionan en public/build y se despliegan tal
# cual. Además de resolver el problema de memoria, tiene dos ventajas propias
# de un despliegue serio: el servidor de producción no necesita Node ni las
# dependencias de desarrollo, lo que reduce su superficie de ataque, y lo que
# se publica es exactamente lo que se probó, no algo recompilado en destino.
#
# Para regenerarlos tras cambiar vistas o estilos:  cd app && npm run build
FROM serversideup/php:8.5-fpm-nginx

USER root
RUN install-php-extensions pdo_mysql bcmath gd intl zip opcache
USER www-data

WORKDIR /var/www/html
COPY --chown=www-data:www-data app/ /var/www/html/
COPY --from=vendor --chown=www-data:www-data /build/vendor /var/www/html/vendor

RUN composer dump-autoload --optimize --no-dev --no-interaction

# Comprobación temprana: si los recursos compilados no viajaron en la imagen,
# la aplicación arrancaría y serviría páginas sin estilos, que es un fallo
# difícil de diagnosticar en plena demostración. Es preferible que la
# construcción se detenga aquí con un mensaje claro.
RUN test -f /var/www/html/public/build/manifest.json || { \
      echo ''; \
      echo 'ERROR: faltan los recursos compilados en app/public/build.'; \
      echo 'Generalos con:  cd app && npm ci && npm run build'; \
      echo 'y volvé a construir la imagen.'; \
      echo ''; \
      exit 1; \
    }

ENV NGINX_WEBROOT=/var/www/html/public \
    SSL_MODE=off \
    PHP_OPCACHE_ENABLE=1 \
    AUTORUN_ENABLED=true
