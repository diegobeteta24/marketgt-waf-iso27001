#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
#  MarketGT · Respaldo cifrado de la base de datos (Capa 6 · A.8.13)
#
#  Vuelca la base de MariaDB, la comprime, la cifra con AES-256 y la deja en
#  /var/backups/marketgt con permisos 600. Lo programa 04-desplegar.sh en
#  /etc/cron.d/marketgt-controles, a diario a las 02:30.
#
#  QUE PROTEGE Y QUE NO, dicho sin adornos:
#
#    Protege contra la corrupcion de la base, un borrado accidental y el
#    compromiso de la aplicacion. El directorio de respaldos no esta montado en
#    ningun contenedor: quien tome la tienda no alcanza los respaldos, ni para
#    leerlos ni para cifrarlos con un rescate.
#
#    NO protege contra la perdida de la maquina virtual entera. El respaldo vive
#    en el mismo disco que la base, fuera de su volumen pero no fuera del
#    servidor. Una copia fuera del servidor exige almacenamiento externo con
#    coste, y queda declarada como limitacion del proyecto en lugar de
#    afirmada como hecha.
#
#  UN RESPALDO QUE NO SE PUEDE ABRIR NO ES UN RESPALDO. Por eso cada copia se
#  verifica nada mas escribirse: se descifra, se descomprime y se comprueba que
#  mariadb-dump llego a escribir su linea final. Un volcado cortado a la mitad
#  comprime y cifra sin quejarse, y solo se descubre el dia que hace falta.
#
#  Uso:  sudo bash infra/scripts/respaldar-base.sh
# ─────────────────────────────────────────────────────────────────────────────
set -uo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENTORNO="${SIEM_ENTORNO:-${RAIZ}/docker/.env}"
DESTINO="${SIEM_RUTA_RESPALDOS:-/var/backups/marketgt}"
CONTENEDOR="${SIEM_CONTENEDOR_BD:-marketgt-db}"

# Catorce dias: dos semanas de puntos de restauracion. Suficiente para volver a
# antes de un incidente que tardo en detectarse, sin llenar el disco.
RETENCION_DIAS="${RETENCION_DIAS:-14}"

marca() { date '+%Y-%m-%d %H:%M:%S'; }
log()   { printf '[%s] %s\n' "$(marca)" "$*"; }
fallo() { printf '[%s] FALLO: %s\n' "$(marca)" "$*" >&2; exit 1; }

# ─── Credenciales y frase ────────────────────────────────────────────────────
if [ -r "${ENTORNO}" ]; then
    # shellcheck disable=SC1090
    set -a; . "${ENTORNO}"; set +a
elif [ -z "${DB_ROOT_PASSWORD:-}" ]; then
    fallo "No se puede leer ${ENTORNO}. Ejecuta el guion como root."
fi

BASE="${DB_DATABASE:-marketgt}"
CLAVE="${DB_ROOT_PASSWORD:-}"
FRASE="${SIEM_FRASE_RESPALDO:-}"

[ -n "${CLAVE}" ] || fallo "Falta DB_ROOT_PASSWORD en ${ENTORNO}."

# Sin frase no se cifra, y sin cifrar no se respalda. Guardar la base en claro
# en el disco contradiria lo que el proyecto declara, y es peor que no tener
# respaldo porque da una falsa sensacion de proteccion.
[ -n "${FRASE}" ] || fallo "Falta SIEM_FRASE_RESPALDO en ${ENTORNO}. La genera 04-desplegar.sh."

command -v docker >/dev/null 2>&1 || fallo "No hay docker en el anfitrion."
command -v gpg    >/dev/null 2>&1 || fallo "No hay gpg en el anfitrion."

docker inspect -f '{{.State.Running}}' "${CONTENEDOR}" 2>/dev/null | grep -q true \
    || fallo "El contenedor ${CONTENEDOR} no esta en marcha."

# ─── Volcado, compresion y cifrado en una sola tuberia ───────────────────────
# El volcado nunca toca el disco en claro: sale de mariadb-dump, pasa por gzip y
# entra en gpg sin archivo intermedio.
umask 077
mkdir -p "${DESTINO}"
chmod 700 "${DESTINO}"

