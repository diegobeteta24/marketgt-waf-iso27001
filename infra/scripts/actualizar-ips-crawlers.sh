#!/usr/bin/env bash
# =============================================================================
# MarketGT - Regenera la lista de IP de crawlers legitimos
#
# Uso:   ./infra/scripts/actualizar-ips-crawlers.sh
# Salida: infra/modsecurity/marketgt-good-bots.data
#
# EJECUTAR: antes de la presentacion, y despues cada semestre.
# Google anade rangos nuevos sin avisar. Si la lista se queda vieja, la regla
# 15021 devuelve 403 al Googlebot REAL y el sitio se desindexa solo. El falso
# positivo de esta regla cuesta mas caro que el ataque que previene.
# =============================================================================
set -euo pipefail

DESTINO="$(cd "$(dirname "$0")/../modsecurity" && pwd)/marketgt-good-bots.data"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "Descargando listas oficiales..."
curl -fsS --max-time 30 https://developers.google.com/static/crawling/ipranges/common-crawlers.json  -o "$TMP/g1.json"
curl -fsS --max-time 30 https://developers.google.com/static/crawling/ipranges/special-crawlers.json -o "$TMP/g2.json"
curl -fsS --max-time 30 https://www.bing.com/toolbox/bingbot.json                                    -o "$TMP/b.json"

# Sanidad: si Google cambia la ruta, el fichero sera un HTML de error. No
# sobreescribimos una lista buena con basura.
for f in "$TMP/g1.json" "$TMP/g2.json" "$TMP/b.json"; do
  if ! grep -q 'Prefix' "$f"; then
    echo "ERROR: $f no contiene prefijos. La URL cambio. NO se toca la lista actual." >&2
    exit 1
  fi
done

extraer() { grep -o '"ipv[46]Prefix": *"[^"]*"' "$1" | sed 's/.*: *"//;s/"//' | sort -u; }

{
  echo "# ============================================================================="
  echo "# MarketGT - Rangos IP de crawlers legitimos (buscadores)"
  echo "# Consumido por @ipMatchFromFile en la regla 15020 de"
  echo "# infra/modsecurity/REQUEST-945-MARKETGT-SEO.conf"
  echo "# Generado: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "# Fuentes oficiales:"
  echo "#   https://developers.google.com/static/crawling/ipranges/common-crawlers.json"
  echo "#   https://developers.google.com/static/crawling/ipranges/special-crawlers.json"
  echo "#   https://www.bing.com/toolbox/bingbot.json"
  echo "# NO EDITAR A MANO. Regenerar con infra/scripts/actualizar-ips-crawlers.sh"
  echo "# ============================================================================="
  echo "# --- Google: common-crawlers (Googlebot) ---"
  extraer "$TMP/g1.json"
  echo "# --- Google: special-crawlers (Googlebot-Image/News/Video, Google-Safety) ---"
  extraer "$TMP/g2.json"
  echo "# --- Microsoft: Bingbot ---"
  extraer "$TMP/b.json"
} > "$TMP/nuevo.data"

TOTAL=$(grep -vc '^#' "$TMP/nuevo.data" || true)
if [ "$TOTAL" -lt 100 ]; then
  echo "ERROR: solo $TOTAL rangos. Esperabamos varios cientos. NO se sobreescribe." >&2
  exit 1
fi

mv "$TMP/nuevo.data" "$DESTINO"
echo "OK  $DESTINO  ->  $TOTAL rangos CIDR"
echo
echo "Aplicar sin reiniciar la tienda:"
echo "  docker compose -f infra/docker/docker-compose.yml exec waf nginx -t"
echo "  docker compose -f infra/docker/docker-compose.yml exec waf nginx -s reload"
