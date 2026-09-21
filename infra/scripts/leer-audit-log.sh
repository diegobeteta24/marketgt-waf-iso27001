#!/usr/bin/env bash
# =============================================================================
# MarketGT - Lectura y parseo del audit log JSON de ModSecurity 3
#
# ESTRUCTURA REAL del JSON de ModSecurity v3.0.16 (src/transaction.cc, toJSON):
#
# {
#   "transaction": {
#     "client_ip":      "203.0.113.9",
#     "time_stamp":     "Sat Sep 26 15:04:11 2026",
#     "server_id":      "<hash>",
#     "client_port":    54233,
#     "host_ip":        "172.19.0.3",
#     "host_port":      8080,
#     "unique_id":      "179...0.123456",      <-- ID UNICO DE TRANSACCION
#     "is_interrupted": true,                   <-- true = el WAF corto la peticion
#     "request":  { "method","http_version","hostname","uri","body","headers":{...} },
#     "response": { "http_code", "body", "headers":{...} },
#     "producer": { "modsecurity","connector","secrules_engine","components":[...] },
#     "messages": [                             <-- solo si la parte H esta activa
#       { "message": "SQL Injection Attack Detected via libinjection",
#         "details": { "match","reference","ruleId","file","lineNumber",
#                      "data","severity","ver","rev","tags":[...],
#                      "maturity","accuracy" } }
#     ]
#   }
# }
#
# OJO 1: "request.body" SOLO aparece si MODSEC_AUDIT_LOG_PARTS incluye la C.
#        El valor por defecto de la imagen (ABIJDEFHZ) NO la incluye.
# OJO 2: "messages" SOLO aparece si las partes incluyen la H.
# OJO 3: la puntuacion de anomalia NO es un campo propio: viene dentro del texto
#        de los mensajes de las reglas 949110 y 980170.
# =============================================================================
set -u
LOG="${1:-/var/log/modsecurity/audit/audit.json}"
IN_DOCKER="docker exec marketgt-waf cat /var/log/modsecurity/audit/audit.json"

leer() {
  if [ -r "$LOG" ]; then cat "$LOG"; else $IN_DOCKER; fi
}

case "${2:-tabla}" in

# --- 1. Tabla resumen: lo que se proyecta en la pantalla durante la demo -----
tabla)
  leer | jq -r '
    .transaction as $t
    | ($t.messages // []) as $m
    | [ $t.time_stamp,
        $t.client_ip,
        $t.request.method,
        ($t.request.uri | .[0:45]),
        ($t.response.http_code | tostring),
        (if $t.is_interrupted then "BLOQUEADO" else "detectado" end),
        ([$m[].details.ruleId] | join(",")),
        ($m[-1].message // "-")
      ] | @tsv' |
  column -t -s $'\t'
  ;;

# --- 2. Ultimo bloqueo, completo: regla, mensaje, dato, score, id -----------
ultimo)
  leer | jq -s 'map(select(.transaction.is_interrupted == true)) | last |
    {
      id_transaccion: .transaction.unique_id,
      hora:           .transaction.time_stamp,
      ip_origen:      .transaction.client_ip,
      peticion:       (.transaction.request.method + " " + .transaction.request.uri),
      cuerpo:         .transaction.request.body,
      respuesta:      .transaction.response.http_code,
      reglas: [ .transaction.messages[] | {
        id:        .details.ruleId,
        mensaje:   .message,
        casa_con:  .details.data,
        severidad: .details.severity,
        fichero:   (.details.file + ":" + .details.lineNumber),
        version:   .details.ver,
        etiquetas: .details.tags
      } ],
      puntuacion_anomalia: ( [ .transaction.messages[]
                               | select(.details.ruleId == "949110" or .details.ruleId == "15091")
                               | .message ] )
    }'
  ;;

# --- 3. Puntuacion de anomalia y desglose por categoria ---------------------
# 949110 -> "Inbound Anomaly Score Exceeded (Total Score: 10)"   SI esta en el JSON
# 15091  -> desglose completo por categoria                      regla propia, SI esta
# 980170 -> desglose oficial de CRS                              NO esta en el JSON:
#           lleva `noauditlog` y solo sale por el error log de nginx. Para verlo:
#              docker compose logs waf | grep "Anomaly Scores"
score)
  leer | jq -r '.transaction as $t | ($t.messages // [])[]
    | select(.details.ruleId == "949110" or .details.ruleId == "15091" or .details.ruleId == "959100")
    | "\($t.unique_id)  [\(.details.ruleId)]  \(.message)"'
  ;;

# --- 4. Contador de reglas disparadas: alimenta las metricas del triangulo --
metricas)
  leer | jq -r '.transaction.messages[]?.details.ruleId' \
    | sort | uniq -c | sort -rn \
    | awk 'BEGIN{printf "%-8s %-10s\n","VECES","REGLA"} {printf "%-8s %-10s\n",$1,$2}'
  ;;

# --- 5. Streaming en vivo para el panel SIEM (una linea JSON por evento) ----
stream)
  tail -n0 -F "$LOG" 2>/dev/null | jq -c --unbuffered '{
      ts:      .transaction.time_stamp,
      id:      .transaction.unique_id,
      ip:      .transaction.client_ip,
      metodo:  .transaction.request.method,
      uri:     .transaction.request.uri,
      codigo:  .transaction.response.http_code,
      bloqueado: .transaction.is_interrupted,
      reglas:  [ .transaction.messages[]? | {id: .details.ruleId, msg: .message, dato: .details.data} ],
      etiquetas: ([ .transaction.messages[]?.details.tags[]? ] | unique)
    }'
  ;;
esac

# -----------------------------------------------------------------------------
# ALTERNATIVA sin fichero: la imagen escribe el audit log en /dev/stdout por
# defecto (MODSEC_AUDIT_LOG=/dev/stdout). Si prefieres esa via:
#
#   docker compose logs -f --no-log-prefix waf | grep '^{' | jq -c '...'
#
# Ventaja: cero problemas de permisos.
# Desventaja: se mezcla con el log de errores de nginx y hay que filtrar por
# la llave '{'. Para el panel SIEM es mas limpio el fichero.
# -----------------------------------------------------------------------------
