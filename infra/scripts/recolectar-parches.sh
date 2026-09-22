#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
#  MarketGT · Recolección del inventario de parches del sistema operativo
#  Vértice de PROTECCIÓN · métrica «cobertura de parcheo crítico»
#  Control A.8.8 (gestión de vulnerabilidades técnicas)
#
#  POR QUÉ EXISTE ESTE SCRIPT
#
#  La aplicación corre en un contenedor y el contenedor no ve /var/log del
#  anfitrión ni la base de datos de paquetes de Ubuntu. Sin un recolector que
#  corra FUERA del contenedor, la métrica de parcheo no puede medirse, y el
#  panel la declara «sin datos» — que es lo correcto, pero no es el objetivo.
#
#  POR QUÉ NO ESTIMA NADA
#
#  La regla del proyecto es que cada cifra proceda de un hecho registrado. Aquí
#  eso significa tres fuentes locales y comprobables, ninguna adivinada:
#
#    · /var/log/unattended-upgrades/unattended-upgrades-dpkg.log
#         qué paquete se desempaquetó, con qué versión y a qué hora, aplicado
#         por el parcheador automático.
#    · /var/log/dpkg.log
#         lo mismo para las actualizaciones aplicadas a mano. Se distinguen a
#         propósito: «lo aplicó el automatismo» y «lo aplicó una persona» son
#         hechos distintos y un auditor pregunta cuál de los dos fue.
#    · /usr/share/doc/<paquete>/changelog.Debian.gz
#         la fecha de publicación de la corrección, firmada en el pie de la
#         entrada del registro de cambios. Es la única fecha de publicación que
#         existe EN LA MÁQUINA, sin red y sin servicios externos.
#
#  LO QUE NO SE PUEDE SABER, SE DECLARA
#
#  No todo paquete trae su registro de cambios instalado, y la entrada de una
#  versión concreta puede no estar. Cuando falta, el campo «publicado_en» sale
#  nulo y el registro lleva escrito POR QUÉ falta. Ese parche cuenta en el
#  total y NO cuenta en el plazo. Promediar sobre una fecha supuesta sería
#  justamente el hallazgo que esta métrica pretende evitar.
#
#  Para los parches PENDIENTES no hay fecha de publicación local: el paquete
#  todavía no está instalado, así que su registro de cambios tampoco. Lo que sí
#  es un hecho medible es CUÁNDO LO VIMOS PENDIENTE POR PRIMERA VEZ, y eso lo
#  registra la base a partir de la primera línea que emite este script. Es una
#  cota inferior del retraso, no el retraso real, y la métrica lo dice así.
#
#  FORMA DE LA SALIDA
#
#  Un objeto JSON por línea, en modo añadir. El archivo es un diario de
#  cambios, no una foto: cada pasada escribe una línea «recoleccion» con las
#  condiciones de la medición y solo los parches cuyo estado cambió respecto de
#  la pasada anterior. Así el archivo crece despacio y cada línea es un hecho
#  fechado, que es lo que se le enseña a un auditor.
#
#  Uso:
#    sudo bash infra/scripts/recolectar-parches.sh
#    sudo bash infra/scripts/recolectar-parches.sh --salida /ruta/archivo.jsonl
#    sudo bash infra/scripts/recolectar-parches.sh --dias 180 --completo
#    bash infra/scripts/recolectar-parches.sh --probar   (no escribe nada)
#
#  Programación recomendada (ver «registros necesarios» en la documentación):
#    /etc/cron.d/marketgt-parches
# ─────────────────────────────────────────────────────────────────────────────
set -uo pipefail

VERSION_RECOLECTOR="1.0.0"

