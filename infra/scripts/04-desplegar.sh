#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
#  MarketGT · Despliegue del sistema completo
#
#  Se ejecuta DENTRO del servidor, después del script de endurecimiento.
#  Genera los secretos, construye las imágenes, levanta el conjunto de
#  contenedores, emite el certificado y deja el sitio publicado.
#
#  Uso:   sudo bash infra/scripts/04-desplegar.sh <dominio> [correo]
#  Ej.:   sudo bash infra/scripts/04-desplegar.sh marketgt.us.kg
#
#  Es idempotente: si vuelve a ejecutarse, conserva los secretos ya generados.
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

log()  { printf '\n\033[1;34m▸ %s\033[0m\n' "$*"; }
ok()   { printf '  \033[1;32m✓\033[0m %s\n' "$*"; }
warn() { printf '  \033[1;33m!\033[0m %s\n' "$*"; }
die()  { printf '\n\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Ejecutar con sudo."

DOMINIO="${1:-}"
CORREO="${2:-admin@${DOMINIO:-marketgt.local}}"
[ -n "${DOMINIO}" ] || die "Falta el dominio.  Uso: sudo bash $0 <dominio> [correo]"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DOCKER_DIR="${REPO_ROOT}/infra/docker"
ENV_FILE="${DOCKER_DIR}/.env"

log "Desplegando MarketGT en ${DOMINIO}"

# ─────────────────────────────────────────────────────────────────────────────
# Traer los cambios antes de construir
#
# El código viaja DENTRO de la imagen: el Dockerfile lo copia al construir y el
# contenedor no lee nada del disco del servidor. Por eso, si se despliega sin
# haber traído los cambios, la construcción encuentra el mismo contexto de
# siempre, reutiliza la imagen anterior en unos milisegundos, y el despliegue
# informa éxito sin haber cambiado absolutamente nada.
#
# Esa confusión costó varias horas: el sitio seguía mostrando la versión
# anterior mientras el servidor ya tenía los archivos nuevos en el disco, y
# ninguna de las dos cosas parecía contradictoria por separado.
#
# Pista para reconocerlo: una construcción legítima de esta imagen tarda
# minutos. Si termina en menos de un segundo, no se construyó nada.
# ─────────────────────────────────────────────────────────────────────────────
if [ "${SIN_ACTUALIZAR:-0}" != "1" ] && [ -d "${REPO_ROOT}/.git" ]; then
  log "Trayendo los cambios del repositorio"
  ANTES="$(git -C "${REPO_ROOT}" rev-parse --short HEAD 2>/dev/null || echo '?')"

  if git -C "${REPO_ROOT}" pull --ff-only 2>&1 | tail -3; then
    DESPUES="$(git -C "${REPO_ROOT}" rev-parse --short HEAD 2>/dev/null || echo '?')"
    if [ "${ANTES}" = "${DESPUES}" ]; then
      ok "Ya estaba al día (${DESPUES})"
    else
      ok "Actualizado de ${ANTES} a ${DESPUES}"
    fi
  else
    warn "No se pudieron traer los cambios. Se despliega lo que hay en el disco."
    warn "Si esperabas cambios nuevos, resolvelo antes de continuar."
  fi
fi
log "Repositorio: ${REPO_ROOT}"

# ─────────────────────────────────────────────────────────────────────────────
# 1. Docker
# ─────────────────────────────────────────────────────────────────────────────
if ! command -v docker >/dev/null 2>&1; then
  log "Instalando Docker"
  curl -fsSL https://get.docker.com | sh >/dev/null
  ok "Docker instalado"
fi
docker compose version >/dev/null 2>&1 || die "Falta el complemento compose de Docker."
ok "Docker $(docker --version | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)"

# ─────────────────────────────────────────────────────────────────────────────
# 2. Parámetro del núcleo que exige el motor de indexación
#    Sin esto el contenedor del indexador termina a los pocos segundos de
#    arrancar, y es el fallo más habitual en un primer despliegue.
# ─────────────────────────────────────────────────────────────────────────────
if [ "$(sysctl -n vm.max_map_count 2>/dev/null || echo 0)" -lt 262144 ]; then
  log "Ajustando vm.max_map_count"
  echo 'vm.max_map_count=262144' > /etc/sysctl.d/99-marketgt-indexer.conf
  sysctl -p /etc/sysctl.d/99-marketgt-indexer.conf >/dev/null
  ok "vm.max_map_count = 262144"
fi

# Memoria de intercambio: da margen cuando el indexador y la base de datos
# coinciden en un pico. Sin ella, el núcleo termina el proceso más grande,
# que suele ser justo el que se está enseñando.
if [ "$(free -m | awk '/^Swap:/{print $2}')" -lt 1024 ]; then
  log "Creando 2 GB de memoria de intercambio"
  fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile >/dev/null && swapon /swapfile
  grep -q '/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
  ok "Memoria de intercambio activa"
fi

# ─────────────────────────────────────────────────────────────────────────────
# 3. Secretos
#    Se generan una sola vez y se conservan. Volver a generarlos invalidaría
#    los datos ya cifrados en reposo y las sesiones activas.
# ─────────────────────────────────────────────────────────────────────────────
log "Preparando los secretos"
gen() { openssl rand -base64 32 | tr -d '/+=' | head -c 32; }

if [ -f "${ENV_FILE}" ]; then
  ok "Se conservan los secretos existentes"
else
  APP_KEY="base64:$(openssl rand -base64 32)"
  cat > "${ENV_FILE}" <<EOF
# ─────────────────────────────────────────────────────────────────────
# MarketGT · Configuración de despliegue
# Generado el $(date -Iseconds)
#
# ESTE ARCHIVO CONTIENE SECRETOS. Nunca se versiona: está excluido en
# .gitignore. Si se filtra, hay que rotar todas las claves y reconstruir.
# ─────────────────────────────────────────────────────────────────────

APP_DOMINIO=${DOMINIO}

# La dirección de la aplicación determina el identificador de la parte
# confiante de WebAuthn. Si no coincide EXACTAMENTE con el dominio por el
# que se entra, el registro de passkeys falla sin mostrar ningún error.
APP_URL=https://${DOMINIO}
APP_KEY=${APP_KEY}

DB_DATABASE=marketgt
DB_USERNAME=marketgt
DB_PASSWORD=$(gen)
DB_ROOT_PASSWORD=$(gen)

# Cabecera secreta que permite a la consola de demostración atravesar el
# WAF en modo de solo detección, sin desactivar el registro de auditoría.
CONSOLA_DEMO_TOKEN=$(gen)
EOF
  chmod 600 "${ENV_FILE}"
  ok "Secretos generados en ${ENV_FILE} (permisos 600)"
fi

# La frase que cifra los respaldos. Se anade a un .env existente en lugar de
# regenerarlo entero, porque regenerar invalidaria las demas claves.
#
# Es el secreto mas delicado del archivo: sin ella, los respaldos cifrados son
# ilegibles, tambien para nosotros. Conviene guardar una copia FUERA del
# servidor; si la maquina se pierde, la frase se pierde con ella.
if ! grep -q '^SIEM_FRASE_RESPALDO=' "${ENV_FILE}"; then
  {
    echo ""
    echo "# Cifra los respaldos de la base (AES-256). Sin ella no se pueden abrir."
    echo "# Guarda una copia fuera del servidor."
    echo "SIEM_FRASE_RESPALDO=$(gen)$(gen)"
  } >> "${ENV_FILE}"
  chmod 600 "${ENV_FILE}"
  ok "Frase de cifrado de respaldos generada (copiala fuera del servidor)"
fi

# shellcheck disable=SC1090
set -a; . "${ENV_FILE}"; set +a

# ─────────────────────────────────────────────────────────────────────────────
# 4. Certificado
#    Se emite ANTES de levantar el conjunto, en modo autónomo, porque en ese
#    momento el puerto 80 todavía está libre.
# ─────────────────────────────────────────────────────────────────────────────
log "Emitiendo el certificado para ${DOMINIO}"
CERT_DIR="/etc/letsencrypt/live/${DOMINIO}"

if [ -f "${CERT_DIR}/fullchain.pem" ]; then
  ok "Ya existe un certificado válido"
else
  command -v certbot >/dev/null 2>&1 || {
    apt-get update -qq && apt-get install -y -qq certbot
  }
  docker compose -f "${DOCKER_DIR}/docker-compose.yml" down >/dev/null 2>&1 || true

  if certbot certonly --standalone --non-interactive --agree-tos \
       -m "${CORREO}" -d "${DOMINIO}" 2>&1 | tail -5; then
    ok "Certificado emitido"
  else
    warn "No se pudo emitir el certificado."
    warn "Comprobá que ${DOMINIO} resuelve hacia la IP de este servidor:"
    warn "   dig +short ${DOMINIO}"
    warn "Si usás Cloudflare, poné el registro en gris (solo DNS) mientras se emite."
    warn "El despliegue continúa; el WAF usará un certificado autofirmado."
  fi
fi

# El WAF corre como usuario sin privilegios y no puede leer los archivos de
# Let's Encrypt, cuyos permisos son restrictivos. Se copian a una ruta propia.
CERTS_DIR="${REPO_ROOT}/infra/certs"
mkdir -p "${CERTS_DIR}"
if [ -f "${CERT_DIR}/fullchain.pem" ]; then
  cp "${CERT_DIR}/fullchain.pem" "${CERTS_DIR}/server.crt"
  cp "${CERT_DIR}/privkey.pem"   "${CERTS_DIR}/server.key"
  ok "Certificado de la autoridad disponible para el WAF"
else
  # Sin estos archivos el contenedor no arrancaría, porque el archivo de
  # composición los monta. Se genera uno autofirmado para que el despliegue
  # pueda completarse y el problema quede acotado al aviso del navegador,
  # en lugar de dejar el sitio entero caído.
  warn "Se genera un certificado autofirmado provisional"
  warn "El navegador mostrará una advertencia hasta emitir el definitivo"
  openssl req -x509 -newkey rsa:2048 -nodes -days 30 \
    -subj "/CN=${DOMINIO}" \
    -keyout "${CERTS_DIR}/server.key" \
    -out "${CERTS_DIR}/server.crt" >/dev/null 2>&1
fi
chmod 644 "${CERTS_DIR}/server.crt"
chmod 644 "${CERTS_DIR}/server.key"

# El WAF corre como usuario sin privilegios y no podría leer la llave con
# permisos restrictivos. Es aceptable porque el archivo vive dentro del
# servidor, cuyo acceso ya está limitado a llave criptográfica.
ok "Certificado listo en ${CERTS_DIR}"

# ─────────────────────────────────────────────────────────────────────────────
# 5. Construcción y arranque
# ─────────────────────────────────────────────────────────────────────────────
log "Construyendo las imágenes (puede tardar varios minutos la primera vez)"
docker compose -f "${DOCKER_DIR}/docker-compose.yml" build 2>&1 | tail -15
ok "Imágenes construidas"

log "Levantando el conjunto de contenedores"
docker compose -f "${DOCKER_DIR}/docker-compose.yml" up -d
sleep 15
docker compose -f "${DOCKER_DIR}/docker-compose.yml" ps

# ─────────────────────────────────────────────────────────────────────────────
# 6. Preparación de la aplicación
# ─────────────────────────────────────────────────────────────────────────────
log "Preparando la base de datos"
EJECUTAR="docker compose -f ${DOCKER_DIR}/docker-compose.yml exec -T app php artisan"

for intento in $(seq 1 10); do
  if ${EJECUTAR} migrate --force 2>&1 | tail -5; then
    ok "Migraciones aplicadas"
    break
  fi
  warn "La base de datos todavía no responde (intento ${intento}/10)"
  sleep 6
done

log "Cargando los datos iniciales"
# La salida NO se recorta. Recortarla a las últimas líneas ocultaba los fallos
# de los semilleros que corren primero, y el despliegue seguía adelante
# aparentando normalidad: la tienda quedaba sin catálogo y, peor, sin las
# cuentas con las que hay que entrar a demostrarla.
if ${EJECUTAR} db:seed --force 2>&1; then
  ok "Datos iniciales cargados"
else
  warn "Falló la carga de datos iniciales. Revisá el detalle de arriba."
  warn "Podés reintentar un semillero concreto con:"
  warn "   docker compose -f ${DOCKER_DIR}/docker-compose.yml exec -T app \\"
  warn "     php artisan db:seed --class=\"Database\\Seeders\\UsuariosDemoSeeder\" --force"
fi

# Comprobación explícita: sin cuentas no hay nada que demostrar, y descubrirlo
# frente a la clase es el peor momento posible.
CUENTAS="$(${EJECUTAR} tinker --execute="echo App\\Models\\User::count();" 2>/dev/null | tr -cd '0-9' || echo 0)"
if [ "${CUENTAS:-0}" -gt 0 ]; then
  ok "Cuentas de acceso disponibles: ${CUENTAS}"
else
  warn "NO HAY NINGUNA CUENTA EN LA BASE DE DATOS."
  warn "Nadie podría iniciar sesión. Revisá los semilleros antes de continuar."
fi

log "Optimizando para producción"
${EJECUTAR} config:cache  >/dev/null 2>&1 || true
${EJECUTAR} route:cache   >/dev/null 2>&1 || true
${EJECUTAR} view:cache    >/dev/null 2>&1 || true
${EJECUTAR} storage:link  >/dev/null 2>&1 || true
ok "Configuración, rutas y vistas en caché"

# ─────────────────────────────────────────────────────────────────────────────
# Reinicio de la aplicación · NO ES OPCIONAL
#
# El acelerador de código de PHP guarda en memoria la versión compilada de cada
# archivo. En producción no revalida las marcas de tiempo, de modo que sigue
# sirviendo la versión anterior aunque el archivo haya cambiado en el disco.
#
# El efecto es desconcertante y costó horas descubrirlo: el despliegue informa
# éxito, los archivos nuevos están en el servidor, la caché de vistas se limpia
# sin errores, y el sitio sigue mostrando exactamente lo de antes. Uno acaba
# dudando de sus propios cambios en vez de sospechar de la memoria del
# intérprete.
#
# Reiniciar el contenedor vacía esa memoria. Cuesta unos segundos y evita que
# un despliegue correcto parezca no haber ocurrido.
# ─────────────────────────────────────────────────────────────────────────────
log "Reiniciando la aplicación para vaciar el acelerador de código"
docker compose -f "${DOCKER_DIR}/docker-compose.yml" restart app programador >/dev/null 2>&1 || \
  docker compose -f "${DOCKER_DIR}/docker-compose.yml" restart app >/dev/null 2>&1 || true

for intento in $(seq 1 20); do
  ESTADO="$(docker compose -f "${DOCKER_DIR}/docker-compose.yml" ps app --format '{{.Status}}' 2>/dev/null || echo '')"
  case "${ESTADO}" in
    *healthy*) ok "Aplicación reiniciada y sana"; break ;;
  esac
  [ "${intento}" -eq 20 ] && warn "La aplicación tarda en volver; revisá: docker compose ps"
  sleep 3
