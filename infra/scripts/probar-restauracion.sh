#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
#  MarketGT · Prueba de restauración cronometrada (vértice de RESPUESTA)
#
#  El anexo del proyecto declara un RTO de 4 h y un RPO de 24 h. Ninguna de las
#  dos cifras se puede sostener leyendo la configuración del respaldo: la
#  configuración declara una intención, la restauración demuestra un hecho. Este
#  guion ejecuta la prueba de verdad y devuelve los tiempos que midió.
#
#  Fases, y por qué están separadas:
#    1. Volcado           mariadb-dump de la base de producción, cronometrado.
#    2. Cifrado           gpg --symmetric --cipher-algo AES256. El documento
#                         declara AES-256, así que se usa AES-256 y además se
#                         LEE del archivo resultante qué cifrador quedó dentro.
#    3. Descifrado        ─┐
#    4. Restauración       ├─ estas tres son el RTO: el tiempo de volver a tener
#    5. Verificación      ─┘  servicio. Volcar y cifrar pertenecen al respaldo.
#
#  La restauración se hace SIEMPRE sobre una base nueva y desechable. El guion
#  se niega a arrancar si el nombre de destino coincide con el de origen, y solo
#  borra bases cuyo nombre empiece por el prefijo de prueba.
#
#  El punto de recuperación (RPO) se mide ANTES de volcar nada: si se midiera
#  después, el volcado de esta misma prueba sería el respaldo más reciente y el
#  RPO saldría siempre en cero. Ese error convierte la métrica en un espejo.
#
#  Uso:
#    bash infra/scripts/probar-restauracion.sh                     # resumen legible
#    bash infra/scripts/probar-restauracion.sh --json              # acta en JSON
#    bash infra/scripts/probar-restauracion.sh --json \
#         | docker compose exec -T app php artisan siem:probar-restauracion \
#             --registrar=- --origen=guion
#
#  Variables de entorno (todas con equivalente en la línea de órdenes):
#    SIEM_BD_BASE SIEM_BD_USUARIO SIEM_BD_CLAVE SIEM_BD_ANFITRION SIEM_BD_PUERTO
#    SIEM_BD_ADMIN_USUARIO SIEM_BD_ADMIN_CLAVE
#    SIEM_CONTENEDOR_BD SIEM_RUTA_RESPALDOS SIEM_FRASE_RESPALDO SIEM_DIR_TRABAJO
# ─────────────────────────────────────────────────────────────────────────────
set -uo pipefail

# ── Configuración ────────────────────────────────────────────────────────────
BASE="${SIEM_BD_BASE:-marketgt}"
USUARIO="${SIEM_BD_USUARIO:-marketgt}"
CLAVE="${SIEM_BD_CLAVE:-}"
ANFITRION="${SIEM_BD_ANFITRION:-}"
PUERTO="${SIEM_BD_PUERTO:-3306}"
ADMIN_USUARIO="${SIEM_BD_ADMIN_USUARIO:-root}"
ADMIN_CLAVE="${SIEM_BD_ADMIN_CLAVE:-}"
CONTENEDOR="${SIEM_CONTENEDOR_BD:-}"
RESPALDOS="${SIEM_RUTA_RESPALDOS:-/var/backups/marketgt}"
FRASE="${SIEM_FRASE_RESPALDO:-}"
TRABAJO="${SIEM_DIR_TRABAJO:-}"
CONEXION="${SIEM_CONEXION:-mariadb}"
SALIDA_JSON=0
CONSERVAR=0

# Prefijo que autoriza el borrado. Ninguna base que no empiece así se toca.
PREFIJO_PRUEBA="marketgt_prueba_"

