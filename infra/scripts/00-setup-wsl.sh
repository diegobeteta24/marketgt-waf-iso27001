#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# MarketGT · Preparación del entorno de desarrollo y del entorno de respaldo
#
# Instala Docker, Compose y el instrumental de auditoría dentro de Ubuntu.
# Sirve tanto para WSL2 (desarrollo y plan de contingencia) como para la VM
# de producción, porque el stack completo corre en contenedores.
#
# Uso:  sudo bash infra/scripts/00-setup-wsl.sh
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

log() { printf '\n\033[1;34m▸ %s\033[0m\n' "$*"; }

export DEBIAN_FRONTEND=noninteractive

log "Actualizando índice de paquetes"
apt-get update -qq

log "Instalando utilidades base"
apt-get install -y -qq --no-install-recommends \
  ca-certificates curl gnupg lsb-release git unzip jq openssl \
  apache2-utils dnsutils iproute2 net-tools

log "Instalando el instrumental de auditoría (nmap, lynis, testssl)"
apt-get install -y -qq --no-install-recommends nmap lynis

log "Añadiendo el repositorio oficial de Docker"
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
  | gpg --dearmor -o /etc/apt/keyrings/docker.gpg --yes
chmod a+r /etc/apt/keyrings/docker.gpg
printf 'deb [arch=%s signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu %s stable\n' \
  "$(dpkg --print-architecture)" "$(. /etc/os-release && echo "$VERSION_CODENAME")" \
  > /etc/apt/sources.list.d/docker.list
apt-get update -qq

log "Instalando Docker Engine y Compose"
apt-get install -y -qq \
  docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

log "Habilitando Docker"
if command -v systemctl >/dev/null 2>&1 && systemctl is-system-running --quiet 2>/dev/null; then
  systemctl enable --now docker
else
  # WSL2 sin systemd: el demonio se levanta a mano
  service docker start || dockerd >/var/log/dockerd.log 2>&1 &
  sleep 5
fi

log "Verificación"
docker --version
docker compose version
nmap --version | head -1
lynis --version 2>/dev/null | head -1 || true

log "Entorno listo"
