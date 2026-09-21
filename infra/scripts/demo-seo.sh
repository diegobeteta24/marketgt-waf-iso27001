#!/usr/bin/env bash
# =============================================================================
# MarketGT - DEMOSTRACION EN VIVO: ataques de SEO y su deteccion
# Presentacion del sabado 2026-09-26.  Duracion objetivo: 3 minutos.
#
# Uso:
#   ./demo-seo.sh https://marketgt.tudominio.com          # demo completa
#   ./demo-seo.sh https://marketgt.tudominio.com rapida   # solo los 6 tiros clave
#
# PREPARACION (hacer ANTES de entrar al aula, no delante de la clase):
#   Terminal IZQUIERDA : este guion
#   Terminal DERECHA   : ./infra/scripts/leer-audit-log.sh    (eventos del WAF)
#   Navegador          : el panel SIEM, filtrado por la etiqueta attack-seo
#
# COMPROBACION PREVIA OBLIGATORIA (si esto falla, la demo falla):
#   docker compose -f infra/docker/docker-compose.yml exec waf nginx -t
#   curl -s -o /dev/null -w '%{http_code}\n' "$BASE/"          -> 200
#   curl -s -o /dev/null -w '%{http_code}\n' "$BASE/?q=<script>alert(1)</script>"  -> 403
# =============================================================================
set -u

BASE="${1:-http://localhost}"
MODO="${2:-completa}"
UA_REAL="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/141.0 Safari/537.36"

verde()  { printf '\033[1;32m%s\033[0m' "$*"; }
rojo()   { printf '\033[1;31m%s\033[0m' "$*"; }
ambar()  { printf '\033[1;33m%s\033[0m' "$*"; }
titulo() { printf '\n\033[1;36m%s\033[0m\n' "==== $* ===="; }
nota()   { printf '   \033[2m%s\033[0m\n' "$*"; }

# Dispara y muestra el codigo. Verde = bloqueado (que es lo que queremos ver).
tiro() {
  local etiqueta="$1"; shift
  printf '  %-46s ' "$etiqueta"
  local code
  code=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 10 -A "$UA_REAL" "$@" 2>/dev/null)
  case "$code" in
    403) verde "HTTP 403  BLOQUEADO POR EL WAF" ;;
    200) rojo  "HTTP 200  PASO (revisar regla)" ;;
    *)   ambar "HTTP $code" ;;
  esac
  printf '\n'
}

# Igual, pero 200 es el resultado correcto (trafico legitimo).
tiro_ok() {
  local etiqueta="$1"; shift
  printf '  %-46s ' "$etiqueta"
  local code
  code=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 10 -A "$UA_REAL" "$@" 2>/dev/null)
  if [ "$code" = "200" ]; then verde "HTTP 200  PASA (correcto)"; else rojo "HTTP $code  FALSO POSITIVO"; fi
  printf '\n'
}

pausa() { [ "$MODO" = "completa" ] && read -r -p "   [Enter para continuar] " _ || sleep 1; }

clear
cat <<'CABECERA'
+---------------------------------------------------------------------------+
|  MarketGT - Ataques de SEO contra nuestro propio laboratorio              |
|  Universidad Mariano Galvez  ·  Seguridad y Auditoria de Sistemas         |
|                                                                           |
|  Todo lo que sigue se lanza CONTRA NUESTRA PROPIA INFRAESTRUCTURA.        |
|  Objetivo: ensenar como el WAF y la aplicacion los DETECTAN y BLOQUEAN.   |
+---------------------------------------------------------------------------+
CABECERA

# -----------------------------------------------------------------------------
titulo "0. LINEA BASE - el sitio funciona y no hay falsos positivos (0:00-0:20)"
# Esto es lo primero que hay que ensenar. Un WAF que bloquea todo no prueba nada.
nota "Si el WAF bloqueara trafico normal, la tienda no serviria para nada."
tiro_ok "Portada"                                "$BASE/"
tiro_ok "Busqueda legitima: 'camisa azul'"       "$BASE/buscar?q=camisa+azul"
tiro_ok "Resena legitima (sin enlaces)"          -X POST --data "resena=Muy buen producto, llego en dos dias." "$BASE/productos/1/resenas"
pausa