while [ $# -gt 0 ]; do
  case "$1" in
    --base)            BASE="$2"; shift 2 ;;
    --usuario)         USUARIO="$2"; shift 2 ;;
    --clave)           CLAVE="$2"; shift 2 ;;
    --anfitrion)       ANFITRION="$2"; shift 2 ;;
    --puerto)          PUERTO="$2"; shift 2 ;;
    --admin-usuario)   ADMIN_USUARIO="$2"; shift 2 ;;
    --admin-clave)     ADMIN_CLAVE="$2"; shift 2 ;;
    --contenedor)      CONTENEDOR="$2"; shift 2 ;;
    --respaldos)       RESPALDOS="$2"; shift 2 ;;
    --frase)           FRASE="$2"; shift 2 ;;
    --trabajo)         TRABAJO="$2"; shift 2 ;;
    --conexion)        CONEXION="$2"; shift 2 ;;
    --json)            SALIDA_JSON=1; shift ;;
    --conservar)       CONSERVAR=1; shift ;;
    -h|--help)         sed -n '2,40p' "$0"; exit 0 ;;
    *) printf 'Opcion desconocida: %s\n' "$1" >&2; exit 2 ;;
  esac
done

# ── Estado del acta ──────────────────────────────────────────────────────────
SELLO="$(date +%Y%m%d_%H%M%S)"
BASE_PRUEBA="${PREFIJO_PRUEBA}${SELLO}"
INICIADA_EN="$(date --iso-8601=seconds)"
ACTOR="$(id -un 2>/dev/null || echo desconocido)@$(hostname 2>/dev/null || echo desconocido)"
ANFITRION_SO="$(hostname 2>/dev/null || echo desconocido)"
COMANDO="infra/scripts/probar-restauracion.sh --base ${BASE}"
MODO_CLIENTE=""
LOG=""
FASE_FALLIDA=""
ERROR=""

SEG_VOLCADO="null"; SEG_CIFRADO="null"; SEG_DESCIFRADO="null"
SEG_RESTAURACION="null"; SEG_VERIFICACION="null"
BYTES_VOLCADO="null"; ALGORITMO="null"; CIFRADO_OK="false"; HUELLA="null"
VERIFICACION_OK="false"; TABLAS=0; FILAS=0
CONTEOS_ORIGEN="{}"; CONTEOS_PRUEBA="{}"; DISCREPANCIAS="{}"
RESP_EPOCH=""; RESP_RUTA=""; RESP_CANT=0

# ── Utilidades ───────────────────────────────────────────────────────────────
paso()  { printf '\033[1;34m▸ %s\033[0m\n' "$*" >&2; LOG="${LOG}> $*"$'\n'; }
dato()  { printf '  %-30s %s\n' "$1" "$2" >&2;       LOG="${LOG}  $1: $2"$'\n'; }
aviso() { printf '\033[1;33m  ! %s\033[0m\n' "$*" >&2; LOG="${LOG}  ! $*"$'\n'; }

# Un fallo no aborta el acta: la registra como fallida. Una prueba que revienta
# sin dejar rastro es peor que una que falla y lo cuenta.
fallar() { FASE_FALLIDA="$1"; ERROR="$2"; printf '\033[1;31m  ✗ %s: %s\033[0m\n' "$1" "$2" >&2; LOG="${LOG}  x $1: $2"$'\n'; }

ahora_ns() { date +%s%N; }
transcurrido() { awk -v a="$1" -v b="$2" 'BEGIN{ printf "%.3f", (b-a)/1000000000 }'; }

json_cadena() {
  printf '%s' "$1" \
    | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e 's/\t/\\t/g' -e 's/\r//g' \
    | sed -e ':a' -e 'N' -e '$!ba' -e 's/\n/\\n/g'
}

json_o_nulo() { [ -n "${1:-}" ] && printf '"%s"' "$(json_cadena "$1")" || printf 'null'; }

