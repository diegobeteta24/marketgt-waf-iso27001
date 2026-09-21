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

# ─── Etapa 1 · Dependencias de PHP y mapa de clases ──────────────────────────
# Es la única etapa que necesita Internet, y la única donde se ejecuta Composer.
#
# El mapa de clases optimizado se genera AQUÍ y no en la imagen final por dos
# razones. La imagen final corre como usuario sin privilegios y Composer
# necesita escribir tanto en el directorio de dependencias como en su propia
# caché, lo que hacía fallar la construcción. Y además, mantener Composer fuera
# de la imagen de producción reduce su superficie: una herramienta capaz de
# descargar y ejecutar código no tiene nada que hacer en un servidor expuesto.
#
# La instalación parte del archivo de bloqueo, de manera que la construcción es
# reproducible: la misma versión exacta de cada paquete, hoy y el sábado.
FROM composer:2 AS vendor
WORKDIR /build

COPY app/composer.json app/composer.lock ./
RUN composer install \
      --no-dev --no-scripts --no-autoloader \
      --prefer-dist --no-interaction --no-progress

# El código completo hace falta para que el mapa de clases recorra también los
# modelos, servicios y comandos del proyecto, no solo las dependencias.
COPY app/ ./

# --no-scripts es imprescindible aquí. Sin esa opción, esta orden dispara el
# script post-autoload-dump que Laravel declara, el cual ejecuta a su vez
# `artisan package:discover`. Arrancar el framework exige extensiones de PHP y
# variables de entorno que esta imagen mínima no tiene, de modo que la
# construcción fallaba con un código de salida que no explicaba nada.
#
# No se pierde nada: el descubrimiento de paquetes genera un archivo de caché
# en bootstrap/cache que el framework regenera solo en la primera petición si
# no lo encuentra. Lo que sí queremos de esta orden —el mapa de clases
# optimizado— se genera igual.
RUN composer dump-autoload --optimize --no-dev --no-interaction --no-scripts

# ─── Etapa 2 · Imagen final ──────────────────────────────────────────────────
# serversideup/php trae nginx, php-fpm y su supervisor, corre como usuario sin
# privilegios y escucha en el puerto 8080.
#
# NOTA SOBRE LOS RECURSOS DE INTERFAZ. Aquí había una etapa que ejecutaba la
# compilación de Vite dentro de la imagen. Se retiró porque el compilador
# emplea binarios nativos distintos según la biblioteca de C del sistema, y el
# archivo de bloqueo se generó sobre una distribución con GNU libc, de modo que
# no incluye las variantes que necesita una imagen basada en musl.
#
# Los recursos compilados se versionan en public/build y se despliegan tal
# cual. Además de resolver aquel fallo, tiene dos ventajas propias de un
# despliegue serio: el servidor de producción no necesita Node ni las
# dependencias de desarrollo, y lo que se publica es exactamente lo que se
# probó, no algo recompilado en destino.
#
# Para regenerarlos tras cambiar vistas o estilos:  cd app && npm run build
FROM serversideup/php:8.5-fpm-nginx

USER root
RUN install-php-extensions pdo_mysql bcmath gd intl zip opcache
USER www-data

WORKDIR /var/www/html
COPY --chown=www-data:www-data app/ /var/www/html/
COPY --from=vendor --chown=www-data:www-data /build/vendor /var/www/html/vendor

# Comprobaciones tempranas. Un contenedor que arranca pero sirve páginas sin
# estilos, o que falla al resolver una clase en la primera petición, es un
# problema difícil de diagnosticar en plena demostración. Es preferible que la
# construcción se detenga aquí, con un mensaje que diga exactamente qué falta.
RUN test -f /var/www/html/public/build/manifest.json || { \
      echo ''; \
      echo 'ERROR: faltan los recursos compilados en app/public/build.'; \
      echo 'Generalos con:  cd app && npm ci && npm run build'; \
      echo ''; \
      exit 1; \
    }; \
    test -f /var/www/html/vendor/composer/autoload_classmap.php || { \
      echo ''; \
      echo 'ERROR: el mapa de clases optimizado no viajo en la imagen.'; \
      echo 'Revisá la etapa vendor del Dockerfile.'; \
      echo ''; \
      exit 1; \
    }

ENV NGINX_WEBROOT=/var/www/html/public \
    SSL_MODE=off \
    PHP_OPCACHE_ENABLE=1 \
    AUTORUN_ENABLED=true
