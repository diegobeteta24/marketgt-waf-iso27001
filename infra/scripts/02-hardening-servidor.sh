#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
#  MarketGT · Endurecimiento del servidor (Capa 2 · Red  y  Capa 3 · SO)
#
#  Aplica la línea base de configuración segura sobre Ubuntu Server, conforme
#  al CIS Benchmark for Ubuntu Linux y a los controles del Anexo A de la
#  norma ISO/IEC 27001:2022 que se indican en cada sección.
#
#  Es idempotente: puede ejecutarse varias veces sin efectos adversos.
#  Funciona en cualquier proveedor (Google Cloud, Azure, Oracle, AWS).
#
#  Uso:   sudo bash infra/scripts/02-hardening-servidor.sh
#  Audita después con:  sudo lynis audit system
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

log()  { printf '\n\033[1;34m▸ %s\033[0m\n' "$*"; }
ok()   { printf '  \033[1;32m✓\033[0m %s\n' "$*"; }
warn() { printf '  \033[1;33m!\033[0m %s\n' "$*"; }

[ "$(id -u)" -eq 0 ] || { echo "Ejecutar con sudo." >&2; exit 1; }
export DEBIAN_FRONTEND=noninteractive

# El usuario administrativo cuyo acceso por SSH hay que preservar.
ADMIN_USER="${SUDO_USER:-ubuntu}"
log "Usuario administrativo detectado: ${ADMIN_USER}"

# ─────────────────────────────────────────────────────────────────────────────
# 1. Actualización del sistema y parcheo automático
#    Controles: A.8.8 (gestión de vulnerabilidades técnicas), A.8.19
#    Meta del proyecto: 100 % de vulnerabilidades críticas corregidas en 72 horas
# ─────────────────────────────────────────────────────────────────────────────
log "Actualizando el sistema y habilitando el parcheo automático"
apt-get update -qq
apt-get upgrade -y -qq
apt-get install -y -qq unattended-upgrades apt-listchanges

cat > /etc/apt/apt.conf.d/20auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Download-Upgradeable-Packages "1";
APT::Periodic::AutocleanInterval "7";
APT::Periodic::Unattended-Upgrade "1";
EOF

cat > /etc/apt/apt.conf.d/50unattended-upgrades <<'EOF'
// MarketGT — parcheo automático de seguridad (control A.8.8)
Unattended-Upgrade::Allowed-Origins {
    "${distro_id}:${distro_codename}";
    "${distro_id}:${distro_codename}-security";
    "${distro_id}ESMApps:${distro_codename}-apps-security";
    "${distro_id}ESM:${distro_codename}-infra-security";
};
Unattended-Upgrade::AutoFixInterruptedDpkg "true";
Unattended-Upgrade::MinimalSteps "true";
Unattended-Upgrade::Remove-Unused-Kernel-Packages "true";
Unattended-Upgrade::Remove-Unused-Dependencies "true";
Unattended-Upgrade::Automatic-Reboot "false";
EOF
systemctl enable --now unattended-upgrades >/dev/null 2>&1 || true
ok "Parcheo automático de seguridad habilitado"

# ─────────────────────────────────────────────────────────────────────────────
# 2. Cortafuegos de red — Capa 2
#    Controles: A.8.20 (seguridad de redes), A.8.21, A.8.22
#
#    ADVERTENCIA sobre Docker: Docker inserta sus propias reglas en iptables
#    ANTES que las de UFW, de modo que cualquier puerto publicado con
#    -p 0.0.0.0:puerto queda expuesto a Internet aunque UFW lo niegue.
#    Por eso todos los contenedores se publican en 127.0.0.1 y únicamente
#    el WAF escucha en 80 y 443. Se verifica al final del script.
# ─────────────────────────────────────────────────────────────────────────────
log "Configurando el cortafuegos (UFW)"
apt-get install -y -qq ufw

# Algunas imágenes de proveedor traen reglas persistentes que anulan a UFW.
if dpkg -l | grep -qE '^ii[[:space:]]+(netfilter-persistent|iptables-persistent)'; then
  warn "Se detectaron reglas iptables persistentes del proveedor; se retiran"
  apt-get purge -y -qq netfilter-persistent iptables-persistent || true
fi

ufw --force reset >/dev/null
ufw default deny incoming  >/dev/null
ufw default allow outgoing >/dev/null
ufw limit 22/tcp  comment 'SSH con limitacion de tasa' >/dev/null
ufw allow  80/tcp comment 'HTTP hacia el WAF'          >/dev/null
ufw allow 443/tcp comment 'HTTPS hacia el WAF'         >/dev/null
ufw --force enable >/dev/null
ok "UFW activo: solo 22 (limitado), 80 y 443"

# ─────────────────────────────────────────────────────────────────────────────
# 3. Bloqueo de fuerza bruta — Capa 2
#    Controles: A.8.5 (autenticación segura), A.5.7 (inteligencia de amenazas)
# ─────────────────────────────────────────────────────────────────────────────
log "Configurando fail2ban"
apt-get install -y -qq fail2ban