# ── Elección del cliente ─────────────────────────────────────────────────────
# Dos escenarios reales: el anfitrión Ubuntu con docker (el del despliegue) y
# una máquina donde los clientes de MariaDB están en el PATH. Se prueba primero
# el cliente directo porque es el camino sin intermediarios.
elegir_cliente() {
  if command -v mariadb-dump >/dev/null 2>&1 && command -v mariadb >/dev/null 2>&1; then
    MODO="directo"; MODO_CLIENTE="directo"
    [ -n "$ANFITRION" ] || ANFITRION="127.0.0.1"
    return 0
  fi
  if command -v mysqldump >/dev/null 2>&1 && command -v mysql >/dev/null 2>&1; then
    MODO="directo_mysql"; MODO_CLIENTE="directo(mysql)"
    [ -n "$ANFITRION" ] || ANFITRION="127.0.0.1"
    return 0
  fi
  if command -v docker >/dev/null 2>&1; then
    if [ -z "$CONTENEDOR" ]; then
      CONTENEDOR="$(docker ps --filter 'ancestor=mariadb:lts' --format '{{.Names}}' 2>/dev/null | head -1)"
      [ -n "$CONTENEDOR" ] || CONTENEDOR="$(docker ps --format '{{.Names}}' 2>/dev/null | grep -m1 -- '-db' || true)"
    fi
    if [ -n "$CONTENEDOR" ] && docker inspect "$CONTENEDOR" >/dev/null 2>&1; then
      MODO="docker"; MODO_CLIENTE="docker:${CONTENEDOR}"
      # Dentro del contenedor el servidor es local; el anfitrión de la aplicación
      # no significa nada ahí.
      ANFITRION="127.0.0.1"
      return 0
    fi
  fi
  return 1
}

# MYSQL_PWD en vez de --password: la clave no aparece en la lista de procesos,
# que es visible para cualquier usuario del servidor. Con docker, "-e MYSQL_PWD"
# sin valor la toma del entorno del cliente, de modo que tampoco entra en los
# argumentos del contenedor.
#
# PWD_ACTUAL dice con que credencial se habla en cada momento: la cuenta de la
# aplicacion para leer el origen y la administrativa solo para crear, cargar y
# borrar la base de prueba. Mezclarlas obligaria a dar permiso de CREATE DATABASE
# a la cuenta con la que corre la tienda.
PWD_ACTUAL=""

volcador() {
  case "$MODO" in
    directo)       MYSQL_PWD="$PWD_ACTUAL" mariadb-dump "$@" ;;
    directo_mysql) MYSQL_PWD="$PWD_ACTUAL" mysqldump "$@" ;;
    docker)        MYSQL_PWD="$PWD_ACTUAL" docker exec -i -e MYSQL_PWD "$CONTENEDOR" mariadb-dump "$@" ;;
  esac
}

cliente() {
  case "$MODO" in
    directo)       MYSQL_PWD="$PWD_ACTUAL" mariadb "$@" ;;
    directo_mysql) MYSQL_PWD="$PWD_ACTUAL" mysql "$@" ;;
    docker)        MYSQL_PWD="$PWD_ACTUAL" docker exec -i -e MYSQL_PWD "$CONTENEDOR" mariadb "$@" ;;
  esac
}

# ── Medición del punto de recuperación ───────────────────────────────────────
# Se hace lo primero de todo, antes de generar un solo byte. Ver la cabecera.
medir_respaldos() {
  paso "Punto de recuperacion: respaldos disponibles en ${RESPALDOS}"

  if [ ! -d "$RESPALDOS" ]; then
    aviso "El directorio de respaldos no existe. El RPO quedara sin datos."
    return 0
  fi

  local archivo marca
  while IFS= read -r -d '' archivo; do
    RESP_CANT=$((RESP_CANT + 1))
    marca="$(stat -c %Y "$archivo" 2>/dev/null || echo 0)"
    if [ -z "$RESP_EPOCH" ] || [ "$marca" -gt "$RESP_EPOCH" ]; then
      RESP_EPOCH="$marca"; RESP_RUTA="$archivo"
    fi
  done < <(find "$RESPALDOS" -maxdepth 2 -type f -size +0c \
             \( -name '*.sql' -o -name '*.sql.gz' -o -name '*.sql.gpg' \
                -o -name '*.sql.gz.gpg' -o -name '*.dump' -o -name '*.dump.gpg' \
                -o -name '*.tar.gz' -o -name '*.tar.gz.gpg' \) -print0 2>/dev/null)

  if [ -z "$RESP_EPOCH" ]; then
    aviso "No hay ningun respaldo en el directorio. El RPO quedara sin datos."
    return 0
  fi

  dato "Respaldos encontrados" "$RESP_CANT"
  dato "Mas reciente" "$RESP_RUTA"
  dato "Fechado" "$(date -d "@${RESP_EPOCH}" --iso-8601=seconds)"
}