done

# ─────────────────────────────────────────────────────────────────────────────
# 7. Renovación automática del certificado
# ─────────────────────────────────────────────────────────────────────────────
log "Programando la renovación del certificado"
cat > /etc/cron.d/marketgt-certificado <<EOF
# Renueva el certificado y lo vuelve a copiar donde el WAF puede leerlo.
17 3 * * 1,4 root certbot renew --quiet --pre-hook 'docker compose -f ${DOCKER_DIR}/docker-compose.yml stop waf' --post-hook 'cp /etc/letsencrypt/live/${DOMINIO}/fullchain.pem ${CERTS_DIR}/server.crt && cp /etc/letsencrypt/live/${DOMINIO}/privkey.pem ${CERTS_DIR}/server.key && docker compose -f ${DOCKER_DIR}/docker-compose.yml start waf'
EOF
ok "Renovación programada dos veces por semana"

# ─────────────────────────────────────────────────────────────────────────────
# 7b. Controles que corren EN EL ANFITRIÓN
#
#     Tres controles no pueden correr dentro de un contenedor, porque necesitan
#     lo que solo tiene el anfitrión: el volumen de la base, docker, y los
#     registros de dpkg. El planificador de Laravel vive dentro del contenedor
#     de la aplicación y no los alcanza; programarlos ahí hacía que fallaran en
#     silencio, que es peor que no programarlos.
#
#     El orden de las horas importa: el respaldo antes de la prueba de
#     restauración, para que el RPO tenga un respaldo reciente que fechar, y la
#     recolección de parches antes de que el contenedor la ingiera a las 03:15.
# ─────────────────────────────────────────────────────────────────────────────
log "Programando los controles del anfitrión"

