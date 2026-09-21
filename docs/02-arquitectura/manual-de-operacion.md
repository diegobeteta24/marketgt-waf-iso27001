# Manual de Operación

| | |
|---|---|
| **Identificador** | ARQ-002 |
| **Versión** | 1.0 |
| **Fecha de emisión** | 21 de septiembre de 2026 |
| **Elaborado por** | Nivel operativo — Departamento de TI |
| **Revisado por** | Nivel táctico — Gerencia de Operaciones |
| **Clasificación** | Uso interno del proyecto |
| **Control del Anexo A** | A.5.37 · Procedimientos operativos documentados |
| **Próxima revisión** | 21 de marzo de 2027 |

---

## 1. Para quién es este manual

Para quien tenga que levantar, operar o reparar el sistema, incluido quien no lo construyó. Está
escrito bajo el supuesto de que se lee bajo presión, de modo que cada procedimiento empieza por el
comando y termina por la explicación, y no al revés.

Las rutas se indican desde la raíz del repositorio. Los comandos de la aplicación se ejecutan dentro
del entorno Linux; en el equipo de desarrollo con Windows, eso significa anteponer la invocación del
subsistema:

```bash
wsl -d Ubuntu-24.04 -u root -- bash -lc 'cd /mnt/e/Seguridad/app && php artisan <comando>'
```

## 2. Mapa del sistema

| Servicio | Contenedor | Qué hace | Red |
|---|---|---|---|
| Cortafuegos de aplicación | `marketgt-waf` | Nginx con ModSecurity 3 y OWASP Core Rule Set 4.29. Único punto expuesto a Internet, en los puertos 80 y 443 | `edge` y `appnet` |
| Aplicación | `marketgt-app` | Laravel: tienda, autenticación y panel de monitoreo | `appnet` |
| Base de datos | `marketgt-db` | MariaDB | `appnet` |
| Respaldo estático | `marketgt-respaldo` | Página de mantenimiento que sirve de origen alternativo al cortafuegos | `appnet` |

La red `appnet` está declarada como interna: **la aplicación y la base de datos no son alcanzables
desde Internet ni desde el anfitrión**. El único camino hacia la aplicación atraviesa el cortafuegos, y
esa es la evidencia de que no existe forma de eludir la inspección.

| Ruta publicada | Para qué sirve | Quién entra |
|---|---|---|
| `/` | Tienda | Público |
| `/siem` | Panel de monitoreo | Rol de administrador o auditor |
| `/seguridad/sesiones` | Sesiones activas del propio usuario | Sesión iniciada con reautenticación |
| `/settings` | Perfil, segundo factor y credenciales de clave pública | Sesión iniciada |
| `/demo` | Consola de demostración | Protegida por secreto de cabecera |

## 3. Levantar el sistema por primera vez

### 3.1 Entorno de desarrollo

```bash
sudo bash infra/scripts/00-setup-wsl.sh        # Docker, Compose y el instrumental de auditoría
cp infra/docker/.env.example infra/docker/.env # y rellenar los secretos
docker compose -f infra/docker/docker-compose.yml up -d
```

El archivo `infra/docker/.env` **nunca se incorpora al repositorio**. Contiene la contraseña de la base
de datos, la del superusuario del motor y el secreto de la consola de demostración. Los tres deben
generarse aleatorios y con longitud suficiente; los valores de ejemplo de la plantilla no sirven para
nada más que para recordar qué variables existen.

### 3.2 Entorno de producción

Se ejecuta en este orden. Saltarse el segundo paso deja el servidor sin endurecer y publicado, que es
la peor combinación posible.

```bash
# 1. Desde el equipo propio, con gcloud autenticado
bash infra/scripts/03-crear-vm-gcp.sh

# 2. Dentro del servidor recién creado
sudo bash infra/scripts/02-hardening-servidor.sh

# 3. Apuntar el nombre de dominio hacia la dirección pública
sudo mkdir -p /etc/marketgt
echo 'DUCKDNS_SUBDOMINIO=marketgt'    | sudo tee    /etc/marketgt/dns.env
echo 'DUCKDNS_TOKEN=<token>'          | sudo tee -a /etc/marketgt/dns.env
sudo chmod 600 /etc/marketgt/dns.env
sudo bash infra/scripts/05-actualizar-dns.sh

# 4. Desplegar
sudo bash infra/scripts/04-desplegar.sh marketgt.duckdns.org
```