SALIDA="${SALIDA:-/var/lib/marketgt/parches/inventario-parches.jsonl}"
ESTADO_PREVIO="${ESTADO_PREVIO:-}"
DIAS="${DIAS:-400}"
COMPLETO=0
PROBAR=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --salida)   SALIDA="${2:-}"; shift 2 ;;
        --estado)   ESTADO_PREVIO="${2:-}"; shift 2 ;;
        --dias)     DIAS="${2:-400}"; shift 2 ;;
        --completo) COMPLETO=1; shift ;;          # reemite todo, ignora el estado previo
        --probar)   PROBAR=1; shift ;;            # escribe por pantalla, no toca el archivo
        --ayuda|-h) sed -n '2,60p' "$0"; exit 0 ;;
        *) printf 'Opción desconocida: %s\n' "$1" >&2; exit 2 ;;
    esac
done

[[ -n "$ESTADO_PREVIO" ]] || ESTADO_PREVIO="$(dirname "$SALIDA")/estado-anterior.json"

if ! command -v python3 >/dev/null 2>&1; then
    printf 'Falta python3, que Ubuntu 24.04 trae de serie. No se recolecta nada.\n' >&2
    exit 1
fi

# El directorio se crea con permiso de lectura para todos a propósito: el
# contenedor lo monta en solo lectura y su usuario no es root. Escribir aquí
# solo puede hacerlo root, que es quien tiene acceso a los registros de origen.
if [[ $PROBAR -eq 0 ]]; then
    mkdir -p "$(dirname "$SALIDA")" 2>/dev/null || {
        printf 'No se puede crear %s. Ejecute con sudo.\n' "$(dirname "$SALIDA")" >&2
        exit 1
    }
    chmod 755 "$(dirname "$SALIDA")" 2>/dev/null || true
fi

# El grueso del trabajo va en python3 y no en awk por una razón concreta: hay
# que codificar JSON con comillas y acentos dentro, y hay que interpretar fechas
# en dos formatos distintos (RFC 2822 del registro de cambios y la hora local de
# dpkg). Hacerlo con tuberías de texto produce exactamente los errores que esta
# métrica no se puede permitir: una fecha mal leída se convierte en un plazo
# inventado sin que nada avise.
SALIDA="$SALIDA" ESTADO_PREVIO="$ESTADO_PREVIO" DIAS="$DIAS" \
COMPLETO="$COMPLETO" PROBAR="$PROBAR" VERSION_RECOLECTOR="$VERSION_RECOLECTOR" \
python3 - <<'PYTHON'
import glob
import gzip
import hashlib
import json
import os
import re
import socket
import subprocess
import sys
from datetime import datetime, timedelta, timezone
from email.utils import parsedate_to_datetime

SALIDA = os.environ["SALIDA"]
ESTADO_PREVIO = os.environ["ESTADO_PREVIO"]
DIAS = int(os.environ.get("DIAS") or 400)
COMPLETO = os.environ.get("COMPLETO") == "1"
PROBAR = os.environ.get("PROBAR") == "1"
VERSION_RECOLECTOR = os.environ.get("VERSION_RECOLECTOR", "0")

AHORA = datetime.now(timezone.utc)
CORTE = AHORA - timedelta(days=DIAS)

RUTA_UU_LOG = "/var/log/unattended-upgrades/unattended-upgrades.log"
RUTA_UU_DPKG = "/var/log/unattended-upgrades/unattended-upgrades-dpkg.log"
RUTA_DPKG = "/var/log/dpkg.log"
RUTA_APT_CHECK = "/usr/lib/update-notifier/apt-check"

ESTADO_PENDIENTE = "pendiente"
ESTADO_APLICADO = "aplicado"
ESTADO_NO_APLICABLE = "no_aplicable"

# Cada fuente que se intenta leer deja aquí su resultado, incluso cuando falla.
# Una fuente ilegible tiene que salir en el registro: si el recolector se calla,
# la métrica baja sin que nadie sepa que fue por un permiso y no por el parcheo.
fuentes = []
avisos = []


def anotar_fuente(ruta, leida, detalle=""):
    fuentes.append({"ruta": ruta, "leida": bool(leida), "detalle": detalle})