# -----------------------------------------------------------------------------
titulo "1. SEO SPAM INJECTION - le roban tu PageRank (0:20-0:50)"
nota "El atacante no quiere tu servidor: quiere tu reputacion en Google."
nota "Mete su enlace en una resena y espera a que Googlebot lo indexe desde TU dominio."
tiro "Enlace <a href> en una resena  -> 15030"  -X POST --data 'resena=Excelente <a href="http://farmacia-barata.ru/viagra">comprar aqui</a>' "$BASE/productos/1/resenas"
tiro "BBCode [url=] en un comentario -> 15030"  -X POST --data 'comentario=[url=http://replicas.top]replica rolex[/url]' "$BASE/productos/1/comentarios"
tiro "Pharma hack en la descripcion  -> 15031"  -X POST --data 'descripcion=buy cheap viagra online sin receta' "$BASE/productos/1"
tiro "Truco del espaciado: v i a g r a -> 15031" -X POST --data 'descripcion=b u y c h e a p v i a g r a' "$BASE/productos/1"
nota "El ultimo pasa por t:removeWhitespace: la regla quita los espacios antes de comparar."
tiro "Japanese keyword hack (CJK)     -> 15032"  -X POST --data 'descripcion=ブランドコピー時計 激安 通販' "$BASE/productos/1"
tiro "Enlace OCULTO con CSS           -> 15033"  -X POST --data 'comentario=<span style="display:none">casino online</span>' "$BASE/productos/1/comentarios"
nota "Texto que el cliente NO ve y Googlebot SI. Es la firma del 'cloaked links hack'."
pausa

# -----------------------------------------------------------------------------
titulo "2. CLOAKING Y GOOGLEBOT FALSIFICADO (0:50-1:20)"
nota "Cloaking = servir contenido distinto al buscador que al usuario."
nota "Primer paso del atacante: probar el sitio haciendose pasar por Googlebot."
tiro "UA 'Googlebot' desde MI IP      -> 15021"  -A "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)" "$BASE/"
nota "La regla 15021 compara la IP contra los 617 rangos oficiales de Google y Bing."
tiro "UA 'bingbot' desde MI IP        -> 15021"  -A "Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)" "$BASE/"
tiro "Forzar vista de bot: ?googlebot=1 -> 15023" "$BASE/productos?googlebot=1"
tiro "Cloaking AJAX: ?_escaped_fragment_ -> 15023" "$BASE/productos?_escaped_fragment_=1"

printf '\n  \033[1mVERIFICACION INVERSA (FCrDNS) - el metodo que documenta Google:\033[0m\n'
printf '  Googlebot REAL:\n'
printf '    $ host 66.249.66.1\n'
printf '      -> crawl-66-249-66-1.googlebot.com          '; verde '(PASO 1 ok: sufijo googlebot.com)'; printf '\n'
printf '    $ host crawl-66-249-66-1.googlebot.com\n'
printf '      -> 66.249.66.1                              '; verde '(PASO 2 ok: vuelve a la misma IP)'; printf '\n'
printf '  El de la demo:\n'
printf '    $ host <mi-ip>   -> PTR de un ISP cualquiera  '; rojo  '(PASO 1 falla: NO es googlebot.com)'; printf '\n'
nota "Sin el PASO 2, cualquiera que controle el PTR de su rango se hace pasar por Googlebot."
nota "Implementado en app/Services/Seguridad/VerificadorCrawler.php (evento APP-15021)."
pausa

# -----------------------------------------------------------------------------
titulo "3. OPEN REDIRECT - phishing con TU dominio de fachada (1:20-1:45)"
nota "La victima ve marketgt.gt y aterriza en otro sitio. Tambien crea doorway pages."
tiro "?url=https://sitio-malo.tld     -> 15040"  "$BASE/ir?url=https://sitio-malo.tld"
tiro "Protocolo relativo: //sitio-malo.tld"      "$BASE/ir?url=//sitio-malo.tld"
tiro "Contrabarra: /\\sitio-malo.tld"            "$BASE/ir?url=/%5Csitio-malo.tld"
tiro "Userinfo: marketgt.gt@sitio-malo.tld"      "$BASE/ir?url=https://marketgt.gt@sitio-malo.tld"
nota "El navegador va a sitio-malo.tld: lo de antes del @ es un usuario, no el host."
tiro "Doble codificacion: %252f%252f"            "$BASE/ir?url=%252f%252fsitio-malo.tld"
tiro "Esquema javascript:               -> 15041" "$BASE/ir?url=javascript:alert(document.domain)"
nota "CRS 931130 cubre parte de esto, pero es paranoia-level/2: aqui corre en PL1, solo DETECTA."
nota "La 15040 bloquea en PL1 y compara con el Host de la peticion: sirve en cualquier dominio."
pausa