**Regla que no se incumple.** En la consola de Google Cloud, nadie pulsa «Activar cuenta completa» ni
«Upgrade». Mientras la cuenta permanezca en modalidad de prueba no puede generarse ningún cargo: al
agotarse el crédito los recursos se detienen. Esa regla es la que sostiene la restricción de costo cero
del proyecto, conforme a la decisión DA-002.

El guion de despliegue es idempotente: si vuelve a ejecutarse, conserva los secretos ya generados.
Termina comprobando dos cosas por su cuenta —que el sitio responde y que una inyección evidente
devuelve 403— y las imprime. Si la segunda comprobación no devuelve 403, **el motor de reglas no está
aplicando y no hay proyecto que demostrar**: se revisa `MODSEC_RULE_ENGINE` antes que ninguna otra cosa.

## 4. Verificar que todo está en pie

Esta secuencia se ejecuta al inicio de cada turno de guardia y antes de cualquier demostración.

```bash
# Estado de los contenedores: los cuatro deben estar arriba
docker compose -f infra/docker/docker-compose.yml ps

# El sitio responde
curl -sk -o /dev/null -w '%{http_code}\n' https://marketgt.duckdns.org/

# El cortafuegos bloquea: TIENE que devolver 403
curl -sk -o /dev/null -w '%{http_code}\n' \
     "https://marketgt.duckdns.org/?id=1%27%20OR%201%3D1--"

# Solo 80 y 443 abiertos, comprobado desde fuera
nmap -Pn -p- marketgt.duckdns.org

# El registro de auditoría se está escribiendo
bash infra/scripts/leer-audit-log.sh | tail -5

# La ingesta avanza
php artisan siem:ingerir-waf
```

| Comprobación | Resultado esperado | Si falla |
|---|---|---|
| Contenedores | Cuatro en ejecución | Sección 8.1 |
| Sitio | 200 o 302 | Sección 8.2 |
| Inyección de prueba | **403** | Sección 8.3 |
| Puertos | Solo 80 y 443 | Revisar el cortafuegos de red del anfitrión y del proveedor |
| Registro de auditoría | Líneas JSON recientes | Sección 8.4 |
| Ingesta | Eventos nuevos procesados | Sección 8.4 |

## 5. Operación diaria del monitoreo

### 5.1 Los dos procesos que deben estar corriendo

La detección no funciona si depende de que alguien recuerde lanzarla. Estos dos comandos son el vértice
de detección en funcionamiento:

```bash
# Ingesta continua del registro del cortafuegos
php artisan siem:ingerir-waf --seguir --intervalo=5

# Motor de correlación, pasada cada minuto
php artisan siem:correlacionar --continuo --intervalo=60
```

La primera vez, y solo la primera, hay que sembrar las reglas de correlación:

```bash
php artisan siem:correlacionar --sembrar-reglas
```

También pueden ingerirse las otras dos fuentes de forma explícita:

```bash
php artisan siem:ingerir-waf --formato=aplicacion
php artisan siem:ingerir-waf --formato=sistema
```

### 5.2 Rutina del turno de guardia

Conforme a POL-003 sección 4.2, dos veces al día en ventana activa:

| Paso | Dónde | Qué se busca |
|---|---|---|
| 1 | `/siem/alertas` | Alertas en estado `nueva`. Se triaan según POL-002 sección 6.2 |
| 2 | `/siem/tablero` | Desviación de las metas del triángulo |
| 3 | `/siem/metricas` | Métricas marcadas como «sin datos», que indican ingesta detenida |
| 4 | `/siem/eventos` | **Eventos con regla de ataque activada y petición no interrumpida.** Es la señal de que un control falló |
| 5 | Terminal | Que el marcador de ingesta avance |

El paso 4 es el más importante del turno. Un evento bloqueado es el sistema funcionando; un evento
**no** bloqueado con una regla de ataque activada es un incidente de severidad crítica conforme a
PR-02, y es lo único de esta lista que exige llamar por teléfono.