def abrir_texto(ruta):
    """Lee un archivo de registro, comprimido o no, sin romperse por bytes sueltos."""
    if ruta.endswith(".gz"):
        return gzip.open(ruta, "rt", encoding="utf-8", errors="replace")
    return open(ruta, "rt", encoding="utf-8", errors="replace")


def rotados(base):
    """El registro vigente y sus rotaciones, del más nuevo al más viejo."""
    encontrados = [base] if os.path.isfile(base) else []
    encontrados += sorted(glob.glob(base + ".*"))
    return encontrados


def a_utc(momento):
    return momento.astimezone(timezone.utc).replace(microsecond=0).isoformat()


def local_a_utc(texto_fecha, texto_hora):
    """Las horas de dpkg no llevan zona: son hora local del anfitrión.

    astimezone() sobre un datetime sin zona aplica la zona del sistema, que es
    justamente la que escribió esas líneas. Asumir UTC aquí desplazaría todos
    los plazos varias horas, y en una ventana de 72 h eso decide si cumple.
    """
    try:
        ingenuo = datetime.strptime(texto_fecha + " " + texto_hora, "%Y-%m-%d %H:%M:%S")
    except ValueError:
        return None
    return ingenuo.astimezone()


def sin_epoca(version):
    """Quita la época (1:) para poder comparar con el registro de cambios."""
    return re.sub(r"^\d+:", "", (version or "").strip())


def huella_de(anfitrion, paquete, arquitectura, version):
    crudo = "|".join([anfitrion, paquete, arquitectura or "", version])
    return hashlib.sha256(crudo.encode("utf-8")).hexdigest()


# ─── Fecha de publicación de la corrección ──────────────────────────────────
#
# El pie de cada entrada del registro de cambios («-- Autor <correo>  fecha»)
# lleva la fecha en que se firmó la subida. Para una actualización de seguridad
# de Ubuntu esa es la fecha en que la corrección quedó publicada, y está EN LA
# MÁQUINA: no hace falta red ni ningún servicio externo que pudiera mentir.

CABECERA_CAMBIOS = re.compile(r"^(\S+)\s+\(([^)]+)\)\s+([^;]+);\s+urgency=", re.IGNORECASE)
PIE_CAMBIOS = re.compile(r"^ -- (.*?)  +(.+?)\s*$")
PATRON_CVE = re.compile(r"CVE-\d{4}-\d{4,7}", re.IGNORECASE)

cache_cambios = {}


def rutas_registro_cambios(paquete):
    base = "/usr/share/doc/" + paquete.split(":")[0]
    return [
        base + "/changelog.Debian.gz",
        base + "/changelog.gz",
        base + "/changelog.Debian",
        base + "/changelog",
    ]


def entradas_registro_cambios(paquete):
    """Devuelve las entradas del registro de cambios instalado del paquete."""
    if paquete in cache_cambios:
        return cache_cambios[paquete]

    ruta_usada = None
    for candidata in rutas_registro_cambios(paquete):
        if os.path.isfile(candidata):
            ruta_usada = candidata
            break

    if ruta_usada is None:
        cache_cambios[paquete] = (None, [])
        return cache_cambios[paquete]

    entradas = []
    actual = None
    try:
        with abrir_texto(ruta_usada) as manejador:
            for linea in manejador:
                cabecera = CABECERA_CAMBIOS.match(linea)
                if cabecera:
                    actual = {
                        "version": sin_epoca(cabecera.group(2)),
                        "distribucion": cabecera.group(3).strip(),
                        "cuerpo": [],
                        "fecha": None,
                    }
                    entradas.append(actual)
                    # Doscientas entradas alcanzan de sobra: se busca una versión
                    # reciente, y algunos registros de cambios tienen miles.
                    if len(entradas) > 200:
                        break
                    continue

                if actual is None:
                    continue

                pie = PIE_CAMBIOS.match(linea)
                if pie:
                    try:
                        actual["fecha"] = parsedate_to_datetime(pie.group(2))
                    except (TypeError, ValueError):
                        actual["fecha"] = None
                    actual = None
                    continue

                actual["cuerpo"].append(linea)
    except OSError as error:
        cache_cambios[paquete] = (None, [])
        avisos.append("No se pudo leer %s: %s" % (ruta_usada, error))
        return cache_cambios[paquete]

    cache_cambios[paquete] = (ruta_usada, entradas)
    return cache_cambios[paquete]