# ── Conteo de filas ──────────────────────────────────────────────────────────
# La verificación no puede limitarse al código de salida: una restauración que
# termina en cero y deja las tablas vacías da falsa confianza, que es peor que
# un fallo ruidoso.
listar_tablas() {
  cliente -N -B -u "$1" -h "$ANFITRION" -P "$PUERTO" -e \
    "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$2' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME;" 2>/dev/null
}

contar_filas() { # $1 usuario  $2 base  $3 archivo con la lista de tablas
  local consulta
  consulta="$(awk 'NF{ if (n++) printf " UNION ALL "; printf "SELECT %c%s%c AS t, COUNT(*) AS n FROM `%s`", 39, $1, 39, $1 } END{ if (!n) printf "SELECT 1 WHERE 0" }' "$3")"
  cliente -N -B -u "$1" -h "$ANFITRION" -P "$PUERTO" "$2" -e "$consulta" 2>/dev/null
}

json_conteos() {
  awk -F'\t' 'BEGIN{ printf "{"; sep="" } NF>=2 { printf "%s\"%s\":%d", sep, $1, $2; sep="," } END{ printf "}" }' "$1"
}

# ── Limpieza ─────────────────────────────────────────────────────────────────
# El trap corre pase lo que pase: una base de prueba olvidada en el servidor es
# una copia completa de los datos de clientes sin dueño ni retención.
DIRTMP=""
limpiar() {
  if [ -n "${BASE_PRUEBA:-}" ] && [ "${BASE_PRUEBA#"$PREFIJO_PRUEBA"}" != "$BASE_PRUEBA" ] && [ -n "${MODO:-}" ]; then
    MYSQL_PWD="$ADMIN_CLAVE" cliente -u "$ADMIN_USUARIO" -h "$ANFITRION" -P "$PUERTO" \
      -e "DROP DATABASE IF EXISTS \`${BASE_PRUEBA}\`;" >/dev/null 2>&1
  fi
  if [ -n "$DIRTMP" ] && [ -d "$DIRTMP" ]; then
    if [ "$CONSERVAR" -eq 1 ]; then
      rm -f "${DIRTMP}/volcado.sql" "${DIRTMP}/restaurado.sql" 2>/dev/null
    else
      rm -rf "$DIRTMP" 2>/dev/null
    fi
  fi
}
trap limpiar EXIT

# ─────────────────────────────────────────────────────────────────────────────
#  Ejecución
# ─────────────────────────────────────────────────────────────────────────────
printf '\n\033[1;36m═══ Prueba de restauracion MarketGT · %s ═══\033[0m\n\n' "$SELLO" >&2

