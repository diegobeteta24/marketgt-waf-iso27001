#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
#  MarketGT · Aprovisionamiento de la máquina virtual en Azure for Students
#
#  Se ejecuta en Azure Cloud Shell, que ya trae la herramienta instalada y
#  autenticada. No hace falta instalar nada en el equipo del estudiante.
#
#  Abrir Cloud Shell:  el icono  >_  de la barra superior del portal de Azure,
#  o directamente en  https://shell.azure.com  (elegir Bash).
#
#  Uso:   bash 03-crear-vm-azure.sh
#
#  SOBRE EL COSTO: la suscripción Azure for Students trae un límite de gasto
#  que NO se puede quitar sin agregar una tarjeta. Cuando el crédito se agota,
#  Azure deshabilita la suscripción en lugar de cobrar. El riesgo financiero
#  es nulo mientras nadie agregue un medio de pago.
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

log()  { printf '\n\033[1;34m▸ %s\033[0m\n' "$*"; }
ok()   { printf '  \033[1;32m✓\033[0m %s\n' "$*"; }
warn() { printf '  \033[1;33m!\033[0m %s\n' "$*"; }

# ─── Parámetros ──────────────────────────────────────────────────────────────
GRUPO="${GRUPO:-marketgt-rg}"
NOMBRE="${NOMBRE:-marketgt}"
USUARIO="${USUARIO:-azureuser}"

# Las suscripciones de estudiante traen una política de Azure que restringe en
# qué regiones pueden desplegarse recursos. Intentar cualquier otra devuelve
# «RequestDisallowedByAzure» sobre TODOS los recursos de la plantilla, con un
# mensaje que no dice cuáles sí están permitidas.
#
# Para consultar la lista de la propia suscripción:
#   az policy assignment list --query "[].parameters" -o json
#
# En la suscripción de este proyecto la política permite únicamente:
#   mexicocentral · francecentral · norwayeast · canadacentral · westus
#
# Se elige México Central por ser la más cercana a Guatemala. La latencia no
# afecta a la evaluación, pero sí a la fluidez de la demostración en vivo.
REGION="${REGION:-mexicocentral}"

# Dos núcleos y 4 GB. La cuota de una suscripción de estudiante ronda los
# cuatro núcleos y NO admite ampliación: no existe un proceso oficial para
# pedirla. Por eso se elige el tamaño que entra con margen en vez del más
# grande que cabría justo.
TAMANO="${TAMANO:-Standard_B2als_v2}"
IMAGEN="${IMAGEN:-Ubuntu2404}"
DISCO="${DISCO:-64}"

command -v az >/dev/null 2>&1 || {
  echo "Este script va en Azure Cloud Shell: https://shell.azure.com" >&2
  exit 1
}

SUS="$(az account show --query name -o tsv 2>/dev/null || echo '')"
log "Suscripción: ${SUS:-desconocida}"
log "Región: ${REGION}   ·   Tamaño: ${TAMANO}"

# ─── Política de regiones ────────────────────────────────────────────────────
# Se comprueba ANTES de crear nada. Cuando la región no está permitida, Azure
# rechaza todos los recursos de la plantilla con un mensaje que describe la
# política pero no revela cuáles son las regiones válidas, de modo que el
# diagnóstico degenera en prueba y error.
log "Comprobando las regiones permitidas por la política"
PERMITIDAS="$(az policy assignment list \
  --query "[].parameters.listOfAllowedLocations.value[]" -o tsv 2>/dev/null \
  | tr '\n' ' ' || true)"

if [ -n "${PERMITIDAS}" ]; then
  ok "Permitidas: ${PERMITIDAS}"
  if ! printf ' %s ' "${PERMITIDAS}" | grep -q " ${REGION} "; then
    PRIMERA="$(printf '%s' "${PERMITIDAS}" | awk '{print $1}')"
    warn "La región ${REGION} no está permitida en esta suscripción."
    warn "Volvé a ejecutar indicando una de las permitidas, por ejemplo:"
    warn "   REGION=${PRIMERA} bash $0"
    exit 1
  fi
else
  warn "No se pudo leer la política de regiones; continuamos"
fi

# ─── Cuota ───────────────────────────────────────────────────────────────────
# Descubrir que no hay cuota DESPUÉS de crear media infraestructura cuesta
# tiempo que no sobra. Se comprueba también por adelantado.
log "Comprobando la cuota de núcleos disponible"
CUOTA="$(az vm list-usage --location "${REGION}" \
  --query "[?contains(localName,'Total Regional')].{limite:limit,uso:currentValue}" -o tsv 2>/dev/null | head -1 || echo '')"
