#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
#  MarketGT · Actualización del registro DNS dinámico
#
#  Apunta el nombre del proyecto hacia la dirección pública de este servidor
#  y deja programada una comprobación periódica, de modo que si el proveedor
#  cambiara la dirección, el nombre la sigue en pocos minutos.
#
#  El token NO se escribe en este archivo ni en ningún otro versionado:
#  se lee del archivo de secretos, que está excluido del repositorio.
#
#  Preparación (una sola vez, en el servidor):
#     sudo mkdir -p /etc/marketgt
#     echo 'DUCKDNS_SUBDOMINIO=marketgt'        | sudo tee  /etc/marketgt/dns.env
#     echo 'DUCKDNS_TOKEN=<el-token-nuevo>'     | sudo tee -a /etc/marketgt/dns.env
#     sudo chmod 600 /etc/marketgt/dns.env
#
#  Uso:   sudo bash infra/scripts/05-actualizar-dns.sh
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

log()  { printf '\n\033[1;34m▸ %s\033[0m\n' "$*"; }
ok()   { printf '  \033[1;32m✓\033[0m %s\n' "$*"; }
warn() { printf '  \033[1;33m!\033[0m %s\n' "$*"; }
die()  { printf '\n\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Ejecutar con sudo."

SECRETOS="/etc/marketgt/dns.env"
[ -f "${SECRETOS}" ] || die "Falta ${SECRETOS}. Creá el archivo como indica la cabecera de este script."

# shellcheck disable=SC1090
set -a; . "${SECRETOS}"; set +a

: "${DUCKDNS_SUBDOMINIO:?falta DUCKDNS_SUBDOMINIO en ${SECRETOS}}"
: "${DUCKDNS_TOKEN:?falta DUCKDNS_TOKEN en ${SECRETOS}}"

DOMINIO="${DUCKDNS_SUBDOMINIO}.duckdns.org"

# ─── Dirección pública real de este servidor ─────────────────────────────────
# Se consulta a un servicio externo en vez de leerla de la interfaz de red,
# porque en la nube la interfaz suele tener una dirección privada y la pública
# la aplica la capa de red del proveedor.
log "Averiguando la dirección pública de este servidor"
IP=""
for servicio in "https://api.ipify.org" "https://ifconfig.me/ip" "https://icanhazip.com"; do
  IP="$(curl -fsS --max-time 10 "${servicio}" 2>/dev/null | tr -d '[:space:]')" || true
  [[ "${IP}" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] && break
  IP=""
done
[ -n "${IP}" ] || die "No se pudo determinar la dirección pública."
ok "Dirección pública: ${IP}"

# ─── Actualización ───────────────────────────────────────────────────────────
log "Actualizando ${DOMINIO}"
RESPUESTA="$(curl -fsS --max-time 20 \
  "https://www.duckdns.org/update?domains=${DUCKDNS_SUBDOMINIO}&token=${DUCKDNS_TOKEN}&ip=${IP}" \
  2>/dev/null || echo 'ERROR')"

case "${RESPUESTA}" in
  OK)  ok "Registro actualizado: ${DOMINIO} → ${IP}" ;;
  KO)  die "El proveedor rechazó la petición. Revisá el token y el subdominio en ${SECRETOS}." ;;
  *)   die "Respuesta inesperada del proveedor: '${RESPUESTA}'" ;;
esac

# ─── Comprobación ────────────────────────────────────────────────────────────
# La propagación es casi inmediata, pero el resolutor local puede tener el
# valor anterior en memoria, así que se consulta a un resolutor público.
log "Comprobando la resolución"
sleep 3
RESUELTO="$(dig +short "${DOMINIO}" @1.1.1.1 2>/dev/null | tail -1)"
if [ "${RESUELTO}" = "${IP}" ]; then
  ok "${DOMINIO} resuelve correctamente hacia ${IP}"
else
  warn "Resuelve hacia '${RESUELTO:-nada}' en vez de ${IP}."
  warn "Suele ser caché; volvé a comprobar en un par de minutos con:"
  warn "   dig +short ${DOMINIO} @1.1.1.1"
fi

# ─── Comprobación periódica ──────────────────────────────────────────────────
log "Programando la comprobación periódica"
cat > /etc/cron.d/marketgt-dns <<EOF
# Mantiene el nombre apuntando a la dirección real del servidor.
# Si el proveedor reasignara la dirección tras un reinicio, el nombre la sigue.
*/10 * * * * root bash $(readlink -f "${BASH_SOURCE[0]}") >/var/log/marketgt-dns.log 2>&1
EOF
chmod 644 /etc/cron.d/marketgt-dns
ok "Comprobación programada cada diez minutos"

cat <<RESUMEN

  Nombre del sistema:  https://${DOMINIO}

  SIGUIENTE PASO — emitir el certificado y desplegar:
     sudo bash infra/scripts/04-desplegar.sh ${DOMINIO}

RESUMEN
