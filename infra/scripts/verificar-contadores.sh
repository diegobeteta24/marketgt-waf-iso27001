#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
#  MarketGT · Verificación de los contadores del panel
#
#  Comprueba que las cifras que muestra la aplicación corresponden con lo que
#  hay en la base de datos. Existe porque el panel llegó a mostrar un guion en
#  el contador de peticiones bloqueadas —la cifra que justifica el proyecto
#  entero— durante casi un día sin que nadie lo notara: la consulta usaba un
#  nombre de columna equivocado, fallaba, y el panel pintaba un hueco en vez de
#  un error.
#
#  Un panel que enmascara sus propios fallos es peor que uno que se rompe.
#
#  Uso:  bash infra/scripts/verificar-contadores.sh
# ─────────────────────────────────────────────────────────────────────────────
set -uo pipefail

APP_DIR="${APP_DIR:-/opt/marketgt}"

printf '\n\033[1;34m▸ Verificando los contadores contra la base de datos\033[0m\n\n'

cat > "${APP_DIR}/verificar-contadores.php" <<'PHP'
<?php

use App\Models\AlertaSeguridad;
use App\Models\EventoSeguridad;

$desde = now()->subDay();

$fila = function (string $etiqueta, $valor): void {
    printf("  %-34s %s\n", $etiqueta, $valor);
};

echo "\n";
$fila('Eventos en total', EventoSeguridad::count());
$fila('Eventos últimas 24 h', EventoSeguridad::where('marca_tiempo', '>=', $desde)->count());
$fila('Bloqueados últimas 24 h', EventoSeguridad::where('marca_tiempo', '>=', $desde)->where('fue_bloqueado', true)->count());
$fila('Bloqueados en total', EventoSeguridad::where('fue_bloqueado', true)->count());
echo "\n";

// La distinción entre eventos reales y de demostración importa para la
// auditoría: una métrica calculada sobre datos sembrados no demuestra nada.
$reales = EventoSeguridad::where('es_demostracion', false)->count();
$demo   = EventoSeguridad::where('es_demostracion', true)->count();
$fila('Eventos reales (ingeridos del WAF)', $reales);
$fila('Eventos de demostración', $demo);
echo "\n";

$fila('Alertas abiertas', AlertaSeguridad::whereIn('estado', ['nueva', 'en_triaje'])->count());
$fila('Alertas en total', AlertaSeguridad::count());
echo "\n";

// El evento más reciente delata si la ingesta sigue viva. Es la comprobación
// que el propio panel hace y la que descubrió que faltaba programar las tareas.
$ultimo = EventoSeguridad::orderByDesc('marca_tiempo')->first();
if ($ultimo) {
    $minutos = (int) now()->diffInMinutes($ultimo->marca_tiempo, true);
    $fila('Último evento', $ultimo->marca_tiempo . "  (hace {$minutos} min)");
    if ($minutos > 15) {
        echo "\n  \033[1;33m! La ingesta lleva más de 15 minutos sin recibir eventos.\033[0m\n";
        echo "    Comprobá el contenedor del programador:  docker compose ps programador\n";
    } else {
        echo "\n  \033[1;32m✓ La ingesta está recibiendo eventos con normalidad.\033[0m\n";
    }
} else {
    echo "\n  \033[1;31m✗ No hay ningún evento en la base de datos.\033[0m\n";
}
echo "\n";
PHP

docker compose -f "${APP_DIR}/infra/docker/docker-compose.yml" exec -T app \
  php artisan tinker /var/www/html/verificar-contadores.php 2>&1 \
  | grep -vE '^\s*$|Psy Shell|exit.*quit' || true

rm -f "${APP_DIR}/verificar-contadores.php"