def datos_de_publicacion(paquete, version):
    """Fecha de publicación, carácter de seguridad y CVE de una versión concreta.

    Devuelve siempre el motivo por el que no encontró la fecha, cuando no la
    encuentra. Un nulo sin explicación no le sirve a nadie en una auditoría.
    """
    vacio = {
        "publicado_en": None,
        "fuente_publicacion": None,
        "es_seguridad": None,
        "identificadores_cve": [],
        "nota_publicacion": None,
    }

    ruta, entradas = entradas_registro_cambios(paquete)

    if ruta is None:
        vacio["nota_publicacion"] = (
            "El paquete no tiene registro de cambios instalado en /usr/share/doc, "
            "de modo que la fecha de publicacion no consta en esta maquina."
        )
        return vacio

    objetivo = sin_epoca(version)
    sin_reconstruccion = re.sub(r"\+b\d+$", "", objetivo)

    elegida = None
    for entrada in entradas:
        if entrada["version"] == objetivo or entrada["version"] == sin_reconstruccion:
            elegida = entrada
            break

    if elegida is None:
        vacio["nota_publicacion"] = (
            "El registro de cambios existe pero no contiene la entrada de la version %s. "
            "No se toma la entrada mas reciente en su lugar: seria una fecha de otra version."
            % version
        )
        return vacio

    cuerpo = "".join(elegida["cuerpo"])
    cves = sorted({identificador.upper() for identificador in PATRON_CVE.findall(cuerpo)})
    distribucion = elegida["distribucion"].lower()

    # Dos hechos independientes marcan una corrección de seguridad: haberse
    # publicado en un archivo «-security» y citar un CVE. Basta uno de los dos.
    es_seguridad = ("security" in distribucion) or bool(cves) or ("SECURITY UPDATE" in cuerpo.upper())

    resultado = {
        "publicado_en": a_utc(elegida["fecha"]) if elegida["fecha"] else None,
        "fuente_publicacion": "%s (entrada %s, distribucion %s)" % (ruta, elegida["version"], elegida["distribucion"]),
        "es_seguridad": bool(es_seguridad),
        "identificadores_cve": cves,
        "nota_publicacion": None,
    }

    if elegida["fecha"] is None:
        resultado["nota_publicacion"] = (
            "La entrada de la version existe pero su pie no trae una fecha legible."
        )

    return resultado


# ─── Parches aplicados ──────────────────────────────────────────────────────

PATRON_INICIO_BLOQUE = re.compile(r"^Log started:\s+(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2}:\d{2})")
PATRON_DESEMPAQUE = re.compile(
    r"^Unpacking\s+(\S+?)(?::(\S+?))?\s+\(([^)]+)\)(?:\s+over\s+\(([^)]+)\))?"
)
PATRON_DPKG_UPGRADE = re.compile(
    r"^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2}:\d{2})\s+upgrade\s+(\S+?)(?::(\S+?))?\s+(\S+)\s+(\S+)\s*$"
)

aplicados = {}


