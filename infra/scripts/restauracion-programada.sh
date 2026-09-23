#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
#  MarketGT · Prueba de restauracion desde el anfitrion (vertice de RESPUESTA)
#
#  Une las dos mitades de la prueba, que viven en sitios distintos a proposito:
#
#    El ANFITRION tiene docker, el volumen de la base y los respaldos. Ahi corre
#    probar-restauracion.sh: vuelca, cifra, descifra y restaura sobre una base
#    desechable, y cronometra cada paso.
#
#    El CONTENEDOR tiene la base de la aplicacion donde se registra el acta. Ahi
#    corre "siem:probar-restauracion --registrar=-", que la lee por la entrada
#    estandar.
#
#  Ninguno de los dos puede hacer el trabajo del otro. Dentro del contenedor no
#  hay ni el guion ni acceso al volumen, que es por lo que programar la prueba
#  en el planificador de Laravel fallaba siempre.
#
#  Lo programa 04-desplegar.sh en /etc/cron.d/marketgt-controles, los domingos.
#
#  Uso:  sudo bash infra/scripts/restauracion-programada.sh
# ─────────────────────────────────────────────────────────────────────────────
set -uo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENTORNO="${SIEM_ENTORNO:-${RAIZ}/docker/.env}"
COMPOSE="docker compose -f ${RAIZ}/docker/docker-compose.yml"

marca() { date '+%Y-%m-%d %H:%M:%S'; }
# Todo mensaje va a la salida de errores. La salida estandar queda limpia para el
# acta en JSON, que es un dato y no puede llegar mezclada con texto para personas.
log()   { printf '[%s] %s\n' "$(marca)" "$*" >&2; }

if [ -r "${ENTORNO}" ]; then
    # shellcheck disable=SC1090
    set -a; . "${ENTORNO}"; set +a
else
    log "FALLO: no se puede leer ${ENTORNO}. Ejecuta el guion como root."
    exit 1
fi

# El guion de la prueba habla su propio vocabulario de variables; aqui se
# traducen desde el .env del despliegue, que es la unica fuente de secretos.
export SIEM_BD_BASE="${DB_DATABASE:-marketgt}"
export SIEM_BD_USUARIO="${DB_USERNAME:-marketgt}"
export SIEM_BD_CLAVE="${DB_PASSWORD:-}"
export SIEM_BD_ADMIN_CLAVE="${DB_ROOT_PASSWORD:-}"
export SIEM_CONTENEDOR_BD="${SIEM_CONTENEDOR_BD:-marketgt-db}"
export SIEM_FRASE_RESPALDO="${SIEM_FRASE_RESPALDO:-}"
export SIEM_RUTA_RESPALDOS="${SIEM_RUTA_RESPALDOS:-/var/backups/marketgt}"

ACTA="$(mktemp)"
ERRORES="$(mktemp)"
trap 'rm -f "${ACTA}" "${ERRORES}"' EXIT

# </dev/null: ninguna parte de la prueba debe poder quedarse esperando a que
# alguien escriba. En cron no hay nadie; en una sesion interactiva, detenerse a
# pedir algo que la persona no sabe es peor que fallar.
bash "${RAIZ}/scripts/probar-restauracion.sh" --json > "${ACTA}" 2> "${ERRORES}" < /dev/null
RESULTADO=$?

if [ "${RESULTADO}" -ne 0 ]; then
    log "La prueba de restauracion fallo (codigo ${RESULTADO}). Motivo:"
    tail -12 "${ERRORES}" | sed 's/^/    /' >&2
fi

# Un acta de fallo tambien se registra. Un intento que no verifico no demuestra
# ningun tiempo de recuperacion, pero es un hecho distinto de no haberlo
# intentado nunca, y el panel de continuidad tiene que poder mostrarlo.
if [ ! -s "${ACTA}" ]; then
    log "La prueba no produjo acta: no hay nada que registrar."
    exit 1
fi

if [ "${SIEM_SIN_REGISTRAR:-0}" = "1" ]; then
    # Para probar el guion sin el conjunto de contenedores de la aplicacion.
    cat "${ACTA}"
    exit "${RESULTADO}"
fi

if ${COMPOSE} exec -T app php artisan siem:probar-restauracion --registrar=- < "${ACTA}"; then
    log "Acta registrada."
else
    log "FALLO: la prueba corrio pero el acta no se pudo registrar en la aplicacion."
    exit 1
fi

exit "${RESULTADO}"