mkdir -p /var/backups/marketgt /var/lib/marketgt/parches
chmod 700 /var/backups/marketgt
chmod 755 /var/lib/marketgt/parches

cat > /etc/cron.d/marketgt-controles <<EOF
# Instalado por 04-desplegar.sh. Se reescribe en cada despliegue.
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

# Respaldo cifrado de la base, verificado al escribirse (A.8.13).
30 2 * * *  root  bash ${REPO_ROOT}/infra/scripts/respaldar-base.sh >> /var/log/marketgt-respaldo.log 2>&1

# Inventario de parches; el contenedor lo ingiere a las 03:15 (A.8.8).
0 3 * * *   root  bash ${REPO_ROOT}/infra/scripts/recolectar-parches.sh --salida /var/lib/marketgt/parches/inventario-parches.jsonl >> /var/log/marketgt-parches.log 2>&1

# Prueba de restauración cronometrada, los domingos (RTO y RPO).
30 4 * * 0  root  bash ${REPO_ROOT}/infra/scripts/restauracion-programada.sh >> /var/log/marketgt-restauracion.log 2>&1
EOF
chmod 644 /etc/cron.d/marketgt-controles
ok "Respaldo diario, parches diarios y restauración semanal programados"

# ─────────────────────────────────────────────────────────────────────────────
# 8. Verificación
# ─────────────────────────────────────────────────────────────────────────────
log "Verificando el despliegue"