def registrar_aplicado(paquete, arquitectura, version, version_anterior, momento, fuente, aplicado_por):
    if not paquete or not version or momento is None:
        return
    if momento < CORTE:
        return

    clave = (paquete, arquitectura or "", sin_epoca(version))
    anterior = aplicados.get(clave)

    # Si el mismo paquete y versión aparece en las dos fuentes, manda la primera
    # marca de tiempo (la aplicación ocurrió una sola vez) y manda la atribución
    # al parcheador automático, que es la que el control A.8.8 quiere demostrar.
    if anterior is None or momento < anterior["momento"]:
        aplicados[clave] = {
            "paquete": paquete,
            "arquitectura": arquitectura or None,
            "version": version,
            "version_anterior": version_anterior,
            "momento": momento,
            "fuente": fuente,
            "aplicado_por": aplicado_por,
        }
    elif aplicado_por == "proceso:unattended-upgrades":
        anterior["aplicado_por"] = aplicado_por
        anterior["fuente"] = fuente


def leer_unattended_dpkg():
    archivos = rotados(RUTA_UU_DPKG)
    if not archivos:
        anotar_fuente(RUTA_UU_DPKG, False, "No existe: el parcheador automatico no ha aplicado nada todavia, o no esta habilitado.")
        return

    for archivo in archivos:
        try:
            momento_bloque = None
            with abrir_texto(archivo) as manejador:
                for linea in manejador:
                    inicio = PATRON_INICIO_BLOQUE.match(linea)
                    if inicio:
                        momento_bloque = local_a_utc(inicio.group(1), inicio.group(2))
                        continue

                    desempaque = PATRON_DESEMPAQUE.match(linea)
                    if desempaque and momento_bloque is not None:
                        registrar_aplicado(
                            desempaque.group(1),
                            desempaque.group(2),
                            desempaque.group(3),
                            desempaque.group(4),
                            momento_bloque,
                            "%s (bloque iniciado %s)" % (archivo, momento_bloque.isoformat()),
                            "proceso:unattended-upgrades",
                        )
            anotar_fuente(archivo, True)
        except OSError as error:
            anotar_fuente(archivo, False, str(error))


def leer_dpkg_log():
    archivos = rotados(RUTA_DPKG)
    if not archivos:
        anotar_fuente(RUTA_DPKG, False, "No existe.")
        return

    for archivo in archivos:
        try:
            with abrir_texto(archivo) as manejador:
                for linea in manejador:
                    coincidencia = PATRON_DPKG_UPGRADE.match(linea)
                    if not coincidencia:
                        continue
                    momento = local_a_utc(coincidencia.group(1), coincidencia.group(2))
                    registrar_aplicado(
                        coincidencia.group(3),
                        coincidencia.group(4),
                        coincidencia.group(6),
                        coincidencia.group(5),
                        momento,
                        archivo,
                        "persona o automatismo externo (apt/dpkg)",
                    )
            anotar_fuente(archivo, True)
        except OSError as error:
            anotar_fuente(archivo, False, str(error))


# ─── Parches pendientes ─────────────────────────────────────────────────────

PATRON_INST = re.compile(
    r"^Inst\s+(\S+)\s+(?:\[([^\]]+)\]\s+)?\(([^\s]+)\s+(.*?)(?:\s+\[([^\]]+)\])?\)\s*$"
)

pendientes = {}


def leer_pendientes():
    orden = ["apt-get", "-s", "-o", "Debug::NoLocking=true", "-o", "APT::Get::Show-User-Simulation-Note=false", "upgrade"]
    try:
        proceso = subprocess.run(orden, capture_output=True, text=True, timeout=180)
    except (OSError, subprocess.SubprocessError) as error:
        anotar_fuente(" ".join(orden), False, str(error))
        return

    if proceso.returncode != 0:
        anotar_fuente(" ".join(orden), False, "codigo %d: %s" % (proceso.returncode, (proceso.stderr or "").strip()[:300]))
        return

    for linea in proceso.stdout.splitlines():
        coincidencia = PATRON_INST.match(linea.strip())
        if not coincidencia:
            continue

        paquete = coincidencia.group(1)
        version_anterior = coincidencia.group(2)
        version = coincidencia.group(3)
        origen = (coincidencia.group(4) or "").strip()
        arquitectura = coincidencia.group(5)

        clave = (paquete, arquitectura or "", sin_epoca(version))
        pendientes[clave] = {
            "paquete": paquete,
            "arquitectura": arquitectura,
            "version": version,
            "version_anterior": version_anterior,
            "origen_archivo": origen or None,
            # Un paquete pendiente no está instalado, así que su registro de
            # cambios no está en la máquina: lo único que se sabe con certeza es
            # de qué archivo viene, y el archivo «-security» es un hecho, no una
            # suposición.
            "es_seguridad": "security" in origen.lower() if origen else None,
        }

    anotar_fuente(" ".join(orden), True, "%d actualizaciones pendientes" % len(pendientes))