ejecutar() {
  if [ "$BASE_PRUEBA" = "$BASE" ]; then
    fallar "preparacion" "La base de prueba coincide con la de origen. Se aborta antes de tocar nada."
    return 1
  fi

  if ! command -v gpg >/dev/null 2>&1; then
    fallar "preparacion" "No hay gpg en el servidor y el respaldo debe ir cifrado en AES-256."
    return 1
  fi

  if [ -z "$FRASE" ]; then
    fallar "preparacion" "Falta la frase de cifrado (SIEM_FRASE_RESPALDO o --frase). Sin ella el volcado saldria en claro mientras el documento declara AES-256."
    return 1
  fi

  if ! elegir_cliente; then
    fallar "preparacion" "No se encontro ni el cliente de MariaDB ni un contenedor de base de datos con el que hablar."
    return 1
  fi

  dato "Cliente" "$MODO_CLIENTE"
  dato "Base de origen" "$BASE"
  dato "Base de prueba" "$BASE_PRUEBA"

  medir_respaldos

  TRABAJO="${TRABAJO:-${TMPDIR:-/var/tmp}}"
  mkdir -p "$TRABAJO" 2>/dev/null
  DIRTMP="$(mktemp -d "${TRABAJO}/marketgt-continuidad-XXXXXX" 2>/dev/null)"
  if [ -z "$DIRTMP" ] || [ ! -d "$DIRTMP" ]; then
    fallar "preparacion" "No se pudo crear el directorio de trabajo bajo ${TRABAJO}."
    return 1
  fi
  chmod 700 "$DIRTMP" 2>/dev/null

  # ── Conteo de control previo ───────────────────────────────────────────────
  # Se toman dos fotos del origen, una antes del volcado y otra después. La base
  # está viva: entre las dos puede entrar un pedido. La restauración se acepta si
  # cada tabla cae DENTRO del intervalo observado, no si coincide con una foto
  # concreta. Comparar contra una sola lectura daría fallos falsos en producción.
  local tablas_origen="${DIRTMP}/tablas.txt"
  listar_tablas "$USUARIO" "$BASE" > "$tablas_origen" 2>/dev/null
  if [ ! -s "$tablas_origen" ]; then
    fallar "preparacion" "No se pudo listar las tablas de ${BASE}. Revise usuario, clave y anfitrion."
    return 1
  fi
  TABLAS="$(grep -c . "$tablas_origen")"
  dato "Tablas en el origen" "$TABLAS"

  local previo="${DIRTMP}/origen-previo.tsv"
  MYSQL_PWD="$CLAVE" contar_filas "$USUARIO" "$BASE" "$tablas_origen" > "$previo"

  # ── Fase 1: volcado ────────────────────────────────────────────────────────
  paso "Fase 1/5 · Volcado de ${BASE}"
  local t0 t1
  t0="$(ahora_ns)"
  # Sin --databases a proposito: esa opcion mete CREATE DATABASE y USE con el
  # nombre de PRODUCCION dentro del volcado, y restaurarlo sobrescribiria la base
  # real por mucho que uno crea estar apuntando a otra.
  MYSQL_PWD="$CLAVE" volcador \
      --single-transaction --quick --skip-lock-tables \
      --default-character-set=utf8mb4 \
      -u "$USUARIO" -h "$ANFITRION" -P "$PUERTO" "$BASE" > "${DIRTMP}/volcado.sql" 2>"${DIRTMP}/volcado.err"
  local estado=$?
  t1="$(ahora_ns)"
  SEG_VOLCADO="$(transcurrido "$t0" "$t1")"

  if [ $estado -ne 0 ] || [ ! -s "${DIRTMP}/volcado.sql" ]; then
    fallar "volcado" "$(head -c 400 "${DIRTMP}/volcado.err" 2>/dev/null || echo 'el volcado quedo vacio')"
    return 1
  fi

  BYTES_VOLCADO="$(stat -c %s "${DIRTMP}/volcado.sql")"
  dato "Tamano del volcado" "$(numfmt --to=iec "$BYTES_VOLCADO" 2>/dev/null || echo "${BYTES_VOLCADO} B")"
  dato "Duracion" "${SEG_VOLCADO} s"

  local posterior="${DIRTMP}/origen-posterior.tsv"
  MYSQL_PWD="$CLAVE" contar_filas "$USUARIO" "$BASE" "$tablas_origen" > "$posterior"
  CONTEOS_ORIGEN="$(json_conteos "$posterior")"

  # ── Fase 2: cifrado ────────────────────────────────────────────────────────
  paso "Fase 2/5 · Cifrado AES-256"
  t0="$(ahora_ns)"
  printf '%s' "$FRASE" | gpg --batch --yes --no-tty --quiet \
      --passphrase-fd 0 --pinentry-mode loopback \
      --symmetric --cipher-algo AES256 \
      --output "${DIRTMP}/volcado.sql.gpg" "${DIRTMP}/volcado.sql" 2>"${DIRTMP}/gpg.err"
  estado=$?
  t1="$(ahora_ns)"
  SEG_CIFRADO="$(transcurrido "$t0" "$t1")"

  if [ $estado -ne 0 ] || [ ! -s "${DIRTMP}/volcado.sql.gpg" ]; then
    fallar "cifrado" "$(head -c 400 "${DIRTMP}/gpg.err" 2>/dev/null || echo 'gpg no produjo archivo')"
    return 1
  fi

  # Un volcado en claro olvidado en el disco es un hallazgo de auditoria por si
  # solo. Se borra en cuanto existe la copia cifrada, no al final del guion.
  shred -u "${DIRTMP}/volcado.sql" 2>/dev/null || rm -f "${DIRTMP}/volcado.sql"

  HUELLA="$(sha256sum "${DIRTMP}/volcado.sql.gpg" 2>/dev/null | cut -d' ' -f1)"

  # No se declara el algoritmo que se pidio, se lee el que quedo dentro. En gpg
  # el identificador 9 es AES-256; 7 y 8 serian AES-128 y AES-192.
  local cifrador
  cifrador="$(gpg --batch --no-tty --list-packets "${DIRTMP}/volcado.sql.gpg" 2>/dev/null \
                | grep -o 'cipher [0-9]\+' | head -1 | awk '{print $2}')"
  case "$cifrador" in
    9)  ALGORITMO="AES256"; CIFRADO_OK="true" ;;
    8)  ALGORITMO="AES192"; CIFRADO_OK="false" ;;
    7)  ALGORITMO="AES128"; CIFRADO_OK="false" ;;
    "") ALGORITMO="desconocido"; CIFRADO_OK="false" ;;
    *)  ALGORITMO="gpg-${cifrador}"; CIFRADO_OK="false" ;;
  esac
  dato "Cifrador leido del archivo" "$ALGORITMO"
  dato "Huella SHA-256" "${HUELLA:0:16}..."
  dato "Duracion" "${SEG_CIFRADO} s"

  if [ "$CIFRADO_OK" != "true" ]; then
    aviso "El archivo no quedo en AES-256: el documento del proyecto declara ese algoritmo."
  fi

  # ── Fase 3: descifrado ─────────────────────────────────────────────────────
  paso "Fase 3/5 · Descifrado"
  t0="$(ahora_ns)"
  printf '%s' "$FRASE" | gpg --batch --yes --no-tty --quiet \
      --passphrase-fd 0 --pinentry-mode loopback \
      --output "${DIRTMP}/restaurado.sql" --decrypt "${DIRTMP}/volcado.sql.gpg" 2>"${DIRTMP}/gpg2.err"
  estado=$?
  t1="$(ahora_ns)"
  SEG_DESCIFRADO="$(transcurrido "$t0" "$t1")"

  if [ $estado -ne 0 ] || [ ! -s "${DIRTMP}/restaurado.sql" ]; then
    fallar "descifrado" "$(head -c 400 "${DIRTMP}/gpg2.err" 2>/dev/null || echo 'gpg no devolvio contenido')"
    return 1
  fi
  dato "Duracion" "${SEG_DESCIFRADO} s"

  # ── Fase 4: restauración ───────────────────────────────────────────────────
  paso "Fase 4/5 · Restauracion sobre ${BASE_PRUEBA}"
  if ! MYSQL_PWD="$ADMIN_CLAVE" cliente -u "$ADMIN_USUARIO" -h "$ANFITRION" -P "$PUERTO" \
        -e "CREATE DATABASE \`${BASE_PRUEBA}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" \
        >/dev/null 2>"${DIRTMP}/crear.err"; then
    fallar "restauracion" "No se pudo crear la base de prueba: $(head -c 300 "${DIRTMP}/crear.err" 2>/dev/null)"
    return 1
  fi

  t0="$(ahora_ns)"
  MYSQL_PWD="$ADMIN_CLAVE" cliente -u "$ADMIN_USUARIO" -h "$ANFITRION" -P "$PUERTO" \
      --default-character-set=utf8mb4 "$BASE_PRUEBA" < "${DIRTMP}/restaurado.sql" \
      >/dev/null 2>"${DIRTMP}/restaurar.err"
  estado=$?
  t1="$(ahora_ns)"
  SEG_RESTAURACION="$(transcurrido "$t0" "$t1")"

  if [ $estado -ne 0 ]; then
    fallar "restauracion" "$(head -c 400 "${DIRTMP}/restaurar.err" 2>/dev/null)"
    return 1
  fi
  dato "Duracion" "${SEG_RESTAURACION} s"

  # ── Fase 5: verificación ───────────────────────────────────────────────────
  paso "Fase 5/5 · Verificacion por conteo de filas"
  local tablas_prueba="${DIRTMP}/tablas-prueba.txt"
  local restaurado="${DIRTMP}/restaurado.tsv"

  t0="$(ahora_ns)"
  MYSQL_PWD="$ADMIN_CLAVE" listar_tablas "$ADMIN_USUARIO" "$BASE_PRUEBA" > "$tablas_prueba"
  MYSQL_PWD="$ADMIN_CLAVE" contar_filas "$ADMIN_USUARIO" "$BASE_PRUEBA" "$tablas_prueba" > "$restaurado"
  t1="$(ahora_ns)"
  SEG_VERIFICACION="$(transcurrido "$t0" "$t1")"

  CONTEOS_PRUEBA="$(json_conteos "$restaurado")"
  FILAS="$(awk -F'\t' 'NF>=2{ s += $2 } END{ printf "%d", s+0 }' "$restaurado")"

  # Una tabla se acepta si su conteo restaurado cae en el intervalo que el origen
  # mostro alrededor del volcado. Fuera de ahi es una discrepancia real.
  DISCREPANCIAS="$(awk -F'\t' '
    NR==FNR { previo[$1]=$2; next }
    FNR!=NR && FILENAME==ARGV[2] { post[$1]=$2; next }
    { rest[$1]=$2 }
    END {
      printf "{"; sep="";
      for (t in previo) {
        lo = previo[t]+0; hi = post[t]+0;
        if (lo > hi) { tmp = lo; lo = hi; hi = tmp }
        if (!(t in rest)) {
          printf "%s\"%s\":{\"origen_min\":%d,\"origen_max\":%d,\"restaurada\":null}", sep, t, lo, hi; sep=",";
        } else if (rest[t]+0 < lo || rest[t]+0 > hi) {
          printf "%s\"%s\":{\"origen_min\":%d,\"origen_max\":%d,\"restaurada\":%d}", sep, t, lo, hi, rest[t]+0; sep=",";
        }
      }
      for (t in rest) {
        if (!(t in previo)) { printf "%s\"%s\":{\"origen_min\":null,\"origen_max\":null,\"restaurada\":%d}", sep, t, rest[t]+0; sep="," }
      }
      printf "}";
    }' "$previo" "$posterior" "$restaurado")"

  local tablas_restauradas
  tablas_restauradas="$(grep -c . "$tablas_prueba")"
  dato "Tablas restauradas" "$tablas_restauradas"
  dato "Filas restauradas" "$FILAS"
  dato "Duracion" "${SEG_VERIFICACION} s"

  if [ "$DISCREPANCIAS" != "{}" ]; then
    fallar "verificacion" "Hay tablas cuyo conteo no cuadra con el origen: ${DISCREPANCIAS}"
    return 1
  fi

  # Restaurar cero filas sin error es el peor desenlace posible: parece un exito.
  if [ "$FILAS" -eq 0 ]; then
    fallar "verificacion" "La restauracion no dejo ni una fila. Un exito con tablas vacias es falsa confianza."
    return 1
  fi

  VERIFICACION_OK="true"
  return 0
}

