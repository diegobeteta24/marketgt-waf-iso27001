#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
#  MarketGT · Compilación de los recursos de interfaz
#
#  POR QUÉ ESTE SCRIPT EXISTE, y no basta con ejecutar npm run build.
#
#  El compilador de estilos recorre las plantillas para saber qué clases tiene
#  que generar, y reutiliza el resultado anterior cuando cree que nada cambió.
#  Esa caché no siempre acierta: una compilación devolvió en seiscientos
#  milisegundos una hoja a la que le faltaba el ochenta y ocho por ciento de
#  las utilidades, porque reutilizó un resultado anterior a las correcciones.
#
#  El fallo es traicionero porque no emite ningún error: la compilación informa
#  éxito, la hoja pesa un cuarto de megabyte, y aun así el sitio aparece sin
#  formato. Una compilación completa recorre las setenta y una plantillas y
#  tarda cerca de un minuto; si termina en menos de cinco segundos, reutilizó
#  la caché y el resultado no es de fiar.
#
#  Por eso el script descarta la caché antes de compilar y COMPRUEBA que la
#  hoja contenga utilidades que solo pueden venir de las plantillas propias.
#  Si no las encuentra, se detiene y se niega a entregar el resultado, en vez
#  de dejar que se publique una hoja incompleta.
#
#  Uso:  bash infra/scripts/compilar-estilos.sh
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

log()  { printf '\n\033[1;34m▸ %s\033[0m\n' "$*"; }
ok()   { printf '  \033[1;32m✓\033[0m %s\n' "$*"; }
warn() { printf '  \033[1;33m!\033[0m %s\n' "$*"; }
die()  { printf '\n\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

ORIGEN="${ORIGEN:-/mnt/e/Seguridad/app}"

[ -d "${ORIGEN}" ] || die "No existe el origen: ${ORIGEN}"

cd "${ORIGEN}"

if [ ! -d node_modules ]; then
  log "Instalando dependencias de interfaz"
  npm ci --no-audit --no-fund >/dev/null 2>&1
  ok "Dependencias instaladas"
fi

# La caché se descarta siempre antes de compilar. Un intento anterior de
# compilar sobre una copia en otro disco devolvió en seiscientos milisegundos
# una hoja a la que le faltaba el ochenta y ocho por ciento de las utilidades:
# había reutilizado un resultado anterior a las correcciones sin avisar de
# ello. Compilar de cero tarda cerca de un minuto y es lo que corresponde
# cuando el resultado se va a publicar.
log "Descartando la caché de compilación"
rm -rf node_modules/.vite public/build
ok "Caché descartada"

log "Compilando (tarda cerca de un minuto: recorre las 71 plantillas)"
npm run build 2>&1 | tail -6

HOJA="$(ls -1 public/build/assets/app-*.css 2>/dev/null | head -1)"
[ -n "${HOJA}" ] || die "No se generó ninguna hoja de estilos"

TAMANO="$(du -h "${HOJA}" | cut -f1)"
log "Verificando que las clases del proyecto estén presentes"
echo "  archivo: $(basename "${HOJA}")  (${TAMANO})"

# Se comprueban UTILIDADES DE TAILWIND, no clases escritas a mano.
#
# Aquí se comprobaban nombres como panel-siem, que son clases propias definidas
# en los archivos de estilos del proyecto. Tailwind no las genera nunca, de modo
# que su ausencia no demostraba nada y el script daba por rota una hoja que
# estaba perfectamente bien.
#
# La comprobación se hace con Node y no con las herramientas de texto del
# intérprete de órdenes. El motivo no es preferencia: en la hoja las clases
# viajan escapadas —sm:grid-cols-2 se escribe .sm\:grid-cols-2— y la barra
# invertida atraviesa tantas capas de interpretación entre las comillas del
# intérprete, la expansión de variables y la sintaxis de expresiones regulares
# que el mismo patrón daba resultados contradictorios en dos ejecuciones
# consecutivas. Node lee el archivo y compara cadenas, sin intermediarios.
FALLOS=0
RESULTADO="$(node -e '
const fs = require("fs");
const css = fs.readFileSync(process.argv[1], "utf8");

// Utilidades que solo pueden provenir del rastreo de las plantillas del
// proyecto: no las trae ni la base de Tailwind ni la biblioteca de componentes.
const esperadas = ["sm\\:grid-cols-2", "sm\\:grid-cols-3", "min-h-11"];
const faltan = esperadas.filter(c => !css.includes("." + c) && !css.includes(c));

// Recuento global de variantes adaptables. Si el rastreo no hubiera leído las
// plantillas, este número rondaría la decena en lugar de superar el centenar.
const distintas = new Set(css.match(/\.(sm|md|lg|xl)\\:[a-zA-Z0-9_\\-]+/g) || []).size;

console.log(JSON.stringify({ faltan, distintas }));
' "${HOJA}")"

FALTAN="$(printf '%s' "${RESULTADO}" | sed -n 's/.*"faltan":\[\([^]]*\)\].*/\1/p')"
DISTINTAS="$(printf '%s' "${RESULTADO}" | sed -n 's/.*"distintas":\([0-9]*\).*/\1/p')"

if [ -n "${FALTAN}" ]; then
  printf '  \033[1;31m✗ faltan utilidades: %s\033[0m\n' "${FALTAN}"
  FALLOS=$((FALLOS + 1))
else
  ok "Las utilidades del proyecto están presentes"
fi

echo "  utilidades adaptables: ${DISTINTAS:-0} distintas"
if [ "${DISTINTAS:-0}" -lt 30 ]; then
  warn "Muy pocas utilidades adaptables: el rastreo pudo no leer las plantillas"
  FALLOS=$((FALLOS + 1))
fi

if [ "${FALLOS}" -gt 0 ]; then
  die "La hoja de estilos NO contiene las clases del proyecto. No la despliegues."
fi
ok "La hoja contiene las clases del proyecto"

ok "Recursos compilados en ${ORIGEN}/public/build"

cat <<RESUMEN

  Ya podés versionar el resultado y desplegar:
     git add app/public/build && git commit && git push

RESUMEN
