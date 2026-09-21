#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# MarketGT · Creación de la aplicación Laravel (Capa 5)
#
# Instala PHP, Composer y el instalador de Laravel, y genera la aplicación
# con el starter kit de Livewire, que ya incluye la interfaz de segundo factor
# con TOTP y con passkeys exigida por el anexo de autenticación.
#
# Uso:  sudo bash infra/scripts/01-crear-app-laravel.sh
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail
log() { printf '\n\033[1;34m▸ %s\033[0m\n' "$*"; }

export DEBIAN_FRONTEND=noninteractive
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

log "Instalando PHP y sus extensiones"
apt-get update -qq
apt-get install -y -qq --no-install-recommends \
  php8.3-cli php8.3-common php8.3-mbstring php8.3-xml php8.3-curl \
  php8.3-zip php8.3-bcmath php8.3-gd php8.3-intl php8.3-mysql \
  php8.3-sqlite3 php8.3-gmp unzip

php -v | head -1

log "Instalando Composer"
if ! command -v composer >/dev/null 2>&1; then
  EXPECTED="$(curl -fsSL https://composer.github.io/installer.sig)"
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  ACTUAL="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
  if [ "$EXPECTED" != "$ACTUAL" ]; then
    echo "ERROR: la firma del instalador de Composer no coincide. Abortando." >&2
    exit 1
  fi
  php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi
composer --version

log "Instalando Node.js 24"
if ! command -v node >/dev/null 2>&1; then
  curl -fsSL https://deb.nodesource.com/setup_24.x | bash - >/dev/null 2>&1
  apt-get install -y -qq nodejs
fi
node --version && npm --version

log "Entorno de la aplicación listo"
