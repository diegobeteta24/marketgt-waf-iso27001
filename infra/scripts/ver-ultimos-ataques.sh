#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
#  MarketGT · Qué pasó en los últimos minutos
#
#  Recorre las tres etapas por las que viaja un ataque y dice en cuál se
#  quedó, que es lo que permite distinguir un problema de otro:
#
#     1. El cortafuegos lo vio y lo escribió en su registro de auditoría
#     2. La ingesta lo leyó y lo convirtió en evento
#     3. La correlación lo evaluó y, si correspondía, levantó una alerta
#
#  Si aparece en la primera y no en la segunda, la ingesta está detenida. Si
#  aparece en la segunda y no en la tercera, el motor de correlación no corre
#  o ninguna regla casó. Sin este desglose, un panel vacío no dice cuál de las
#  tres cosas falló.
#
#  Uso:  bash infra/scripts/ver-ultimos-ataques.sh [minutos]
# ─────────────────────────────────────────────────────────────────────────────
set -uo pipefail

MINUTOS="${1:-30}"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/docker"
COMPOSE="docker compose -f ${DIR}/docker-compose.yml"

log() { printf '\n\033[1;34m▸ %s\033[0m\n' "$*"; }

log "1 · Lo que vio el cortafuegos (últimas 15 transacciones registradas)"
${COMPOSE} exec -T waf tail -15 /var/log/modsecurity/audit/audit.json 2>/dev/null \
  | grep -oE '"uri":"[^"]{0,70}|"ruleId":"[0-9]+"' \
  | sed 's/"uri":"/  ruta   /; s/"ruleId":"/  regla  /; s/"$//' \
  | tail -30
echo "  (si no sale nada, el cortafuegos no ha registrado tráfico relevante)"

log "2 · Lo que ingirió el panel (últimos ${MINUTOS} minutos)"
cat > /tmp/marketgt-ultimos.php <<PHP
<?php
use App\Models\AlertaSeguridad;
use App\Models\EventoSeguridad;

\$desde = now()->subMinutes(${MINUTOS});

\$total  = EventoSeguridad::where('marca_tiempo', '>=', \$desde)->count();
\$corte  = EventoSeguridad::where('marca_tiempo', '>=', \$desde)->where('fue_bloqueado', true)->count();
\$reales = EventoSeguridad::where('marca_tiempo', '>=', \$desde)->where('es_demostracion', false)->count();

printf("  eventos ingeridos        %d\n", \$total);
printf("  de ellos bloqueados      %d\n", \$corte);
printf("  reales (no sembrados)    %d\n", \$reales);

// Las reglas que más se activaron: es la evidencia concreta de qué detectó.
\$reglas = EventoSeguridad::where('marca_tiempo', '>=', \$desde)
    ->whereNotNull('identificadores_regla')
    ->pluck('identificadores_regla')
    ->flatMap(fn (\$r) => is_array(\$r) ? \$r : (json_decode((string) \$r, true) ?: []))
    ->countBy()
    ->sortDesc()
    ->take(8);

if (\$reglas->isNotEmpty()) {
    echo "\n  reglas activadas:\n";
    foreach (\$reglas as \$id => \$veces) {
        printf("    %-10s %d\n", \$id, \$veces);
    }
}

echo "\n";
\$alertas = AlertaSeguridad::where('created_at', '>=', \$desde)->count();
printf("  alertas generadas        %d\n", \$alertas);

// El último evento delata si la ingesta sigue viva, con independencia de si
// hubo tráfico: un sistema sin eventos recientes puede estar en calma o roto.
\$ultimo = EventoSeguridad::orderByDesc('marca_tiempo')->first();
if (\$ultimo) {
    \$min = (int) now()->diffInMinutes(\$ultimo->marca_tiempo, true);
    printf("\n  último evento hace       %d min\n", \$min);
    if (\$min > 5) {
        echo "\n  \033[1;33m! La ingesta corre cada minuto: más de 5 sin eventos es sospechoso.\033[0m\n";
        echo "    Revisá el programador:  docker compose ps programador\n";
    }
}
PHP

${COMPOSE} cp /tmp/marketgt-ultimos.php app:/tmp/ultimos.php >/dev/null 2>&1
# </dev/null: tinker ejecuta el archivo y despues abre su consola interactiva;
# sin esto se queda esperando que alguien escriba y el guion parece colgado.
${COMPOSE} exec -T app php artisan tinker /tmp/ultimos.php < /dev/null 2>&1 \
  | grep -vE '^\s*$|Psy Shell|INFO' || true
${COMPOSE} exec -T app rm -f /tmp/ultimos.php >/dev/null 2>&1
rm -f /tmp/marketgt-ultimos.php

log "3 · Dónde mirarlo en pantalla"
echo "  https://marketgt.duckdns.org/siem/eventos"
echo "  https://marketgt.duckdns.org/siem/alertas"
echo