def corroboracion_pendientes():
    """Segunda opinión sobre cuántas pendientes son de seguridad.

    No alimenta la métrica: queda en la línea de recolección para que un auditor
    pueda contrastar el conteo del recolector con el de la propia herramienta de
    Ubuntu. Si las dos cifras no coinciden, hay algo que explicar.
    """
    resultado = {}

    if os.path.isfile(RUTA_APT_CHECK) and os.access(RUTA_APT_CHECK, os.X_OK):
        try:
            proceso = subprocess.run(
                [RUTA_APT_CHECK, "--human-readable"], capture_output=True, text=True, timeout=120
            )
            resultado["apt_check"] = (proceso.stdout or proceso.stderr or "").strip()[:500]
        except (OSError, subprocess.SubprocessError) as error:
            resultado["apt_check"] = "fallo: %s" % error
    else:
        resultado["apt_check"] = (
            "no disponible: falta %s (paquete update-notifier-common)" % RUTA_APT_CHECK
        )

    try:
        proceso = subprocess.run(
            ["bash", "-lc", "apt list --upgradable 2>/dev/null | grep -ci security"],
            capture_output=True,
            text=True,
            timeout=120,
        )
        resultado["apt_list_security"] = (proceso.stdout or "").strip() or "0"
    except (OSError, subprocess.SubprocessError) as error:
        resultado["apt_list_security"] = "fallo: %s" % error

    return resultado


# ─── Composición de los registros ───────────────────────────────────────────

def leer_estado_previo():
    if COMPLETO or not os.path.isfile(ESTADO_PREVIO):
        return {}
    try:
        with open(ESTADO_PREVIO, "rt", encoding="utf-8") as manejador:
            datos = json.load(manejador)
        return datos.get("parches", {}) if isinstance(datos, dict) else {}
    except (OSError, ValueError) as error:
        avisos.append("Estado previo ilegible (%s): se reemite el inventario completo." % error)
        return {}


