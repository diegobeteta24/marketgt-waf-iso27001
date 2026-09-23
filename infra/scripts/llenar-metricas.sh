#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
#  MarketGT · Ejecutar los controles para que el triangulo tenga datos
#
#  El cron del anfitrion ya corre estos controles solo (lo instala
#  04-desplegar.sh): respaldo y parches a diario, restauracion los domingos.
#  Este guion los ejecuta AHORA, sin esperar, que es lo que hace falta la
#  primera vez y antes de una revision.
#
#  No inventa ninguna cifra. Cada metrica que sube aqui lo hace porque el
#  control se ejecuto de verdad.
#
#  El contenedor de la aplicacion no ve el anfitrion: no puede leer
#  /var/log/dpkg.log ni ejecutar mariadb-dump contra el volumen. Por eso los
#  recolectores corren FUERA y le entregan al contenedor el hecho ya extraido:
#  el inventario por un volumen de solo lectura, y el acta de restauracion por
#  la entrada estandar.
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

# Ejecuta un archivo PHP dentro de la aplicacion y TERMINA.
#
# No se usa "artisan tinker archivo.php": tinker ejecuta el archivo y despues
# abre su consola interactiva, que se queda esperando a que alguien escriba. En
# una sesion SSH eso deja el guion colgado sin ningun mensaje. Aqui el archivo
# arranca Laravel por su cuenta y sale.
ejecutar_php() {
    local origen="$1"
    local destino="/tmp/marketgt-$$.php"
    {
        echo '<?php'
        echo 'require "/var/www/html/vendor/autoload.php";'
        echo '$app = require "/var/www/html/bootstrap/app.php";'
        echo '$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();'
        tail -n +2 "${origen}"
    } > "${origen}.completo"
    ${COMPOSE} cp "${origen}.completo" "app:${destino}" >/dev/null 2>&1
    ${COMPOSE} exec -T app php "${destino}" < /dev/null
    ${COMPOSE} exec -T app rm -f "${destino}" >/dev/null 2>&1
    rm -f "${origen}" "${origen}.completo"
}

# El .env con los secretos es 600 y de root. Sin esto docker compose falla con
# "permission denied" sin llegar a ejecutar nada.
if [ "$(id -u)" -ne 0 ]; then
    fallo "Hay que ejecutarlo como root:  sudo bash $0"
    exit 1
fi

[ -f "${ENTORNO}" ] || { fallo "No existe ${ENTORNO}. Ejecuta antes 04-desplegar.sh"; exit 1; }

if ! grep -q '^SIEM_FRASE_RESPALDO=' "${ENTORNO}"; then
    fallo "Falta la frase de cifrado de respaldos. Vuelve a desplegar:"
    fallo "  sudo bash infra/scripts/04-desplegar.sh marketgt.duckdns.org"
    exit 1
fi

# ─── 1 · Parches, vertice de PROTECCION ──────────────────────────────────────
log "1 · Inventario de parches del anfitrion"

mkdir -p "$(dirname "${INVENTARIO}")"
chmod 755 "$(dirname "${INVENTARIO}")"

bash "${RAIZ}/scripts/recolectar-parches.sh" --salida "${INVENTARIO}" < /dev/null \
    || warn "El recolector termino con error; se intenta ingerir lo que haya escrito"

if [ ! -s "${INVENTARIO}" ]; then
    fallo "El inventario quedo vacio: ${INVENTARIO}"
else
    chmod 644 "${INVENTARIO}"

    # Se comprueba el montaje ANTES de ingerir. Dentro del contenedor el comando no
    # distingue "el recolector no corrio" de "el volumen no esta montado", y son
    # dos arreglos distintos.
    if ${COMPOSE} exec -T app test -r "${INVENTARIO}"; then
        ${ARTISAN} siem:ingerir-parches < /dev/null && ok "Inventario ingerido" \
            || fallo "La ingesta fallo. La salida de arriba dice por que."
    else
        fallo "El contenedor no ve ${INVENTARIO}: falta el montaje. Vuelve a desplegar."
    fi
fi

# ─── 2 · Respaldo y restauracion, vertice de RESPUESTA ───────────────────────
log "2 · Respaldo cifrado de la base"
echo "  Sin un respaldo real no hay punto de recuperacion (RPO) que medir."