# -----------------------------------------------------------------------------
titulo "4. ENVENENAMIENTO DE INDEXACION (1:45-2:15)"
tiro "POST sobre /robots.txt           -> 15050"  -X POST --data "contenido=User-agent: *%0ADisallow: /" "$BASE/robots.txt"
nota "Un 'Disallow: /' desindexa la tienda entera en dias. Nadie escribe robots.txt por HTTP."
tiro "PUT sobre /sitemap.xml           -> 15050"  -X PUT --data "<urlset/>" "$BASE/sitemap.xml"
tiro "Canonical secuestrado            -> 15034"  -X POST --data 'descripcion=<link rel="canonical" href="https://sitio-malo.tld/">' "$BASE/productos/1"
nota "El canonical inyectado le dice a Google: 'la version buena esta en MI dominio'."
nota "Te roba el posicionamiento sin tocar una letra visible de tu contenido."
tiro "meta robots noindex inyectado    -> 15034"  -X POST --data 'descripcion=<meta name="robots" content="noindex">' "$BASE/productos/1"
tiro "Search poisoning: <h1> en ?q=    -> 15060"  "$BASE/buscar?q=%3Ch1%3Ecomprar+viagra%3C%2Fh1%3E"
nota "La pagina de resultados reflejaria ese texto y Googlebot lo indexaria."
nota "Control definitivo: cabecera X-Robots-Tag: noindex en /buscar (middleware CabecerasSeguridad)."

printf '\n  \033[1mGET a robots.txt SIGUE funcionando (Googlebot tiene que poder leerlo):\033[0m\n'
tiro_ok "GET /robots.txt"                         "$BASE/robots.txt"

printf '\n  \033[1mVigilancia de integridad (lo que el WAF NO puede ver):\033[0m\n'
printf '    $ php artisan seo:vigilar\n'
nota "Si el atacante entra por FTP o por un contenedor, el cambio NO pasa por HTTP."
nota "El hash SHA-256 de robots.txt se compara cada 5 minutos -> MTTD de 5 min, no de dias."
pausa

# -----------------------------------------------------------------------------
titulo "5. NEGATIVE SEO: scraping y clonado de contenido (2:15-2:40)"
tiro "UA de HTTrack (clonador de sitios) -> 15120" -A "Mozilla/4.0 (compatible; HTTrack 3.0x; Windows 98)" "$BASE/productos"
tiro "UA de Scrapy                       -> 15120" -A "Scrapy/2.13 (+https://scrapy.org)" "$BASE/productos"
printf '\n  Scraping masivo del catalogo (50 peticiones sin sesion):\n'
BLOQ=0; PASO=0
for i in $(seq 1 50); do
  c=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 5 -A "$UA_REAL" "$BASE/productos/$i" 2>/dev/null)
  if [ "$c" = "403" ]; then BLOQ=$((BLOQ+1)); else PASO=$((PASO+1)); fi
done
printf '    pasaron: %s    ' "$PASO"; verde "bloqueadas: $BLOQ"; printf '  (regla 15111, umbral 40/60 s)\n'
nota "Duplicar tu catalogo en otro dominio te hace competir contra tu propio contenido."
nota "Refuerzo que NO depende de ModSecurity: limit_req de nginx + rate limit de Cloudflare."
pausa

# -----------------------------------------------------------------------------
titulo "6. LA OTRA MITAD: deteccion SALIENTE (2:40-3:00)"
nota "Todo lo anterior evita que el spam ENTRE. Pero, si ya entro por otra via?"
nota "La regla 15085 inspecciona el cuerpo de la RESPUESTA antes de enviarla."
nota "Si una pagina de MarketGT ya contiene spam farmaceutico, se corta ANTES de"
nota "que Googlebot la vea. Eso es el vertice de DETECCION del Triangulo."
printf '\n  Simulacion (con el spam ya en la base de datos):\n'
tiro "GET /productos/99 (producto envenenado) -> 15085" "$BASE/productos/99"

# -----------------------------------------------------------------------------
titulo "CIERRE - las evidencias"
cat <<'CIERRE'
  Evento del WAF en el audit log JSON:
    docker compose -f infra/docker/docker-compose.yml exec waf \
      sh -c "grep -c attack-seo /var/log/modsecurity/audit/audit.json"

  Desglose por regla (que regla salto y cuantas veces):
    docker compose -f infra/docker/docker-compose.yml exec waf sh -c \
      "cat /var/log/modsecurity/audit/audit.json" | \
      jq -r '.transaction.messages[]?.details | select(.ruleId|startswith("150")) | .ruleId+"  "+.msg' | sort | uniq -c | sort -rn

  Evento de la aplicacion (con el PTR del bot falso):
    docker compose -f infra/docker/docker-compose.yml exec app \
      tail -n 20 storage/logs/seguridad.json | jq -c 'select(.context.evento|startswith("SEO/"))'

  Panel SIEM: filtrar por la etiqueta  attack-seo
CIERRE

printf '\n'
verde "Demostracion terminada."
printf '\n\n'