cat > /etc/fail2ban/jail.local <<'EOF'
# MarketGT — política de bloqueo automático (control A.8.5)
[DEFAULT]
bantime   = 1h
findtime  = 10m
maxretry  = 5
backend   = systemd
banaction = ufw

[sshd]
enabled  = true
port     = 22
maxretry = 3
bantime  = 24h

# Ataques detectados por el WAF: cinco bloqueos del Core Rule Set desde
# la misma dirección en diez minutos implican actividad dirigida, no un
# falso positivo aislado.
[marketgt-waf]
enabled  = true
port     = http,https
filter   = marketgt-waf
logpath  = /var/log/marketgt/waf-access.log
maxretry = 5
findtime = 10m
bantime  = 2h
EOF

cat > /etc/fail2ban/filter.d/marketgt-waf.conf <<'EOF'
# Detecta respuestas 403 emitidas por el WAF en el registro de acceso.
[Definition]
failregex = ^<HOST> .* "(GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS|TRACE)[^"]*" 403
ignoreregex =
EOF

mkdir -p /var/log/marketgt && touch /var/log/marketgt/waf-access.log
systemctl enable --now fail2ban >/dev/null 2>&1 || true
ok "fail2ban activo para SSH y para los bloqueos del WAF"

# ─────────────────────────────────────────────────────────────────────────────
# 4. Acceso remoto — Capa 3
#    Controles: A.8.5 (autenticación segura), A.5.15 (control de acceso)
# ─────────────────────────────────────────────────────────────────────────────
log "Endureciendo el servicio SSH"

# Solo se desactiva la contraseña si el usuario administrativo ya tiene una
# llave autorizada; de lo contrario quedaríamos fuera del servidor.
if [ "${ADMIN_USER}" = "root" ]; then
  KEYFILE="/root/.ssh/authorized_keys"
else
  KEYFILE="/home/${ADMIN_USER}/.ssh/authorized_keys"
fi

if [ -s "${KEYFILE}" ]; then
  cat > /etc/ssh/sshd_config.d/99-marketgt.conf <<EOF
# MarketGT — endurecimiento del acceso remoto (controles A.8.5 y A.5.15)
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
PermitEmptyPasswords no
X11Forwarding no
AllowAgentForwarding no
AllowTcpForwarding no
MaxAuthTries 3
MaxSessions 4
LoginGraceTime 30
ClientAliveInterval 300
ClientAliveCountMax 2
AllowUsers ${ADMIN_USER}

# Solo algoritmos criptográficos vigentes.
#
# Se incluyen además los cifrados en modo contador porque hay clientes que
# operan bajo el estándar FIPS 140 y no admiten ni ChaCha20 ni los modos GCM.
# La consola web del propio proveedor de nube es uno de ellos: restringir la
# lista a los tres primeros dejó el servidor inaccesible desde ella, lo que
# obligó a recuperarlo mediante el agente de la plataforma.
#
# Es un ejemplo de endurecimiento contraproducente: una configuración más
# estricta que el entorno no puede satisfacer no aumenta la seguridad, porque
# acaba forzando a abrir otra vía de acceso para recuperar el control. Los
# cifrados en modo contador siguen siendo aceptables; lo que se excluye de
# verdad son los algoritmos obsoletos como CBC, 3DES, RC4 y Arcfour.
KexAlgorithms curve25519-sha256,curve25519-sha256@libssh.org,diffie-hellman-group16-sha512
Ciphers chacha20-poly1305@openssh.com,aes256-gcm@openssh.com,aes128-gcm@openssh.com,aes256-ctr,aes192-ctr,aes128-ctr
MACs hmac-sha2-512-etm@openssh.com,hmac-sha2-256-etm@openssh.com,hmac-sha2-512,hmac-sha2-256
EOF
  if sshd -t 2>/dev/null; then
    systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || true
    ok "SSH: solo llave pública, sin acceso de root, tres intentos máximo"
  else
    warn "La configuración de SSH no validó; se revierte por seguridad"
    rm -f /etc/ssh/sshd_config.d/99-marketgt.conf
  fi
else
  warn "No hay llave autorizada para '${ADMIN_USER}'."
  warn "NO se desactiva la contraseña para no perder el acceso al servidor."
  warn "Copiá tu llave con ssh-copy-id y volvé a ejecutar este script."
fi

# ─────────────────────────────────────────────────────────────────────────────
# 5. Parámetros del núcleo — Capa 3
#    Controles: A.8.20 (seguridad de redes), A.8.9 (gestión de configuración)
# ─────────────────────────────────────────────────────────────────────────────
log "Aplicando parámetros de red y de memoria del núcleo"
cat > /etc/sysctl.d/99-marketgt-hardening.conf <<'EOF'
# MarketGT — línea base del núcleo (CIS Ubuntu Linux Benchmark)

# Reenvío de paquetes y enrutamiento por origen
net.ipv4.ip_forward = 0
net.ipv4.conf.all.send_redirects = 0
net.ipv4.conf.default.send_redirects = 0
net.ipv4.conf.all.accept_source_route = 0
net.ipv4.conf.default.accept_source_route = 0
net.ipv4.conf.all.accept_redirects = 0
net.ipv4.conf.default.accept_redirects = 0
net.ipv4.conf.all.secure_redirects = 0
net.ipv6.conf.all.accept_redirects = 0
net.ipv6.conf.all.accept_source_route = 0