if [ -n "${CUOTA}" ]; then
  ok "Núcleos usados/límite en la región: ${CUOTA}"
else
  warn "No se pudo leer la cuota; continuamos"
fi

# ─── Grupo de recursos ───────────────────────────────────────────────────────
# Todo se crea dentro de un mismo grupo para poder borrarlo entero con un solo
# comando cuando termine el curso, sin dejar recursos consumiendo crédito.
log "Creando el grupo de recursos"
az group create --name "${GRUPO}" --location "${REGION}" --output none
ok "Grupo ${GRUPO} listo"

# ─── Máquina virtual ─────────────────────────────────────────────────────────
log "Creando la máquina virtual (tarda unos 2 o 3 minutos)"
if az vm show -g "${GRUPO}" -n "${NOMBRE}" >/dev/null 2>&1; then
  ok "La máquina ya existía"
else
  az vm create \
    --resource-group "${GRUPO}" \
    --name "${NOMBRE}" \
    --image "${IMAGEN}" \
    --size "${TAMANO}" \
    --admin-username "${USUARIO}" \
    --generate-ssh-keys \
    --os-disk-size-gb "${DISCO}" \
    --public-ip-sku Standard \
    --nsg-rule SSH \
    --output none
  ok "Máquina creada"
fi

# ─── Dirección pública estática ──────────────────────────────────────────────
# Sin fijarla, la dirección cambia al detener e iniciar la máquina, y el
# registro de dominio quedaría apuntando al vacío justo el día de la entrega.
log "Fijando la dirección pública"
IP_NOMBRE="$(az network public-ip list -g "${GRUPO}" --query "[0].name" -o tsv)"
az network public-ip update -g "${GRUPO}" -n "${IP_NOMBRE}" \
  --allocation-method Static --output none 2>/dev/null || warn "No se pudo fijar la dirección"
ok "Dirección fijada como estática"

# ─── Puertos ─────────────────────────────────────────────────────────────────
# Primera de las dos capas de cortafuegos. La segunda es UFW dentro de la
# máquina, que aplica el script de endurecimiento. Ambas son necesarias:
# esta filtra en la red de Azure, antes de que el paquete llegue al servidor.
log "Abriendo los puertos 80 y 443"
NSG="$(az network nsg list -g "${GRUPO}" --query "[0].name" -o tsv)"
az network nsg rule create -g "${GRUPO}" --nsg-name "${NSG}" \
  --name permitir-web --priority 1001 \
  --direction Inbound --access Allow --protocol Tcp \
  --source-address-prefixes '*' --destination-port-ranges 80 443 \
  --description "Trafico HTTP y HTTPS hacia el WAF de MarketGT" \
  --output none 2>/dev/null || ok "La regla ya existía"
ok "Puertos 80 y 443 abiertos"

IP="$(az vm show -d -g "${GRUPO}" -n "${NOMBRE}" --query publicIps -o tsv)"

cat <<RESUMEN

═══════════════════════════════════════════════════════════════════
  MÁQUINA VIRTUAL LISTA
═══════════════════════════════════════════════════════════════════

  Nombre              ${NOMBRE}
  Grupo               ${GRUPO}
  Región              ${REGION}
  Tamaño              ${TAMANO}  (2 núcleos · 4 GB)
  Dirección pública   ${IP}

  ─── PASO 1 · Apuntar el dominio ───────────────────────────────
  Entrá a https://www.duckdns.org y poné ${IP} en el campo
  de dirección de marketgt, o abrí esta URL reemplazando el token:

    https://www.duckdns.org/update?domains=marketgt&token=TU_TOKEN&ip=${IP}

  Tiene que responder la palabra  OK

  ─── PASO 2 · Entrar al servidor ───────────────────────────────
    ssh ${USUARIO}@${IP}

  ─── PASO 3 · Desplegar ────────────────────────────────────────
    sudo apt-get update && sudo apt-get install -y git
    git clone https://github.com/diegobeteta24/marketgt-waf-iso27001.git /opt/marketgt
    cd /opt/marketgt
    sudo bash infra/scripts/02-hardening-servidor.sh
    sudo bash infra/scripts/04-desplegar.sh marketgt.duckdns.org

  ─── AL TERMINAR EL CURSO ──────────────────────────────────────
  Para borrar todo y dejar de consumir crédito:
    az group delete --name ${GRUPO} --yes

═══════════════════════════════════════════════════════════════════

RESUMEN