CODIGO="$(curl -sk -o /dev/null -w '%{http_code}' "https://${DOMINIO}/" --max-time 15 || echo '000')"
if [ "${CODIGO}" = "200" ] || [ "${CODIGO}" = "302" ]; then
  ok "El sitio responde HTTP ${CODIGO}"
else
  warn "El sitio respondió ${CODIGO}. Revisá:  docker compose logs waf"
fi

# El WAF tiene que bloquear una inyección evidente. Si esto no devuelve 403,
# el motor no está aplicando reglas y toda la demostración se cae.
BLOQUEO="$(curl -sk -o /dev/null -w '%{http_code}' \
           "https://${DOMINIO}/?id=1%27%20OR%201%3D1--" --max-time 15 || echo '000')"
if [ "${BLOQUEO}" = "403" ]; then
  ok "El WAF bloquea la inyección de prueba (HTTP 403)"
else
  warn "La inyección de prueba devolvió ${BLOQUEO} en vez de 403."
  warn "El motor de reglas podría no estar activo. Revisá MODSEC_RULE_ENGINE."
fi

cat <<RESUMEN

═══════════════════════════════════════════════════════════════════
  MARKETGT DESPLEGADO
═══════════════════════════════════════════════════════════════════

  Sitio            https://${DOMINIO}
  Panel SIEM       https://${DOMINIO}/siem
  Consola de demo  https://${DOMINIO}/demo-waf/consola   (rol de administrador)
  Laboratorio SEO  https://${DOMINIO}/seo/laboratorio
  Metricas         https://${DOMINIO}/siem/metricas

  Secretos         ${ENV_FILE}   (permisos 600, fuera del repositorio)

  COMPROBACIONES QUE CONVIENE HACER AHORA:
    Ver los contenedores      docker compose -f ${DOCKER_DIR}/docker-compose.yml ps
    Seguir el WAF             docker compose -f ${DOCKER_DIR}/docker-compose.yml logs -f waf
    Leer el registro del WAF  bash infra/scripts/leer-audit-log.sh
    Lanzar la demostración    bash infra/scripts/demo-waf.sh https://${DOMINIO}
    Comprobar puertos abiertos desde fuera:   nmap -Pn -p- ${DOMINIO}

═══════════════════════════════════════════════════════════════════

RESUMEN