# Registro de paquetes con dirección de origen falsificada
net.ipv4.conf.all.log_martians = 1
net.ipv4.conf.default.log_martians = 1

# Filtrado por ruta inversa: descarta el suplantado de direcciones
net.ipv4.conf.all.rp_filter = 1
net.ipv4.conf.default.rp_filter = 1

# Denegación de servicio por inundación de SYN
net.ipv4.tcp_syncookies = 1
net.ipv4.tcp_max_syn_backlog = 2048
net.ipv4.tcp_synack_retries = 2

# No responder a difusiones ICMP (amplificación Smurf)
net.ipv4.icmp_echo_ignore_broadcasts = 1
net.ipv4.icmp_ignore_bogus_error_responses = 1

# Aleatorización del espacio de direcciones y protección de memoria
kernel.randomize_va_space = 2
kernel.kptr_restrict = 2
kernel.dmesg_restrict = 1
kernel.yama.ptrace_scope = 1

# Volcados de memoria: podrían contener credenciales en claro
fs.suid_dumpable = 0
EOF
sysctl -p /etc/sysctl.d/99-marketgt-hardening.conf >/dev/null 2>&1 || true
ok "Parámetros del núcleo aplicados"

# ─────────────────────────────────────────────────────────────────────────────
# 6. Registro de auditoría — vértice de detección
#    Controles: A.8.15 (registro de eventos), A.8.16 (actividades de seguimiento)
# ─────────────────────────────────────────────────────────────────────────────
log "Instalando la auditoría del sistema"
apt-get install -y -qq auditd audispd-plugins

cat > /etc/audit/rules.d/99-marketgt.rules <<'EOF'
# MarketGT — reglas de auditoría (controles A.8.15 y A.8.16)
-D
-b 8192
-f 1

# Cambios sobre cuentas, grupos y credenciales
-w /etc/passwd  -p wa -k identidad
-w /etc/shadow  -p wa -k identidad
-w /etc/group   -p wa -k identidad
-w /etc/gshadow -p wa -k identidad
-w /etc/sudoers -p wa -k escalada_privilegios
-w /etc/sudoers.d/ -p wa -k escalada_privilegios

# Configuración del acceso remoto y del cortafuegos
-w /etc/ssh/sshd_config    -p wa -k acceso_remoto
-w /etc/ssh/sshd_config.d/ -p wa -k acceso_remoto
-w /etc/ufw/               -p wa -k cortafuegos
-w /etc/fail2ban/          -p wa -k cortafuegos

# Configuración del WAF y del despliegue: integridad de los controles
-w /opt/marketgt/infra/modsecurity/ -p wa -k waf_configuracion
-w /opt/marketgt/infra/docker/      -p wa -k despliegue

# Uso de comandos privilegiados
-a always,exit -F arch=b64 -S execve -C uid!=euid -F euid=0 -k ejecucion_privilegiada

# Los registros de auditoría no se modifican una vez escritos
-e 2
EOF
systemctl enable --now auditd >/dev/null 2>&1 || true
augenrules --load >/dev/null 2>&1 || true
ok "Auditoría del sistema activa (reglas inmutables)"

# ─────────────────────────────────────────────────────────────────────────────
# 7. Reducción de la superficie de exposición
#    Controles: A.8.9 (gestión de configuración), A.8.19
# ─────────────────────────────────────────────────────────────────────────────
log "Reduciendo la superficie de exposición"
for svc in avahi-daemon cups rpcbind nfs-server bluetooth; do
  if systemctl list-unit-files 2>/dev/null | grep -q "^${svc}"; then
    systemctl disable --now "${svc}" >/dev/null 2>&1 || true
    ok "Servicio innecesario desactivado: ${svc}"
  fi
done

# Sistemas de archivos que esta plataforma no utiliza
cat > /etc/modprobe.d/99-marketgt-blacklist.conf <<'EOF'
install cramfs   /bin/true
install freevxfs /bin/true
install jffs2    /bin/true
install hfs      /bin/true
install hfsplus  /bin/true
install udf      /bin/true
install usb-storage /bin/true
EOF
ok "Módulos de sistemas de archivos no utilizados bloqueados"

# ─────────────────────────────────────────────────────────────────────────────
# 8. Herramientas de auditoría y verificación final
# ─────────────────────────────────────────────────────────────────────────────
log "Instalando el instrumental de auditoría"
apt-get install -y -qq lynis nmap
ok "Lynis y nmap disponibles"

log "Verificación del resultado"
echo
ufw status verbose | head -12
echo
echo "Puertos en escucha que NO están limitados a loopback:"
ss -tlnH 2>/dev/null | grep -vE '127\.0\.0\.1|\[::1\]' | awk '{print "  " $4}' | sort -u
echo
warn "Comprobá desde FUERA del servidor que solo 80 y 443 respondan:"
warn "   nmap -Pn -p- <IP_PUBLICA>"
echo
log "Endurecimiento completado. Auditá con:  sudo lynis audit system"
