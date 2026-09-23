#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
#  MarketGT · Ejecutar los controles para que el triangulo tenga datos
#
#  El planificador ya corre estos controles solo: los parches a diario y la
#  prueba de restauracion los domingos. Este guion los ejecuta AHORA, sin
#  esperar, que es lo que hace falta la primera vez y antes de una revision.
#
#  No inventa ninguna cifra. Cada metrica que sube aqui lo hace porque el
#  control se ejecuto de verdad:
#
#     Parcheo       lee los registros del anfitrion y los ingiere
#     Restauracion  vuelca, cifra, descifra y restaura sobre una base desechable
#     Triaje        clasifica las alertas segun un criterio declarado
#
#  Lo unico que NO se puede automatizar es la capacitacion del personal, y es a
#  proposito: el numerador son asistencias que registra una persona con rol
#  desde el panel. Una casilla que el propio interesado marca por si mismo no
#  es evidencia de nada, y el control perderia su sentido.
#
#  Uso:  sudo bash infra/scripts/llenar-metricas.sh
# ─────────────────────────────────────────────────────────────────────────────
set -uo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE="docker compose -f ${RAIZ}/docker/docker-compose.yml"
ARTISAN="${COMPOSE} exec -T app php artisan"
INVENTARIO="${INVENTARIO:-/var/lib/marketgt/parches/inventario-parches.jsonl}"

log()   { printf '\n\033[1;34m▸ %s\033[0m\n' "$*"; }
ok()    { printf '  \033[1;32m✓\033[0m %s\n' "$*"; }
warn()  { printf '  \033[1;33m!\033[0m %s\n' "$*"; }
fallo() { printf '  \033[1;31m✗\033[0m %s\n' "$*"; }

# El .env con los secretos es 600 y de root. Sin esto, docker compose falla con
# "permission denied" y los comandos no llegan a ejecutarse, que es un fallo
# silencioso muy facil de leer como "no hizo nada".
if [ "$(id -u)" -ne 0 ]; then
    fallo "Hay que ejecutarlo como root:  sudo bash $0"
    exit 1
fi

# ─── 1 · Parches, vertice de PROTECCION ──────────────────────────────────────
log "1 · Inventario de parches del anfitrion"

mkdir -p "$(dirname "${INVENTARIO}")"

if bash "${RAIZ}/scripts/recolectar-parches.sh" --salida "${INVENTARIO}"; then
    ok "Inventario escrito en ${INVENTARIO}"
else
    warn "El recolector termino con error; se intenta ingerir lo que haya escrito"
fi

if ${ARTISAN} siem:ingerir-parches; then
    ok "Inventario ingerido"
else
    fallo "La ingesta fallo. Sin esto la cobertura de parcheo se queda sin datos."
fi

# ─── 2 · Restauracion, vertice de RESPUESTA ──────────────────────────────────
log "2 · Prueba de restauracion (RTO y RPO)"
echo "  Vuelca la base, la cifra, la descifra y la restaura sobre una base"
echo "  desechable. Tarda un par de minutos y no toca produccion."

if ${ARTISAN} siem:probar-restauracion; then
    ok "Acta de restauracion registrada"
else
    fallo "La prueba fallo. Revisa que el contenedor de la base este sano:"
    fallo "  ${COMPOSE} ps db"
fi

# ─── 3 · Triaje, vertice de DETECCION ────────────────────────────────────────
log "3 · Triaje por lote de las alertas de demostracion"
echo "  Solo toca alertas nacidas de eventos sembrados, y deja constancia de"
echo "  que las decidio un proceso. Las alertas de trafico real quedan para"
echo "  una persona, en /siem/alertas."

if ${ARTISAN} siem:triar-sinteticas --dias=0; then
    ok "Alertas de demostracion triadas"
else
    warn "El triaje por lote fallo o no encontro nada que clasificar"
fi

log "4 · Recalculando y mostrando el resultado"
${ARTISAN} config:clear >/dev/null 2>&1 || true

cat > /tmp/marketgt-triangulo.php <<'PHP'
<?php
use App\Services\Siem\CalculadoraMetricas as C;

$vertices = app(C::class)->vertices();

$palabra = [C::CUMPLE => 'cumple', C::INCUMPLE => 'INCUMPLE', C::SIN_DATOS => 'sin datos'];

foreach ($vertices as $clave => $v) {
    $total = count($v['metricas']);
    $cumplen = count(array_filter($v['metricas'], fn ($m) => $m['estado'] === C::CUMPLE));
    $medidas = count(array_filter($v['metricas'], fn ($m) => $m['estado'] !== C::SIN_DATOS));

    $estado = $medidas === 0
        ? 'sin instrumentar'
        : (count(array_filter($v['metricas'], fn ($m) => $m['estado'] === C::INCUMPLE)) > 0
            ? 'INCUMPLE'
            : ($medidas === $total ? 'cumple' : 'parcial'));

    printf("\n  %-12s %-18s %d de %d\n", strtoupper($v['nombre']), $estado, $cumplen, $total);

    foreach ($v['metricas'] as $m) {
        printf("      %-38s %-10s %s\n",
            substr($m['nombre'], 0, 38),
            $palabra[$m['estado']] ?? $m['estado'],
            (string) ($m['valor_texto'] ?? ''));
    }
}
echo "\n";
PHP

${COMPOSE} cp /tmp/marketgt-triangulo.php app:/tmp/t.php >/dev/null 2>&1
${COMPOSE} exec -T app php artisan tinker /tmp/t.php 2>&1 | grep -vE '^\s*$|Psy Shell|INFO'
${COMPOSE} exec -T app rm -f /tmp/t.php >/dev/null 2>&1
rm -f /tmp/marketgt-triangulo.php

log "Lo que queda, y no se puede automatizar"
cat <<'TEXTO'
  PERSONAL CAPACITADO, la otra metrica de PROTECCION.

  Entra en https://marketgt.duckdns.org/seguridad/capacitacion con la cuenta de
  administrador, marca la sesion como impartida y registra la asistencia de cada
  integrante del equipo.

  No tiene comando a proposito: el control pierde su sentido si la asistencia se
  autodeclara. Que la registre una persona con rol ES el control.

  Con eso PROTECCION pasa a 2 de 2.
TEXTO
echo
echo "  Mira el resultado en:  https://marketgt.duckdns.org/siem/metricas"
echo
