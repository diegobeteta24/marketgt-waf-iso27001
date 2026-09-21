#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
#  MarketGT · Aprovisionamiento de la máquina virtual en Google Cloud
#
#  Se ejecuta DESDE TU EQUIPO (no desde el servidor), con la herramienta gcloud
#  ya autenticada. Crea la instancia, abre los puertos y deja impresa la
#  dirección pública que hay que apuntar en el DNS.
#
#  Requisitos previos:
#    1. Cuenta de Google Cloud con el crédito de prueba activo (300 USD, 90 días).
#    2. gcloud instalado:  https://cloud.google.com/sdk/docs/install
#    3. gcloud init  &&  gcloud auth login
#
#  Uso:   bash infra/scripts/03-crear-vm-gcp.sh
#
#  REGLA DE ORO: nadie pulsa "Activar cuenta completa" ni "Upgrade" en la
#  consola de Google Cloud. Mientras la cuenta siga en prueba, no puede
#  generarse ningún cargo: al agotarse el crédito los recursos se detienen.
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

log()  { printf '\n\033[1;34m▸ %s\033[0m\n' "$*"; }
ok()   { printf '  \033[1;32m✓\033[0m %s\n' "$*"; }
warn() { printf '  \033[1;33m!\033[0m %s\n' "$*"; }

# ─── Parámetros ──────────────────────────────────────────────────────────────
NOMBRE="${NOMBRE:-marketgt}"
ZONA="${ZONA:-us-central1-a}"

# 4 núcleos y 16 GB. Es el tamaño que exige el conjunto completo con Wazuh:
# el indexador por sí solo pide alrededor de 4 GB, y encima van MariaDB,
# PHP-FPM, Nginx con ModSecurity y el panel. Cuesta unos 16 USD de los 300
# del crédito durante los cinco días hasta la presentación.
TIPO="${TIPO:-e2-standard-4}"
DISCO="${DISCO:-50GB}"
IMAGEN_FAMILIA="${IMAGEN_FAMILIA:-ubuntu-2404-lts}"
IMAGEN_PROYECTO="ubuntu-os-cloud"
ETIQUETA_WEB="servidor-web"

command -v gcloud >/dev/null 2>&1 || {
  echo "No se encontró gcloud. Instalalo desde https://cloud.google.com/sdk/docs/install" >&2
  exit 1
}

PROYECTO="$(gcloud config get-value project 2>/dev/null)"
[ -n "${PROYECTO}" ] && [ "${PROYECTO}" != "(unset)" ] || {
  echo "No hay proyecto seleccionado. Ejecutá:  gcloud init" >&2
  exit 1
}

log "Proyecto: ${PROYECTO}   ·   Zona: ${ZONA}   ·   Tipo: ${TIPO}"

# ─── Verificación de que la cuenta sigue en modo prueba ──────────────────────
log "Comprobando el estado de la facturación"
if gcloud beta billing projects describe "${PROYECTO}" >/dev/null 2>&1; then
  ok "Facturación vinculada (necesaria incluso para el crédito de prueba)"
else
  warn "No se pudo leer la facturación; continuamos de todos modos"
fi

# ─── Regla de cortafuegos ────────────────────────────────────────────────────
# Primera de las dos capas de cortafuegos. La segunda es UFW dentro de la
# máquina, que aplica el script de endurecimiento. Las dos son necesarias:
# esta filtra en la red de Google, antes de que el paquete llegue al servidor.
log "Creando la regla de cortafuegos para HTTP y HTTPS"
if gcloud compute firewall-rules describe "permitir-web-marketgt" >/dev/null 2>&1; then
  ok "La regla ya existía"
else
  gcloud compute firewall-rules create "permitir-web-marketgt" \
    --description="Trafico HTTP y HTTPS hacia el WAF de MarketGT" \
    --direction=INGRESS \
    --priority=1000 \
    --network=default \
    --action=ALLOW \
    --rules=tcp:80,tcp:443 \
    --source-ranges=0.0.0.0/0 \
    --target-tags="${ETIQUETA_WEB}" >/dev/null
  ok "Regla creada: 80 y 443 abiertos hacia las instancias etiquetadas"
fi