def main():
    anfitrion = socket.getfqdn() or socket.gethostname() or "desconocido"

    leer_unattended_dpkg()
    leer_dpkg_log()
    leer_pendientes()

    if os.path.isfile(RUTA_UU_LOG):
        anotar_fuente(RUTA_UU_LOG, True, "presente; las horas de aplicacion se toman del registro dpkg asociado")
    else:
        anotar_fuente(RUTA_UU_LOG, False, "No existe.")

    previo = leer_estado_previo()
    registros = []
    estado_nuevo = {}

    momento_recoleccion = a_utc(AHORA)
    invocado_por = os.environ.get("SUDO_USER") or os.environ.get("USER") or "desconocido"
    # Quién dispara la recolección importa: el cron es un proceso, una ejecución
    # a mano es una persona, y la procedencia del dato cambia según cuál fue.
    disparo = "proceso:cron" if not sys.stdin.isatty() else "persona:%s" % invocado_por

    for clave, pendiente in pendientes.items():
        huella = huella_de(anfitrion, pendiente["paquete"], pendiente["arquitectura"], sin_epoca(pendiente["version"]))
        anterior = previo.get(huella, {})
        visto_desde = anterior.get("visto_pendiente_desde") or momento_recoleccion

        registro = {
            "tipo": "parche",
            "huella": huella,
            "anfitrion": anfitrion,
            "paquete": pendiente["paquete"],
            "arquitectura": pendiente["arquitectura"],
            "version": pendiente["version"],
            "version_anterior": pendiente["version_anterior"],
            "estado": ESTADO_PENDIENTE,
            "es_seguridad": pendiente["es_seguridad"],
            "origen_archivo": pendiente["origen_archivo"],
            "publicado_en": None,
            "fuente_publicacion": None,
            "nota_publicacion": (
                "El paquete no esta instalado, de modo que su registro de cambios no esta en la "
                "maquina y la fecha de publicacion no se puede leer sin red. El plazo de este "
                "parche solo puede medirse desde que el recolector lo vio pendiente."
            ),
            "identificadores_cve": [],
            "aplicado_en": None,
            "fuente_aplicacion": None,
            "aplicado_por": None,
            "visto_pendiente_desde": visto_desde,
            "desfase_horas": None,
            "recolectado_en": momento_recoleccion,
            "recolectado_por": disparo,
            "version_recolector": VERSION_RECOLECTOR,
        }

        estado_nuevo[huella] = {"estado": ESTADO_PENDIENTE, "visto_pendiente_desde": visto_desde}

        if anterior.get("estado") != ESTADO_PENDIENTE:
            registros.append(registro)

    for clave, aplicado in aplicados.items():
        publicacion = datos_de_publicacion(aplicado["paquete"], aplicado["version"])
        huella = huella_de(anfitrion, aplicado["paquete"], aplicado["arquitectura"], sin_epoca(aplicado["version"]))
        anterior = previo.get(huella, {})
        visto_desde = anterior.get("visto_pendiente_desde")

        desfase = None
        if publicacion["publicado_en"]:
            publicado = datetime.fromisoformat(publicacion["publicado_en"])
            desfase = round((aplicado["momento"] - publicado).total_seconds() / 3600.0, 2)
            # Un desfase negativo significa que la máquina instaló el paquete
            # antes de la fecha firmada en el registro de cambios. Eso no puede
            # pasar salvo que el reloj esté mal, así que se declara en lugar de
            # regalarle un cero al plazo.
            if desfase < 0:
                avisos.append(
                    "%s %s: fecha de aplicacion anterior a la de publicacion (%.2f h). "
                    "Revisar el reloj del anfitrion." % (aplicado["paquete"], aplicado["version"], desfase)
                )

        registro = {
            "tipo": "parche",
            "huella": huella,
            "anfitrion": anfitrion,
            "paquete": aplicado["paquete"],
            "arquitectura": aplicado["arquitectura"],
            "version": aplicado["version"],
            "version_anterior": aplicado["version_anterior"],
            "estado": ESTADO_APLICADO,
            "es_seguridad": publicacion["es_seguridad"],
            "origen_archivo": None,
            "publicado_en": publicacion["publicado_en"],
            "fuente_publicacion": publicacion["fuente_publicacion"],
            "nota_publicacion": publicacion["nota_publicacion"],
            "identificadores_cve": publicacion["identificadores_cve"],
            "aplicado_en": a_utc(aplicado["momento"]),
            "fuente_aplicacion": aplicado["fuente"],
            "aplicado_por": aplicado["aplicado_por"],
            "visto_pendiente_desde": visto_desde,
            "desfase_horas": desfase,
            "recolectado_en": momento_recoleccion,
            "recolectado_por": disparo,
            "version_recolector": VERSION_RECOLECTOR,
        }

        estado_nuevo[huella] = {"estado": ESTADO_APLICADO, "visto_pendiente_desde": visto_desde}

        if anterior.get("estado") != ESTADO_APLICADO:
            registros.append(registro)

    # Un parche que estaba pendiente y ya no figura ni pendiente ni aplicado se
    # cierra de forma explícita. Sin esta línea la base lo seguiría contando
    # como pendiente vencido para siempre, y la métrica bajaría por un paquete
    # que fue retenido, sustituido o eliminado. Un incumplimiento imaginario es
    # tan falso como un cumplimiento imaginario.
    for huella, anterior in previo.items():
        if huella in estado_nuevo or anterior.get("estado") != ESTADO_PENDIENTE:
            continue
        registros.append({
            "tipo": "parche",
            "huella": huella,
            "anfitrion": anfitrion,
            "estado": ESTADO_NO_APLICABLE,
            "nota_publicacion": (
                "Dejo de figurar como pendiente sin constar aplicado: el paquete pudo ser "
                "retenido, reemplazado por otra version o eliminado del sistema."
            ),
            "visto_pendiente_desde": anterior.get("visto_pendiente_desde"),
            "recolectado_en": momento_recoleccion,
            "recolectado_por": disparo,
            "version_recolector": VERSION_RECOLECTOR,
        })

    try:
        version_so = subprocess.run(
            ["lsb_release", "-ds"], capture_output=True, text=True, timeout=30
        ).stdout.strip()
    except (OSError, subprocess.SubprocessError):
        version_so = ""

    linea_recoleccion = {
        "tipo": "recoleccion",
        "recolectado_en": momento_recoleccion,
        "recolectado_por": disparo,
        "version_recolector": VERSION_RECOLECTOR,
        "anfitrion": anfitrion,
        "sistema_operativo": version_so or "desconocido",
        "ejecutado_como_root": os.geteuid() == 0,
        "dias_observados": DIAS,
        "fuentes": fuentes,
        "corroboracion": corroboracion_pendientes(),
        "conteos": {
            "aplicados_vistos": len(aplicados),
            "pendientes_vistos": len(pendientes),
            "registros_emitidos": len(registros),
        },
        "avisos": avisos,
    }

    lineas = [json.dumps(linea_recoleccion, ensure_ascii=False)]
    lineas += [json.dumps(registro, ensure_ascii=False) for registro in registros]

    if PROBAR:
        for linea in lineas:
            print(linea)
        print(
            "\n[prueba] %d registros de cambio y 1 linea de recoleccion. No se escribio nada."
            % len(registros),
            file=sys.stderr,
        )
        return 0

    # Se añade con una sola escritura y un salto final garantizado. La ingesta
    # de la aplicación descarta la última línea si no termina en salto, de modo
    # que una escritura a medias no se convierte en un registro truncado.
    try:
        with open(SALIDA, "at", encoding="utf-8") as manejador:
            manejador.write("\n".join(lineas) + "\n")
            manejador.flush()
            os.fsync(manejador.fileno())
        os.chmod(SALIDA, 0o644)
    except OSError as error:
        print("No se pudo escribir %s: %s" % (SALIDA, error), file=sys.stderr)
        return 1

    # El estado se guarda DESPUÉS de escribir la salida. Si el guardado fallara,
    # la próxima pasada reemitiría los mismos registros: duplicar un hecho es
    # inofensivo porque la ingesta agrupa por huella, pero perderlo no lo es.
    try:
        temporal = ESTADO_PREVIO + ".tmp"
        with open(temporal, "wt", encoding="utf-8") as manejador:
            json.dump(
                {"actualizado_en": momento_recoleccion, "parches": estado_nuevo},
                manejador,
                ensure_ascii=False,
            )
        os.replace(temporal, ESTADO_PREVIO)
        os.chmod(ESTADO_PREVIO, 0o600)
    except OSError as error:
        print("Aviso: no se pudo guardar el estado previo (%s)." % error, file=sys.stderr)

    print(
        "Recoleccion completada: %d aplicados y %d pendientes observados, %d registros nuevos en %s"
        % (len(aplicados), len(pendientes), len(registros), SALIDA)
    )
    return 0


sys.exit(main())
PYTHON