SELLO="$(date '+%Y%m%d-%H%M%S')"
FINAL="${DESTINO}/marketgt-${SELLO}.sql.gz.gpg"

# Se escribe con un nombre que la prueba de restauracion no reconoce y se
# renombra al terminar. Si el guion muere a mitad, lo que queda es un .parcial
# que nadie va a tomar por un respaldo valido para medir el RPO.
PARCIAL="${DESTINO}/.marketgt-${SELLO}.parcial"
trap 'rm -f "${PARCIAL}"' EXIT

log "Volcando ${BASE} desde ${CONTENEDOR}"
INICIO="$(date +%s)"

# --single-transaction: una foto coherente de InnoDB sin bloquear la tienda.
# La contrasena viaja por MYSQL_PWD en el entorno y no en la linea de ordenes,
# donde cualquier usuario del servidor la veria con ps.
MYSQL_PWD="${CLAVE}" docker exec -i -e MYSQL_PWD "${CONTENEDOR}" \
    mariadb-dump -u root \
        --single-transaction --quick \
        --routines --triggers --events --hex-blob \
        "${BASE}" \
  | gzip -6 \
  | gpg --batch --yes --no-tty --quiet \
        --pinentry-mode loopback --passphrase-fd 3 \
        --symmetric --cipher-algo AES256 \
        --output "${PARCIAL}" \
        3< <(printf '%s' "${FRASE}")

ESTADOS=("${PIPESTATUS[@]}")

if [ "${ESTADOS[0]}" -ne 0 ]; then
    fallo "mariadb-dump termino con codigo ${ESTADOS[0]}. No se guarda un respaldo incompleto."
fi
if [ "${ESTADOS[1]}" -ne 0 ] || [ "${ESTADOS[2]}" -ne 0 ]; then
    fallo "La compresion o el cifrado fallaron (gzip ${ESTADOS[1]}, gpg ${ESTADOS[2]})."
fi
[ -s "${PARCIAL}" ] || fallo "El archivo cifrado quedo vacio."

# ─── Verificacion: que se pueda abrir, y que este entero ─────────────────────
log "Verificando que el respaldo se puede abrir"

COLA="$(gpg --batch --no-tty --quiet \
            --pinentry-mode loopback --passphrase-fd 3 \
            --decrypt "${PARCIAL}" 3< <(printf '%s' "${FRASE}") 2>/dev/null \
        | gzip -dc 2>/dev/null \
        | tail -c 400)"

# mariadb-dump escribe "-- Dump completed" como ultima linea solo si termino
# bien. Es la unica prueba de que el volcado no se corto a la mitad.
if ! printf '%s' "${COLA}" | grep -q -- '-- Dump completed'; then
    fallo "El respaldo no se puede abrir o esta truncado: falta la linea final de mariadb-dump."
fi

mv "${PARCIAL}" "${FINAL}"
chmod 600 "${FINAL}"
trap - EXIT

DURACION=$(( $(date +%s) - INICIO ))
TAMANO="$(du -h "${FINAL}" | cut -f1)"
HUELLA="$(sha256sum "${FINAL}" | cut -c1-16)"

log "Respaldo verificado: ${FINAL}"
log "  tamano ${TAMANO} · ${DURACION} s · sha256 ${HUELLA}... · AES-256"

# ─── Retencion ───────────────────────────────────────────────────────────────
# Solo se borra DESPUES de tener un respaldo nuevo verificado, y nunca el mas
# reciente: una retencion que puede dejar el directorio vacio es un borrado
# programado de todos los respaldos.
BORRADOS="$(find "${DESTINO}" -maxdepth 1 -type f -name 'marketgt-*.sql.gz.gpg' \
    -mtime "+${RETENCION_DIAS}" ! -path "${FINAL}" -print -delete 2>/dev/null | wc -l)"

TOTAL="$(find "${DESTINO}" -maxdepth 1 -type f -name 'marketgt-*.sql.gz.gpg' | wc -l)"
log "Retencion de ${RETENCION_DIAS} dias: ${BORRADOS} borrados, ${TOTAL} disponibles"