# El acceso remoto queda restringido al rango del servicio de consola web de
# Google, de modo que el puerto 22 no está expuesto a todo Internet.
log "Restringiendo el acceso remoto"
if ! gcloud compute firewall-rules describe "permitir-ssh-marketgt" >/dev/null 2>&1; then
  gcloud compute firewall-rules create "permitir-ssh-marketgt" \
    --description="Acceso remoto solo desde la consola web de Google" \
    --direction=INGRESS --priority=1000 --network=default --action=ALLOW \
    --rules=tcp:22 --source-ranges=35.235.240.0/20 \
    --target-tags="${ETIQUETA_WEB}" >/dev/null
  ok "Acceso remoto limitado al rango de la consola web"
  warn "Para entrar usá:  gcloud compute ssh ${NOMBRE} --zone=${ZONA}"
fi

# ─── Instancia ───────────────────────────────────────────────────────────────
log "Creando la instancia ${NOMBRE}"
if gcloud compute instances describe "${NOMBRE}" --zone="${ZONA}" >/dev/null 2>&1; then
  ok "La instancia ya existía"
else
  gcloud compute instances create "${NOMBRE}" \
    --zone="${ZONA}" \
    --machine-type="${TIPO}" \
    --image-family="${IMAGEN_FAMILIA}" \
    --image-project="${IMAGEN_PROYECTO}" \
    --boot-disk-size="${DISCO}" \
    --boot-disk-type=pd-balanced \
    --tags="${ETIQUETA_WEB}" \
    --metadata=enable-oslogin=TRUE \
    --scopes=cloud-platform >/dev/null
  ok "Instancia creada"
fi

log "Esperando a que la instancia esté en ejecución"
for _ in $(seq 1 30); do
  ESTADO="$(gcloud compute instances describe "${NOMBRE}" --zone="${ZONA}" \
            --format='get(status)' 2>/dev/null || echo '')"
  [ "${ESTADO}" = "RUNNING" ] && break
  printf '.'
done
echo
ok "Estado: ${ESTADO:-desconocido}"

IP="$(gcloud compute instances describe "${NOMBRE}" --zone="${ZONA}" \
      --format='get(networkInterfaces[0].accessConfigs[0].natIP)')"

# ─── Dirección estática ──────────────────────────────────────────────────────
# Sin reservarla, la dirección cambia en cada reinicio de la instancia y el
# registro DNS quedaría apuntando al vacío justo el día de la presentación.
log "Reservando la dirección para que no cambie al reiniciar"
REGION="${ZONA%-*}"
if gcloud compute addresses describe "${NOMBRE}-ip" --region="${REGION}" >/dev/null 2>&1; then
  ok "La dirección ya estaba reservada"
else
  if gcloud compute addresses create "${NOMBRE}-ip" --region="${REGION}" \
       --addresses="${IP}" >/dev/null 2>&1; then
    ok "Dirección ${IP} reservada como estática"
  else
    warn "No se pudo reservar la dirección; anotá que puede cambiar al reiniciar"
  fi
fi

# ─── Resumen ─────────────────────────────────────────────────────────────────
cat <<RESUMEN

═══════════════════════════════════════════════════════════════════
  MÁQUINA VIRTUAL LISTA
═══════════════════════════════════════════════════════════════════

  Nombre              ${NOMBRE}
  Zona                ${ZONA}
  Tipo                ${TIPO}  (4 núcleos · 16 GB)
  Dirección pública   ${IP}

  SIGUIENTE PASO 1 — Apuntá el dominio a esta dirección:
    En Cloudflare, creá un registro A
       marketgt.us.kg   →   ${IP}
    Dejalo en gris (solo DNS) hasta emitir el certificado.

  SIGUIENTE PASO 2 — Entrá al servidor:
    gcloud compute ssh ${NOMBRE} --zone=${ZONA}

  SIGUIENTE PASO 3 — Desplegá dentro del servidor:
    sudo bash infra/scripts/02-hardening-servidor.sh
    sudo bash infra/scripts/04-desplegar.sh marketgt.us.kg

  RECORDATORIO: no pulses "Activar cuenta completa" en la consola.
  Mientras la cuenta siga en prueba no puede generarse ningún cargo.

═══════════════════════════════════════════════════════════════════

RESUMEN