if bash "${RAIZ}/scripts/respaldar-base.sh" < /dev/null; then
    ok "Respaldo escrito y verificado"
else
    fallo "El respaldo fallo. Sin el, el RPO sigue sin datos."
fi

log "3 · Prueba de restauracion (RTO y RPO)"
echo "  Vuelca, cifra, descifra y restaura sobre una base desechable. No toca produccion."

if bash "${RAIZ}/scripts/restauracion-programada.sh" < /dev/null; then
    ok "Prueba satisfactoria y acta registrada"
else
    fallo "La prueba no termino bien. La salida de arriba dice en que paso."
fi

# ─── 4 · Triaje, vertice de DETECCION ────────────────────────────────────────
log "4 · Triaje por lote de las alertas de demostracion"

${ARTISAN} siem:triar-sinteticas --dias=0 < /dev/null \
    || warn "El triaje por lote no encontro nada que clasificar"

log "5 · Que son las alertas reales que quedan sin revisar"
echo "  Es el dato para decidir cuales puede contener el sistema solo y cuales"
echo "  necesitan a una persona."

cat > /tmp/marketgt-reparto.php <<'PHP'
<?php
use App\Models\AlertaSeguridad as A;
use App\Models\EventoSeguridad as E;
use App\Services\Siem\TriajeAsistido as T;

$consulta = app(T::class)->soloReales(A::query()->where('estado', A::ESTADO_NUEVA));
$alertas = $consulta->with('eventos')->get(['id', 'clave_regla', 'severidad']);

$filas = [];
foreach ($alertas as $alerta) {
    $waf = $alerta->eventos->where('fuente', E::FUENTE_WAF);
    $cortado = $waf->isNotEmpty() && $waf->where('fue_bloqueado', false)->isEmpty();
    $clave = $alerta->clave_regla.' ('.$alerta->severidad.')';
    $filas[$clave] ??= ['total' => 0, 'cortado' => 0];
    $filas[$clave]['total']++;
    $filas[$clave]['cortado'] += $cortado ? 1 : 0;
}

uasort($filas, fn ($a, $b) => $b['total'] <=> $a['total']);

printf("\n  %-40s %7s %18s %12s\n", 'regla (severidad)', 'total', 'todo cortado WAF', 'algo paso');
foreach ($filas as $clave => $f) {
    printf("  %-40s %7d %18d %12d\n", substr($clave, 0, 40), $f['total'], $f['cortado'], $f['total'] - $f['cortado']);
}
printf("\n  %d alertas reales sin revisar en total\n\n", $alertas->count());
PHP
ejecutar_php /tmp/marketgt-reparto.php

# ─── 6 · Resultado ───────────────────────────────────────────────────────────
log "6 · Como queda el triangulo"

cat > /tmp/marketgt-triangulo.php <<'PHP'
<?php
use App\Services\Siem\CalculadoraMetricas as C;

$palabra = [C::CUMPLE => 'cumple', C::INCUMPLE => 'INCUMPLE', C::SIN_DATOS => 'sin datos'];

foreach (app(C::class)->calcular() as $v) {
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

        // El motivo solo cuando hay algo que arreglar.
        if ($m['estado'] !== C::CUMPLE) {
            foreach (explode("\n", wordwrap(trim((string) ($m['origen'] ?? '')), 84)) as $linea) {
                printf("        %s\n", $linea);
            }
        }
    }
}
echo "\n";
PHP
ejecutar_php /tmp/marketgt-triangulo.php

log "Lo que queda, y por que no tiene comando"
cat <<'TEXTO'
  1) PERSONAL CAPACITADO  ·  la otra metrica de PROTECCION

     https://marketgt.duckdns.org/siem/capacitacion
     Entra como administrador, marca una sesion como impartida y registra la
     asistencia de cada persona con rol de administrador o auditor.

     No se automatiza a proposito: si la asistencia se autodeclara, el control
     pierde su sentido. Que la registre una persona con rol ES el control.

  2) COBERTURA DE TRIAJE  ·  la metrica que hace incumplir a DETECCION

     https://marketgt.duckdns.org/siem/alertas
     La tabla del paso 5 dice que son. Las de trafico real solo las revisa una
     persona, desde la pantalla de acciones masivas al pie de esa pagina.
TEXTO
echo
echo "  Resultado en:  https://marketgt.duckdns.org/siem/metricas"
echo
