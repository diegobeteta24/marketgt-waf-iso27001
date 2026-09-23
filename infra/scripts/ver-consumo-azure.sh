#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
#  MarketGT · Cuanto esta costando el servidor
#
#  Se ejecuta en Azure Cloud Shell (o donde haya `az` con sesion iniciada), no
#  en el servidor.
#
#  Responde tres cosas distintas que conviene no mezclar:
#
#    1. Cuanto se gasto por dia, para saber si el ritmo es el esperado.
#    2. Que recurso se lleva el dinero. La maquina virtual no suele ser el
#       unico: el disco y la direccion IP estatica facturan aunque la maquina
#       este apagada, y esa es la sorpresa habitual.
#    3. Cuantos dias de credito quedan a ese ritmo.
#
#  El dato de consumo de Azure llega con retraso, tipicamente de entre 8 y 24
#  horas. Un dia reciente que salga en cero casi siempre significa "todavia no
#  facturado", no "gratis". El script lo dice en lugar de dejar que se lea mal.
#
#  Uso:  bash infra/scripts/ver-consumo-azure.sh [dias]   (por defecto 7)
# ─────────────────────────────────────────────────────────────────────────────
set -uo pipefail

DIAS="${1:-7}"
GRUPO="${GRUPO:-marketgt-rg}"

log()  { printf '\n\033[1;34m▸ %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m  ! %s\033[0m\n' "$*"; }

command -v az >/dev/null 2>&1 || { echo "No hay 'az' en este equipo. Ejecutalo en Azure Cloud Shell."; exit 1; }

DESDE="$(date -u -d "${DIAS} days ago" +%Y-%m-%d 2>/dev/null || date -u -v-"${DIAS}"d +%Y-%m-%d)"
HASTA="$(date -u +%Y-%m-%d)"

log "Consumo del ${DESDE} al ${HASTA}"

CRUDO="$(az consumption usage list --start-date "${DESDE}" --end-date "${HASTA}" \
  --query "[].{fecha:usageStart, recurso:instanceName, medidor:meterDetails.meterName, costo:pretaxCost, moneda:currency}" \
  -o json 2>/dev/null)"

if [ -z "${CRUDO}" ] || [ "${CRUDO}" = "[]" ]; then
  warn "Azure no devolvio registros de consumo."
  warn "Puede ser que la suscripcion sea muy nueva, que el dato aun no este"
  warn "facturado, o que el plan de estudiante no exponga esta API."
  warn "Alternativa: portal.azure.com -> Cost Management -> Analisis de costos."
  echo
else
  echo "${CRUDO}" | python3 -c '
import json, sys
from collections import defaultdict

filas = json.load(sys.stdin)
if not filas:
    sys.exit(0)

moneda = filas[0].get("moneda") or "USD"

# Por dia: es la cifra que contesta "cuanto me cuesta dejarla encendida".
por_dia = defaultdict(float)
# Por recurso: contesta "que es lo que cuesta", que rara vez es solo la maquina.
por_recurso = defaultdict(float)

for f in filas:
    costo = float(f.get("costo") or 0)
    dia = (f.get("fecha") or "")[:10]
    por_dia[dia] += costo
    por_recurso[f.get("recurso") or "(sin nombre)"] += costo

total = sum(por_dia.values())
dias = sorted(por_dia)

print("  POR DIA")
for d in dias:
    print("    %s   %8.4f %s" % (d, por_dia[d], moneda))

print()
print("  POR RECURSO")
for r, c in sorted(por_recurso.items(), key=lambda kv: -kv[1]):
    pct = (c / total * 100) if total else 0
    print("    %-34s %8.4f %s  (%4.1f%%)" % (r[:34], c, moneda, pct))

# El promedio se calcula sobre los dias COMPLETOS: incluir el ultimo, que casi
# siempre esta a medio facturar, hunde la media y da una autonomia optimista.
completos = dias[:-1] if len(dias) > 1 else dias
media = sum(por_dia[d] for d in completos) / len(completos) if completos else 0

print()
print("  TOTAL DEL PERIODO      %8.4f %s" % (total, moneda))
print("  MEDIA POR DIA          %8.4f %s   (sobre %d dias completos)" % (media, moneda, len(completos)))
if media > 0:
    print("  AL MES, A ESTE RITMO   %8.2f %s" % (media * 30, moneda))
    print()
    print("  Autonomia segun el credito que te quede:")
    for credito in (100, 75, 50, 25, 10):
        print("    con %3d %s  ->  %5.1f dias" % (credito, moneda, credito / media))
' 2>/dev/null || warn "Falta python3 para el resumen; arriba esta el dato crudo."
fi

log "Que hay encendido ahora mismo"
az resource list --resource-group "${GRUPO}" \
  --query "[].{nombre:name, tipo:type}" -o table 2>/dev/null \
  || warn "No se pudo listar el grupo ${GRUPO}"

echo
az vm get-instance-view --resource-group "${GRUPO}" --name marketgt \
  --query "instanceView.statuses[?starts_with(code, 'PowerState')].displayStatus | [0]" \
  -o tsv 2>/dev/null | sed 's/^/  Estado de la maquina: /' || true

log "Lo que sigue facturando con la maquina APAGADA"
cat <<'TEXTO'
  El disco gestionado y la direccion IP estatica se cobran por existir, no por
  usarse. Apagar la maquina baja la factura, no la lleva a cero.

  Para que deje de costar del todo hay que borrar el grupo entero, y eso se
  lleva tambien los datos y la direccion:

      az group delete --name marketgt-rg --yes --no-wait

  No lo hagas antes de la presentacion.
TEXTO
echo
