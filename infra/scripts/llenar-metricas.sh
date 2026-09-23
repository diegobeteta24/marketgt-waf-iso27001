#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
#  MarketGT · Ejecutar los controles para que el triangulo tenga datos
#
#  El planificador ya corre estos controles solo: los parches a diario y la
#  prueba de restauracion los domingos. Este guion los ejecuta AHORA, sin
#  esperar, que es lo que hace falta la primera vez y antes de una revision.
#
#  No inventa ninguna cifra. Cada metrica que sube aqui lo hace porque el
#  control se ejecuto de verdad.
#
#  UNA COSA QUE HAY QUE ENTENDER PARA QUE ESTO FUNCIONE: el contenedor de la
#  aplicacion no ve el anfitrion. No puede leer /var/log/dpkg.log, ni
#  /usr/share/doc, ni ejecutar mariadb-dump contra el volumen. Por eso los dos
#  recolectores corren FUERA, en el anfitrion, y le entregan al contenedor el
#  hecho ya extraido: el inventario por un volumen montado en solo lectura, y
#  el acta de restauracion por la entrada estandar.
#
#  Uso:  sudo bash infra/scripts/llenar-metricas.sh
# ─────────────────────────────────────────────────────────────────────────────
set -uo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENTORNO="${RAIZ}/docker/.env"
COMPOSE="docker compose -f ${RAIZ}/docker/docker-compose.yml"
ARTISAN="${COMPOSE} exec -T app php artisan"
INVENTARIO="${INVENTARIO:-/var/lib/marketgt/parches/inventario-parches.jsonl}"

log()   { printf '\n\033[1;34m▸ %s\033[0m\n' "$*"; }
ok()    { printf '  \033[1;32m✓\033[0m %s\n' "$*"; }
warn()  { printf '  \033[1;33m!\033[0m %s\n' "$*"; }
fallo() { printf '  \033[1;31m✗\033[0m %s\n' "$*"; }

# El .env con los secretos es 600 y de root. Sin esto docker compose falla con
# "permission denied" sin llegar a ejecutar nada, que es un fallo silencioso
# muy facil de leer como "no hizo nada".
if [ "$(id -u)" -ne 0 ]; then
    fallo "Hay que ejecutarlo como root:  sudo bash $0"
    exit 1
fi

[ -f "${ENTORNO}" ] || { fallo "No existe ${ENTORNO}. Ejecuta antes 04-desplegar.sh"; exit 1; }

# shellcheck disable=SC1090
set -a; . "${ENTORNO}"; set +a

# ─── 1 · Parches, vertice de PROTECCION ──────────────────────────────────────
log "1 · Inventario de parches del anfitrion"

mkdir -p "$(dirname "${INVENTARIO}")"
chmod 755 "$(dirname "${INVENTARIO}")"

bash "${RAIZ}/scripts/recolectar-parches.sh" --salida "${INVENTARIO}" \
    || warn "El recolector termino con error; se intenta ingerir lo que haya escrito"

if [ ! -s "${INVENTARIO}" ]; then
    fallo "El inventario quedo vacio: ${INVENTARIO}"
else
    chmod 644 "${INVENTARIO}"

    # Comprobacion previa, y no despues del fallo: el comando dentro del
    # contenedor no puede distinguir "el recolector no corrio" de "el volumen no
    # esta montado", y son dos arreglos distintos. Desde aqui si se ve.
    if ${COMPOSE} exec -T app test -r "${INVENTARIO}"; then
        if ${ARTISAN} siem:ingerir-parches; then
            ok "Inventario ingerido"
        else
            fallo "La ingesta fallo. La salida de arriba dice por que."
        fi
    else
        fallo "El contenedor no ve ${INVENTARIO}."
        fallo "Falta el montaje del volumen. Vuelve a desplegar para aplicarlo:"
        fallo "  sudo bash infra/scripts/04-desplegar.sh marketgt.duckdns.org"
    fi
fi

# ─── 2 · Restauracion, vertice de RESPUESTA ──────────────────────────────────
log "2 · Prueba de restauracion (RTO y RPO)"
echo "  Vuelca la base, la cifra con AES-256, la descifra y la restaura sobre una"
echo "  base desechable. No toca produccion. Tarda un par de minutos."

# La frase de cifrado tiene que ser estable entre ejecuciones: si cambiara cada
# vez, el acta describiria un respaldo que nadie podria volver a abrir. Se genera
# una vez y se guarda junto a los demas secretos.
if ! grep -q '^SIEM_FRASE_RESPALDO=' "${ENTORNO}" 2>/dev/null; then
    printf 'SIEM_FRASE_RESPALDO=%s\n' "$(openssl rand -base64 32 | tr -d '\n')" >> "${ENTORNO}"
    chmod 600 "${ENTORNO}"
    ok "Frase de cifrado de respaldos generada y guardada en el .env"
    # shellcheck disable=SC1090
    set -a; . "${ENTORNO}"; set +a
fi

export SIEM_BD_BASE="${DB_DATABASE:-marketgt}"
export SIEM_BD_USUARIO="${DB_USERNAME:-marketgt}"
export SIEM_BD_CLAVE="${DB_PASSWORD:-}"
export SIEM_BD_ADMIN_CLAVE="${DB_ROOT_PASSWORD:-}"
export SIEM_CONTENEDOR_BD="${SIEM_CONTENEDOR_BD:-marketgt-db}"
export SIEM_FRASE_RESPALDO="${SIEM_FRASE_RESPALDO:-}"