### 5.3 Leer el registro del cortafuegos a mano

```bash
bash infra/scripts/leer-audit-log.sh
```

El registro es JSON con un objeto por línea. Los campos que importan durante un incidente:

| Campo | Para qué |
|---|---|
| `transaction.unique_id` | **La clave del incidente.** Se cita en toda la bitácora y permite reconstruir la petición completa |
| `transaction.is_interrupted` | `true` significa que el cortafuegos cortó; `false` significa que pasó |
| `transaction.client_ip` | Origen, para bloquear o para descartar un falso positivo |
| `transaction.request.uri` y `.body` | El vector empleado |
| `transaction.messages[].details.ruleId` | Qué regla actuó. Las reglas propias del proyecto ocupan el espacio 15000-15121; el resto pertenece al Core Rule Set |
| `transaction.response.http_code` | Qué devolvió el servidor |

La puntuación de anomalía **no es un campo propio**: aparece dentro del texto de los mensajes de las
reglas 949110 y 980170. Es una particularidad del formato que confunde a quien lo lee por primera vez.

### 5.4 Vigilancia de integridad del contenido indexable

```bash
php artisan seo:vigilar                                   # comparar contra la línea base
php artisan seo:vigilar --sellar --nota="Alta de catálogo" # sellar un cambio autorizado
```

Un cambio legítimo del sitio debe sellarse con su justificación. Si no se sella, la vigilancia lo
reporta como alteración en cada pasada y se termina ignorando la alerta, que es la forma más común de
inutilizar un control de integridad.

## 6. Demostraciones

```bash
bash infra/scripts/demo-waf.sh https://marketgt.duckdns.org   # inyección, XSS, recorrido de rutas
bash infra/scripts/demo-seo.sh https://marketgt.duckdns.org   # rastreador falsificado, encubrimiento, redirección
```

Cada ataque imprime su código HTTP: **403 es el resultado correcto**, y el guion lo resalta. El primer
bloque del guion del cortafuegos lanza tráfico legítimo que **debe pasar**, porque una demostración que
solo enseña bloqueos no demuestra un cortafuegos, sino un sitio caído.

Después de cada demostración conviene leer el evento correspondiente en el panel y citar su
identificador único de transacción. Enseñar el 403 y a continuación el registro que lo explica es lo
que convierte la demostración en evidencia.

## 7. Restaurar un respaldo

> **Advertencia sobre el estado real.** Al 21 de septiembre de 2026 **los guiones de respaldo y de
> restauración no constan en el repositorio** y ninguna restauración se ha probado. El procedimiento
> que sigue es el definido en POL-004 y debe ejecutarse a mano hasta que los guiones existan. El
> control A.8.13 figura en la matriz como definido y no implementado, y el riesgo R-04 permanece en
> nivel alto por esta razón.

### 7.1 Antes de tocar nada

Las tres verificaciones de POL-004 sección 7.1, en este orden:

1. **¿El respaldo es anterior al incidente?** Restaurar desde una copia comprometida reinicia el
   incidente y consume el único juego de datos sano.
2. **¿El resumen coincide?** `sha256sum` del archivo descargado contra el registrado. Si difiere, se
   pasa al respaldo anterior.
3. **¿El descifrado produce un volcado válido?** Se comprueba la estructura antes de aplicarlo.

### 7.2 Secuencia

```bash
# 1. Descargar la copia EXTERNA (no la local: probar la local no verifica el eslabón que falla)
# 2. Verificar integridad
sha256sum marketgt-AAAAMMDD-HHMM.sql.gz.enc

# 3. Descifrar y descomprimir
openssl enc -d -aes-256-cbc -pbkdf2 \
  -in marketgt-AAAAMMDD-HHMM.sql.gz.enc | gunzip > restauracion.sql

# 4. Restaurar sobre infraestructura RECONSTRUIDA, nunca sobre el sistema comprometido
docker exec -i marketgt-db mariadb -u root -p"$DB_ROOT_PASSWORD" marketgt < restauracion.sql

# 5. Verificar
docker exec -i marketgt-db mariadb -u root -p"$DB_ROOT_PASSWORD" marketgt \
  -e "SELECT COUNT(*) FROM pedidos; SELECT COUNT(*) FROM users; SELECT COUNT(*) FROM registros_auditoria;"
```

