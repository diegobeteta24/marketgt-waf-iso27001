#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
#  MarketGT · Diagnóstico de la hoja de estilos del panel de monitoreo
#
#  Comprueba si las variables de color que usan la gráfica y el triángulo
#  llegan de verdad al documento. Cuando una variable de CSS no está definida,
#  el navegador no avisa: descarta la declaración y aplica el valor inicial de
#  la propiedad, que para el relleno de un elemento SVG es NEGRO. El resultado
#  es una gráfica de barras negras sobre fondo negro, sin ningún error visible
#  en consola ni en los registros.
#
#  Uso:  bash infra/scripts/diagnosticar-estilos.sh [base]
# ─────────────────────────────────────────────────────────────────────────────
set -uo pipefail

BASE="${1:-http://127.0.0.1:8000}"
CORREO="${2:-admin@marketgt.test}"
CLAVE="${3:-Admin#MarketGT2026}"

GALLETAS="$(mktemp)"
PAGINA="$(mktemp)"
trap 'rm -f "$GALLETAS" "$PAGINA"' EXIT

printf '\n\033[1;34m▸ Iniciando sesión\033[0m\n'
curl -s -c "$GALLETAS" "$BASE/login" -o "$PAGINA"
TOKEN="$(sed -n 's/.*name="csrf-token" content="\([^"]*\)".*/\1/p' "$PAGINA" | head -1)"
curl -s -b "$GALLETAS" -c "$GALLETAS" -o /dev/null \
  -X POST "$BASE/login" \
  --data-urlencode "_token=${TOKEN}" \
  --data-urlencode "email=${CORREO}" \
  --data-urlencode "password=${CLAVE}"

printf '\033[1;34m▸ Descargando el tablero\033[0m\n'
curl -s -b "$GALLETAS" "$BASE/siem/tablero" -o "$PAGINA"
echo "  tamaño de la respuesta: $(wc -c < "$PAGINA") bytes"

comprobar() {
  local etiqueta="$1" patron="$2"
  local n
  n="$(grep -cF "$patron" "$PAGINA" 2>/dev/null || echo 0)"
  if [ "$n" -gt 0 ]; then
    printf '  \033[1;32m✓\033[0m %-42s (%s)\n' "$etiqueta" "$n"
  else
    printf '  \033[1;31m✗\033[0m %-42s AUSENTE\n' "$etiqueta"
  fi
}

printf '\n\033[1;34m▸ ¿Llega la hoja de estilos del panel?\033[0m\n'
comprobar 'contenedor .panel-siem'            'panel-siem'
comprobar 'definición --siem-serie-bloqueados' '--siem-serie-bloqueados'
comprobar 'definición --siem-eje'              '--siem-eje'
comprobar 'definición --siem-contorno'         '--siem-contorno'

printf '\n\033[1;34m▸ ¿Qué usa el dibujo para pintarse?\033[0m\n'
comprobar 'referencias a var(--siem-'          'var(--siem-'

printf '\n\033[1;34m▸ Muestra de las definiciones encontradas\033[0m\n'
grep -oE '\-\-siem-[a-z-]+:[^;]{1,28}' "$PAGINA" | sort -u | head -12 | sed 's/^/    /'

printf '\n\033[1;34m▸ Muestra de cómo se pintan las barras\033[0m\n'
grep -oE '<rect[^>]{0,120}' "$PAGINA" | head -3 | sed 's/^/    /'

echo