# El guion corre en el ANFITRION, que es quien tiene docker y el volumen; el
# acta viaja por la entrada estandar al comando, que es quien tiene la base
# donde se registra. Ninguno de los dos podria hacer el trabajo del otro.
ACTA="$(mktemp)"
trap 'rm -f "${ACTA}"' EXIT

if bash "${RAIZ}/scripts/probar-restauracion.sh" --json > "${ACTA}" 2>/tmp/restauracion-error.log; then
    if ${ARTISAN} siem:probar-restauracion --registrar=- < "${ACTA}"; then
        ok "Acta de restauracion registrada"
    else
        fallo "La prueba corrio pero el acta no se pudo registrar."
    fi
else
    fallo "La prueba de restauracion fallo. Motivo:"
    sed 's/^/     /' /tmp/restauracion-error.log | tail -12
    # Un acta de fallo tambien es un hecho que la metrica debe conocer: un
    # intento que no verifico no demuestra ningun tiempo de recuperacion, y eso
    # es distinto de no haber intentado nunca.
    if [ -s "${ACTA}" ]; then
        ${ARTISAN} siem:probar-restauracion --registrar=- < "${ACTA}" >/dev/null 2>&1 \
            && warn "Se registro el acta del intento fallido, que es lo que corresponde"
    fi
fi

# ─── 3 · Triaje, vertice de DETECCION ────────────────────────────────────────
log "3 · Triaje por lote de las alertas de demostracion"

${ARTISAN} siem:triar-sinteticas --dias=0 || warn "El triaje por lote no encontro nada que clasificar"

# ─── 4 · Resultado ───────────────────────────────────────────────────────────
log "4 · Como queda el triangulo"

cat > /tmp/marketgt-triangulo.php <<'PHP'
<?php
use App\Services\Siem\CalculadoraMetricas as C;

$vertices = app(C::class)->calcular();

$palabra = [C::CUMPLE => 'cumple', C::INCUMPLE => 'INCUMPLE', C::SIN_DATOS => 'sin datos'];

foreach ($vertices as $v) {
    $metricas = $v['metricas'];
    $total = count($metricas);
    $cumplen = count(array_filter($metricas, fn ($m) => $m['estado'] === C::CUMPLE));
    $medidas = count(array_filter($metricas, fn ($m) => $m['estado'] !== C::SIN_DATOS));
    $fallan = count(array_filter($metricas, fn ($m) => $m['estado'] === C::INCUMPLE));

    $estado = $medidas === 0
        ? 'sin instrumentar'
        : ($fallan > 0 ? 'INCUMPLE' : ($medidas === $total ? 'cumple' : 'parcial'));

    printf("\n  %-11s  %-16s  %d de %d\n", strtoupper($v['nombre']), $estado, $cumplen, $total);

    foreach ($metricas as $m) {
        printf("     %-34s %-10s %s\n",
            substr((string) $m['nombre'], 0, 34),
            $palabra[$m['estado']] ?? (string) $m['estado'],
            (string) ($m['valor_texto'] ?? ''));

        // El motivo solo se imprime cuando hay algo que arreglar. En las que
        // cumplen ocuparia pantalla sin decir nada accionable.
        if ($m['estado'] !== C::CUMPLE) {
            $motivo = trim((string) ($m['origen'] ?? ''));
            foreach (str_split($motivo, 86) as $linea) {
                printf("        %s\n", trim($linea));
            }
        }
    }
}
echo "\n";
PHP

${COMPOSE} cp /tmp/marketgt-triangulo.php app:/tmp/t.php >/dev/null 2>&1
${COMPOSE} exec -T app php artisan tinker /tmp/t.php 2>&1 | grep -vE '^\s*$|Psy Shell|INFO'
${COMPOSE} exec -T app rm -f /tmp/t.php >/dev/null 2>&1
rm -f /tmp/marketgt-triangulo.php

log "Lo que queda, y por que no tiene comando"
cat <<'TEXTO'
  1) PERSONAL CAPACITADO  ·  la otra metrica de PROTECCION

     https://marketgt.duckdns.org/seguridad/capacitacion
     Entra como administrador, marca la sesion como impartida y registra la
     asistencia de cada integrante.

     No se automatiza a proposito: el control pierde su sentido si la asistencia
     se autodeclara. Que la registre una persona con rol ES el control.

  2) COBERTURA DE TRIAJE  ·  la metrica que hace incumplir a DETECCION

     https://marketgt.duckdns.org/siem/alertas
     La meta es el 100 % de las alertas revisadas, y las de trafico real solo
     las puede revisar una persona. Para eso esta la pantalla de acciones
     masivas al pie de esa pagina: marca un lote, elige el estado y aplica.

     El lote automatico de arriba no las toca, y es deliberado: una alerta de
     trafico real cerrada por un comando no la reviso nadie, y esa distincion
     es justo lo que la metrica mide.
TEXTO
echo
echo "  Resultado en:  https://marketgt.duckdns.org/siem/metricas"
echo
