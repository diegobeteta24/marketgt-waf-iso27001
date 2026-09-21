#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
#  MarketGT · Prueba de humo de las rutas autenticadas
#
#  Inicia sesión con una cuenta de demostración y recorre todas las rutas
#  protegidas, comprobando que ninguna devuelve una página de error.
#
#  Comprobar el código HTTP no basta: Laravel devuelve 200 en la página de
#  acceso, de modo que una sesión que no se estableció daría todo en verde.
#  Por eso se verifica además que la respuesta contenga contenido propio de
#  la página y no el formulario de acceso.
#
#  Uso:  bash infra/scripts/probar-rutas.sh [base] [correo] [contraseña]
# ─────────────────────────────────────────────────────────────────────────────
set -uo pipefail

BASE="${1:-http://127.0.0.1:8000}"
CORREO="${2:-admin@marketgt.test}"
CLAVE="${3:-Admin#MarketGT2026}"

GALLETAS="$(mktemp)"
CUERPO="$(mktemp)"
trap 'rm -f "$GALLETAS" "$CUERPO"' EXIT

verde() { printf '  \033[1;32m✓\033[0m %s\n' "$*"; }
rojo()  { printf '  \033[1;31m✗\033[0m %s\n' "$*"; }
info()  { printf '\n\033[1;34m▸ %s\033[0m\n' "$*"; }

info "Iniciando sesión como ${CORREO}"

# El token de la sesión viaja en la etiqueta meta del encabezado.
curl -s -c "$GALLETAS" "$BASE/login" -o "$CUERPO"
TOKEN="$(sed -n 's/.*name="csrf-token" content="\([^"]*\)".*/\1/p' "$CUERPO" | head -1)"
if [ -z "$TOKEN" ]; then
  TOKEN="$(sed -n 's/.*name="_token"[^>]*value="\([^"]*\)".*/\1/p' "$CUERPO" | head -1)"
fi

if [ -z "$TOKEN" ]; then
  rojo "No se pudo obtener el token de la sesión"
  exit 1
fi
verde "Token obtenido"

CODIGO="$(curl -s -b "$GALLETAS" -c "$GALLETAS" -o "$CUERPO" -w '%{http_code}' \
  -X POST "$BASE/login" \
  --data-urlencode "_token=${TOKEN}" \
  --data-urlencode "email=${CORREO}" \
  --data-urlencode "password=${CLAVE}")"

if [ "$CODIGO" = "302" ] || [ "$CODIGO" = "200" ]; then
  verde "Acceso aceptado (HTTP ${CODIGO})"
else
  rojo "Acceso rechazado (HTTP ${CODIGO})"
  exit 1
fi

info "Recorriendo las rutas protegidas"

# Cada entrada es «ruta|resultado esperado». La pantalla que administra los
# factores de seguridad exige reautenticación con contraseña antes de permitir
# cambiarlos, de modo que su redirección NO es un fallo: es el control que pide
# el anexo de autenticación. Una sesión robada no debe bastar para desactivar
# el segundo factor de la cuenta.
RUTAS=(
  "/dashboard|200"
  "/tienda|200"
  "/siem/tablero|200"
  "/siem/alertas|200"
  "/siem/eventos|200"
  "/siem/metricas|200"
  "/seo/incidentes|200"
  "/seo/integridad|200"
  "/seo/laboratorio|200"
  "/settings/security|302"
)

FALLOS=0
for entrada in "${RUTAS[@]}"; do
  ruta="${entrada%%|*}"
  esperado="${entrada##*|}"

  CODIGO="$(curl -s -b "$GALLETAS" -c "$GALLETAS" -o "$CUERPO" -w '%{http_code}' \
            --max-time 30 "${BASE}${ruta}")"

  PROBLEMA=""
  NOTA=""

  # Una traza de excepción de Laravel o de Livewire
  if grep -qiE 'Whoops|Exception</|Multiple root elements|does not exist|Undefined (variable|method)' "$CUERPO"; then
    PROBLEMA="traza de error en la respuesta"
  # La sesión se perdió y devolvió el formulario de acceso
  elif [ "$esperado" = "200" ] && grep -qi 'Log in to your account' "$CUERPO"; then
    PROBLEMA="devolvió el formulario de acceso: la sesión no se conservó"
  elif [ "$CODIGO" != "$esperado" ]; then
    PROBLEMA="HTTP ${CODIGO}, se esperaba ${esperado}"
  elif [ "$esperado" = "302" ]; then
    NOTA=" · reautenticación exigida, correcto"
  fi

  TAM="$(wc -c < "$CUERPO" | tr -d ' ')"

  if [ -n "$PROBLEMA" ]; then
    rojo "$(printf '%-22s %s' "$ruta" "$PROBLEMA")"
    FALLOS=$((FALLOS + 1))
  else
    verde "$(printf '%-22s HTTP %s · %s bytes%s' "$ruta" "$CODIGO" "$TAM" "$NOTA")"
  fi
done

echo
if [ "$FALLOS" -eq 0 ]; then
  printf '\033[1;32m%s\033[0m\n' "Las ${#RUTAS[@]} rutas responden correctamente."
else
  printf '\033[1;31m%s\033[0m\n' "${FALLOS} de ${#RUTAS[@]} rutas con problemas."
  exit 1
fi