ejecutar
RESULTADO_CODIGO=$?

TERMINADA_EN="$(date --iso-8601=seconds)"

if [ "$RESULTADO_CODIGO" -eq 0 ] && [ "$VERIFICACION_OK" = "true" ] && [ "$CIFRADO_OK" = "true" ]; then
  RESULTADO="satisfactoria"
  printf '\n\033[1;32m✓ Prueba satisfactoria\033[0m\n\n' >&2
else
  RESULTADO="fallida"
  printf '\n\033[1;31m✗ Prueba fallida (%s)\033[0m\n\n' "${FASE_FALLIDA:-cifrado}" >&2
  # Si la unica pega fue el cifrador, dejarlo dicho: el resto pudo salir bien.
  if [ -z "$FASE_FALLIDA" ] && [ "$CIFRADO_OK" != "true" ]; then
    FASE_FALLIDA="cifrado"
    ERROR="El volcado no quedo cifrado en AES-256 (${ALGORITMO}), que es el algoritmo que declara el documento del proyecto."
  fi
fi

# ── Acta en JSON ─────────────────────────────────────────────────────────────
# El guion reporta HECHOS MEDIDOS. Las cifras derivadas (el tiempo de
# recuperacion, la antiguedad del respaldo en minutos) y el veredicto los calcula
# el comando de Laravel a partir de estos hechos: quien ejecuta la prueba no se
# pone la nota a si mismo.
emitir_json() {
  cat <<JSON
{
  "version": 1,
  "iniciada_en": "$(json_cadena "$INICIADA_EN")",
  "terminada_en": "$(json_cadena "$TERMINADA_EN")",
  "resultado": "$(json_cadena "$RESULTADO")",
  "fase_fallida": $(json_o_nulo "$FASE_FALLIDA"),
  "error": $(json_o_nulo "$ERROR"),
  "actor": "$(json_cadena "$ACTOR")",
  "anfitrion": "$(json_cadena "$ANFITRION_SO")",
  "comando": "$(json_cadena "$COMANDO")",
  "modo_cliente": $(json_o_nulo "$MODO_CLIENTE"),
  "conexion": "$(json_cadena "$CONEXION")",
  "base_origen": "$(json_cadena "$BASE")",
  "base_prueba": "$(json_cadena "$BASE_PRUEBA")",
  "segundos_volcado": ${SEG_VOLCADO},
  "segundos_cifrado": ${SEG_CIFRADO},
  "segundos_descifrado": ${SEG_DESCIFRADO},
  "segundos_restauracion": ${SEG_RESTAURACION},
  "segundos_verificacion": ${SEG_VERIFICACION},
  "bytes_volcado": ${BYTES_VOLCADO},
  "algoritmo_cifrado": $(json_o_nulo "$ALGORITMO"),
  "cifrado_verificado": ${CIFRADO_OK},
  "huella_volcado": $(json_o_nulo "$HUELLA"),
  "verificacion_superada": ${VERIFICACION_OK},
  "tablas_comparadas": ${TABLAS},
  "filas_comparadas": ${FILAS},
  "conteos_origen": ${CONTEOS_ORIGEN},
  "conteos_restaurada": ${CONTEOS_PRUEBA},
  "discrepancias": ${DISCREPANCIAS},
  "ruta_respaldos": $(json_o_nulo "$RESPALDOS"),
  "respaldos_encontrados": ${RESP_CANT},
  "respaldo_mas_reciente_en": $([ -n "$RESP_EPOCH" ] && json_o_nulo "$(date -d "@${RESP_EPOCH}" --iso-8601=seconds)" || printf 'null'),
  "respaldo_mas_reciente_ruta": $(json_o_nulo "$RESP_RUTA"),
  "salida": "$(json_cadena "$LOG")"
}
JSON
}

if [ "$SALIDA_JSON" -eq 1 ]; then
  emitir_json
else
  printf 'Resultado: %s\n' "$RESULTADO"
  printf 'Recuperacion (descifrado + restauracion + verificacion): %s + %s + %s s\n' \
    "$SEG_DESCIFRADO" "$SEG_RESTAURACION" "$SEG_VERIFICACION"
  printf 'Registre el acta con:\n  bash %s --json | php artisan siem:probar-restauracion --registrar=- --origen=guion\n' "$0"
fi

[ "$RESULTADO" = "satisfactoria" ] && exit 0 || exit 1