### 7.3 Después de restaurar

Se verifica que la aplicación arranca y autentica, que el cortafuegos sigue bloqueando —con el guion de
demostración— y que la ingesta avanza. Si la restauración obedeció a un compromiso, **se rotan todos
los secretos**: clave de la aplicación, credenciales de base de datos, llaves de acceso administrativo
y secreto de la consola de demostración.

Se registra el volumen de datos perdido entre el último respaldo y el incidente. Esa cifra es la
medición real del objetivo de punto de recuperación, y es el dato que el acta debe contener.

### 7.4 La clave que no se puede perder

Los datos personales de los clientes están cifrados con la clave de la aplicación. **Sin esa clave, el
respaldo se restaura pero la dirección, el teléfono y el documento de identidad quedan ilegibles de
forma permanente.** La custodia de la clave es parte del respaldo, no un asunto aparte.

## 8. Cuando algo falla

### 8.1 El cortafuegos no levanta

La imagen resuelve el nombre de su destino al arrancar. Si la aplicación todavía está migrando, el
servidor web muere y el cortafuegos no levanta.

```bash
docker compose -f infra/docker/docker-compose.yml logs waf | tail -40
docker compose -f infra/docker/docker-compose.yml ps
```

Si la aplicación no está sana, se apunta el destino al servicio de respaldo estático cambiando
`BACKEND` a `http://respaldo:80` y se vuelve a levantar el cortafuegos. Las reglas de las fases uno y
dos siguen evaluando: los ataques siguen devolviendo 403 y el registro de auditoría se sigue
escribiendo. **Se salva la demostración del cortafuegos aunque la aplicación esté caída**, que es
exactamente el propósito de la decisión DA-006.

### 8.2 El sitio devuelve 502

El cortafuegos está en pie pero no alcanza a la aplicación.

```bash
docker compose -f infra/docker/docker-compose.yml logs app | tail -40
docker compose -f infra/docker/docker-compose.yml restart app
```

La causa más frecuente es una migración a medias o la base de datos aún iniciando. Conviene recordar
que la red interna **no tiene salida a Internet**, de modo que cualquier operación de la aplicación que
requiera descargar algo fallará en silencio; las dependencias se resuelven en la construcción de la
imagen precisamente por eso.

### 8.3 La inyección de prueba no devuelve 403

Es la avería más grave que puede tener este sistema, porque significa que el control central no está
actuando.

```bash
docker exec marketgt-waf env | grep -E 'MODSEC_RULE_ENGINE|ANOMALY|PARANOIA'
```

| Valor esperado | Qué pasa si no lo es |
|---|---|
| `MODSEC_RULE_ENGINE=On` | En `DetectionOnly` el cortafuegos registra pero **no bloquea**. Es el error que más veces se comete al depurar un falso positivo y olvidar revertirlo |
| `ANOMALY_INBOUND=5` | Con un umbral más alto, un solo acierto crítico deja de alcanzarlo |
| `BLOCKING_PARANOIA=1` | Con nivel 0 participan muchas menos reglas |

Si alguien puso el motor en modo de solo detección para resolver un falso positivo, **esa es la falla**.
Se revierte y el falso positivo se resuelve como manda POL-001 sección 7.4 y la decisión DA-008: con una
exclusión acotada a la regla y a la ruta, documentada y con su identificador, en
`infra/modsecurity/REQUEST-900-EXCLUSION-RULES-BEFORE-CRS.conf`.

### 8.4 El panel aparece vacío

Un panel vacío se confunde con ausencia de ataques, y esa confusión es peligrosa. Casi siempre
significa que la ingesta está detenida, no que no haya tráfico hostil.

```bash
bash infra/scripts/leer-audit-log.sh | tail -5   # ¿el registro se escribe?
php artisan siem:ingerir-waf                     # ¿la ingesta procesa?
php artisan siem:ingerir-waf --reiniciar         # último recurso: releer desde el principio
```

La ruta del registro configurada en `app/config/siem.php` debe coincidir **exactamente** con la variable
`MODSEC_AUDIT_LOG` de `infra/docker/docker-compose.yml`. Una divergencia entre ambas rompe la ingesta
en silencio, sin error visible.

Conforme a POL-002 sección 5.1, la pérdida de visibilidad es en sí misma un incidente de severidad alta
como mínimo, no una avería menor.

### 8.5 Un falso positivo bloquea tráfico legítimo

1. Identificar la regla en el registro: el campo `ruleId` del mensaje correspondiente.
2. Confirmar que el tráfico es realmente legítimo, no un ataque que se parece a uno.
3. Escribir la exclusión **acotada a esa regla y a esa ruta** en el archivo de exclusiones previas.
4. Documentarla con su identificador y su motivo.
5. Volver a ejecutar el guion de demostración para confirmar que el ataque real sigue bloqueado.

Lo que no se hace, bajo ninguna circunstancia ni urgencia: desactivar el motor de reglas, bajar el
nivel de paranoia o subir el umbral de anomalía.

### 8.6 El sitio no resuelve

```bash
sudo bash infra/scripts/05-actualizar-dns.sh
dig +short marketgt.duckdns.org
```

El guion deja programada una comprobación periódica, de modo que si el proveedor cambiara la dirección,
el nombre la sigue en pocos minutos. El token vive en `/etc/marketgt/dns.env` con permisos 600 y **no
está en el repositorio**.

### 8.7 El certificado no se emite o no se renueva

La emisión exige que el nombre resuelva hacia el servidor y que el puerto 80 esté alcanzable desde
Internet. Si el nombre acaba de cambiar de dirección, se espera a que la resolución se propague antes
de reintentar. El certificado se renueva de forma automática; una renovación fallida repetida se trata
como incidente de severidad media.

## 9. Tareas periódicas

| Tarea | Frecuencia | Comando o procedimiento | Evidencia |
|---|---|---|---|
| Revisión de alertas | Dos veces al día en ventana activa | Panel, sección 5.2 | Ficha de turno |
| Verificación de la ingesta | Diaria | Sección 8.4 | Ficha de turno |
| Revisión de eventos no interrumpidos | Diaria | Panel, filtro correspondiente | Ficha de turno |
| Informe y traspaso de turno | Semanal, lunes 08:00 | POL-003 sección 4.3 | Informe de turno |
| Revisión de falsos positivos | Semanal | Sección 8.5 | Registro de exclusiones |
| **Prueba de restauración** | **Mensual, primer lunes** | Sección 7 | **Acta `RES-AAAA-MM`** |
| Auditoría del endurecimiento | Mensual | `sudo lynis audit system` | Reporte en `evidencias/` |
| Descubrimiento de puertos desde fuera | Mensual | `nmap -Pn -p- marketgt.duckdns.org` | Reporte en `evidencias/` |
| Capacitación y simulacro | Semestral | POL-006 | Acta `CAP-AAAA-SN` |
| Revisión del sistema de gestión | Semestral | POL-001 sección 12 | Acta de revisión |

## 10. Qué hacer ante un incidente

Este manual describe la operación normal. **Ante un incidente se aplica POL-002**, y el resumen de una
página de POL-003 sección 8 es lo que debe tenerse a la vista durante la guardia.

Tres reglas del plan que conviene recordar aquí, porque son las que se olvidan bajo presión:

1. **La contención de primer nivel no espera autorización.** Cerrar sesiones, desactivar una cuenta o
   bloquear un origen se ejecuta y se notifica después.
2. **Primero se preserva lo volátil, después se contiene y solo al final se restaura.** Apagar un
   contenedor destruye su memoria, que puede ser la evidencia o incluso la clave de descifrado.
3. **Escalar por falta de información es el uso correcto del procedimiento**, no un error de criterio.

## 11. Control de versiones

| Versión | Fecha | Autor | Cambio |
|---|---|---|---|
| 1.0 | 2026-09-21 | Nivel operativo — Departamento de TI | Emisión inicial. Desarrolla el control A.5.37 |
</content>
