# Política de Retención de Registros

| | |
|---|---|
| **Identificador** | POL-005 |
| **Versión** | 1.0 |
| **Fecha de emisión** | 21 de septiembre de 2026 |
| **Elaborado por** | Nivel táctico — Arquitecto de Seguridad |
| **Aprobado por** | Nivel estratégico — Director de Seguridad de la Información (CISO) |
| **Clasificación** | Uso interno del proyecto |
| **Controles del Anexo A** | A.8.15, A.8.16, A.5.28 |
| **Próxima revisión** | 21 de marzo de 2027 |

---

## 1. Propósito

El anexo del triángulo de la ciberresiliencia señaló que el proyecto desplegaba herramientas de
monitoreo pero **no declaraba política de retención de registros**. La omisión no es menor: sin plazo
declarado, un registro se conserva mientras quepa en el disco y desaparece cuando deja de caber, que
es la peor de las políticas porque además es involuntaria.

Esta política fija qué se registra, durante cuánto tiempo, qué no se registra nunca y cómo se protege
la integridad de lo registrado. Desarrolla el control A.8.15 del Anexo A de la norma ISO/IEC
27001:2022.

## 2. Por qué la retención es un control de seguridad

Un registro cumple tres funciones distintas, y cada una impone un requisito de plazo diferente.

La **detección** necesita registros recientes y accesibles con rapidez. Es el uso diario del turno de
guardia y exige consulta en segundos.

La **investigación** necesita alcance histórico. El intervalo entre el compromiso inicial y su
descubrimiento rara vez es inmediato: si el registro solo conserva una semana, la investigación de un
incidente descubierto al mes no tiene con qué reconstruir el origen. Esta es la razón del plazo de 90
días en línea.

La **evidencia** necesita integridad demostrable y permanencia. Un registro alterable no sostiene
ninguna afirmación sobre lo ocurrido.

## 3. Plazos de retención

| Fase | Plazo | Ubicación | Accesibilidad |
|---|---|---|---|
| **En línea** | **90 días** | Base de datos de la plataforma y archivos de registro activos | Consulta inmediata desde el panel de monitoreo |
| **Almacenamiento frío** | **12 meses** adicionales | Archivo comprimido y cifrado, incorporado al respaldo externo | Recuperable en horas, no en segundos |
| **Eliminación** | Al cumplir 15 meses desde su generación | — | — |

Los plazos están configurados en la aplicación y no escritos únicamente en este documento: el archivo
de configuración del panel declara 90 días de retención en línea y 12 meses en frío, con la ruta del
archivo frío. Una política cuyo valor vive solo en un documento se desincroniza del sistema en el
primer cambio; una que vive en la configuración se aplica sola.

### 3.1 Regla de purga

La purga **mueve al almacenamiento frío antes de borrar; nunca elimina sin haber archivado**. Esta
regla es la que convierte la purga en una transición y no en una pérdida.

Un registro que forme parte de la evidencia de un incidente abierto **no se purga**, con independencia
de su antigüedad. La retención se suspende sobre las piezas identificadas en la cadena de custodia
hasta que el incidente se cierra formalmente y, si el incidente derivó en una comunicación a titulares
de datos, hasta doce meses después de su cierre.

### 3.2 Justificación de los plazos

El plazo de 90 días en línea corresponde al mínimo con el que una investigación puede reconstruir un
compromiso descubierto de forma tardía, y es coherente con el requisito del estándar PCI DSS de
mantener el historial de auditoría disponible para análisis inmediato durante ese período, con un año
de conservación total. El plazo de 12 meses en frío completa esa conservación total y permite
contrastar comportamientos estacionales del tráfico.

Al cumplirse los 15 meses los registros se eliminan. Conservarlos indefinidamente no aporta capacidad
de detección y sí mantiene vivo un conjunto de datos que incluye direcciones de origen, rutas
consultadas y cargas útiles: información que, acumulada, permite perfilar a usuarios legítimos. La
minimización es también un control de seguridad.

## 4. Qué se registra

### 4.1 Registro de auditoría del cortafuegos de aplicación

Es la fuente primaria del vértice de detección. Se escribe en formato JSON, un objeto por línea, en
`/var/log/modsecurity/audit/audit.json`.

| Campo | Contenido | Para qué sirve |
|---|---|---|
| Identificador único de transacción | Clave que enlaza todos los registros de una misma petición | Reconstruir el incidente sin depender de búsquedas por hora aproximada |
| Dirección de origen y marca temporal | Quién y cuándo | Correlación y bloqueo |
| Método, URI y cuerpo de la petición | Qué se intentó | Determinar el vector del ataque |
| Cabeceras de la petición | Contexto, incluido el agente de usuario | Detectar rastreadores falsificados |
| Código de respuesta | Qué devolvió el servidor | Distinguir el ataque bloqueado del que pasó |
| Indicador de interrupción | Si el cortafuegos cortó la petición | **Es el campo que separa una severidad media de una crítica** |
| Reglas activadas | Identificador, mensaje, severidad, datos coincidentes y etiquetas | Determinar qué control actuó, incluidas las reglas propias 15000 a 15099 |

El motor de auditoría opera en modo de registro selectivo: se registra cuando hubo coincidencia de
alguna regla o cuando la respuesta fue de la familia 4xx o 5xx, con exclusión de los 404. La decisión
es deliberada: registrar la totalidad del tráfico legítimo consumiría el disco sin aportar capacidad
de detección, y los 404 de un sitio público son, en su mayor parte, ruido de rastreadores.

### 4.2 Bitácora de seguridad de la aplicación

Se escribe en `storage/logs/seguridad.log` y es la fuente que el panel ingiere junto con la anterior.
Registra:

| Evento | Detalle registrado |
|---|---|
| Inicio y cierre de sesión | Resultado, cuenta, dirección de origen, agente de usuario |
| Intento fallido de autenticación | Cuenta y origen, sin la credencial empleada |
| Verificación del segundo factor | Resultado y método empleado, código temporal o credencial de clave pública |
| Cambio en los factores de seguridad | Activación, retiro o regeneración de códigos de recuperación |
| Acceso denegado por rol | Cuenta, ruta solicitada y rol requerido |
| Operaciones sobre pedidos y catálogo | Acción, recurso afectado y resultado |
| Cierre forzado de sesiones | Cuenta afectada y sesiones cerradas |
| Cambios de configuración y despliegues | Qué cambió y quién lo ejecutó |

### 4.3 Tabla de registros de auditoría

Los mismos hechos se asientan en la tabla `registros_auditoria`. La duplicación es deliberada y consta
en el propio código: el archivo es el flujo que el panel consume y que se rota, mientras que la tabla
es el registro consultable que sobrevive a la rotación y puede cruzarse con pedidos y usuarios en una
sola consulta. Un auditor pide siempre ambas cosas, y disponer únicamente del archivo obliga a
reprocesarlo para contestar cualquier pregunta.

### 4.4 Eventos y alertas del panel

Las tablas `eventos_seguridad`, `alertas_seguridad` y sus relaciones conservan el resultado de la
normalización y de la correlación, con la severidad asignada y el estado de cada alerta a lo largo de
su ciclo. La tabla de alertas conserva los datos esenciales del evento que la originó, de modo que la
alerta sigue siendo interpretable aunque los eventos que la produjeron hayan sido purgados por
retención.

### 4.5 Registros del sistema operativo

Autenticación por SSH, acciones ejecutadas con elevación de privilegios, decisiones del cortafuegos de
red y bloqueos automáticos por intentos fallidos. Se conservan bajo el mismo plazo.

## 5. Qué no se registra nunca

Esta sección es tan importante como la anterior. Un registro que contiene lo que no debe se convierte
de control en vulnerabilidad: concentra en un archivo de lectura amplia exactamente aquello que el
resto del sistema protege.

| Nunca se registra | Qué se registra en su lugar |
|---|---|
| **Contraseñas**, en claro o cifradas, ni siquiera en un intento fallido | Que hubo un intento fallido, con cuenta y origen |
| **Números de tarjeta completos.** La plataforma no los almacena en ningún caso | El identificador del testigo de pago |
| Códigos de verificación del segundo factor y secretos compartidos del algoritmo temporal | Que la verificación fue correcta o incorrecta, y el método empleado |
| Códigos de recuperación | Que se usó uno, y que quedó invalidado |
| Claves de cifrado, clave de la aplicación, credenciales de la base de datos y llaves privadas | Que se produjo una rotación, con su fecha |
| Testigos de sesión y cookies de autenticación | El identificador interno de la sesión, que no permite suplantarla |
| Contenido íntegro de datos personales cifrados | Que se accedió al registro, con qué cuenta y con qué propósito |

### 5.1 La excepción que exige cuidado

El registro de auditoría del cortafuegos conserva el cuerpo de las peticiones, porque sin él no puede
determinarse el vector de un ataque. Eso significa que el cuerpo de una petición de inicio de sesión
bloqueada **puede contener una contraseña en texto claro**.

Esta consecuencia se declara de forma expresa en lugar de ignorarla, y se atiende por tres vías: el
registro se clasifica como información confidencial y su acceso se restringe a los roles de
administrador y auditor; el conjunto de reglas incorpora la ocultación del valor de los campos
sensibles cuando la regla correspondiente está activa; y la rotación de la credencial de cualquier
cuenta cuyo intento de autenticación haya quedado registrado con su cuerpo completo se trata como
requisito y no como recomendación.

Una política que prohíbe registrar contraseñas sin reconocer este caso sería una política que se
incumple a sí misma sin saberlo.

## 6. Protección de la integridad de los registros

El control A.8.15 exige que los registros estén protegidos frente a alteración y acceso no autorizado.
Un registro que el atacante puede editar después de entrar no es un registro: es una coartada.

### 6.1 Separación de privilegios

El registro del cortafuegos se genera dentro del contenedor del cortafuegos y se expone al contenedor
de la aplicación **en modo de solo lectura**. La aplicación consume eventos; no puede alterarlos. Esta
separación es verificable inspeccionando la definición del conjunto de contenedores y constituye
evidencia directa del control.

La red interna entre la aplicación y la base de datos no es alcanzable desde el exterior ni desde el
anfitrión: el único camino hacia la aplicación atraviesa el cortafuegos. Esta configuración es la
evidencia de que no existe una vía alternativa que eluda la inspección y, por tanto, el registro.

### 6.2 Inmutabilidad práctica

Los asientos de la tabla de auditoría se escriben una sola vez. La aplicación no expone ninguna ruta
que permita modificarlos o eliminarlos, y ningún rol —incluido el de administrador— dispone de esa
operación en la interfaz.

Conviene ser preciso sobre el alcance de esta protección: un atacante que obtenga acceso directo al
motor de base de datos con credenciales administrativas **sí puede alterar la tabla**. La protección
es de la aplicación, no del motor. La mitigación real frente a ese escenario es doble: el usuario de
base de datos que emplea la aplicación opera con privilegio mínimo y no es administrador, y el
registro del cortafuegos vive fuera de la base de datos, de modo que ambas fuentes deberían alterarse
de forma coherente para que la manipulación pasara inadvertida.

### 6.3 Sellado del archivo frío

Al archivar un lote de registros se calcula su resumen SHA-256, que se almacena junto al archivo y se
registra en la bitácora de operación. Cualquier alteración posterior del archivo resulta detectable
mediante recálculo. El archivo frío se incorpora al respaldo externo conforme a POL-004, lo que le
aplica también el requisito de credencial sin permiso de borrado.

### 6.4 Sincronización horaria

Todos los componentes mantienen su reloj sincronizado por protocolo de tiempo de red. Una correlación
entre fuentes con relojes desalineados produce secuencias falsas, y una secuencia falsa en una
investigación conduce a la conclusión equivocada con toda la apariencia de rigor. Los registros se
generan en huso horario de Guatemala, UTC−6, y las actas de evidencia consignan además el equivalente
en UTC.

### 6.5 Rotación

Los archivos de registro se rotan por tamaño y por fecha. La ingesta del panel es incremental y
recuerda el desplazamiento donde se quedó; si el archivo encoge respecto de ese desplazamiento, asume
que hubo rotación y vuelve a leer desde el principio. Sin esa previsión, una rotación produciría una
laguna silenciosa en la ingesta, que es la peor clase de laguna porque no genera error.

## 7. Acceso a los registros

| Rol | Alcance de acceso |
|---|---|
| Administrador | Lectura completa del panel, eventos, alertas y métricas |
| Auditor | Lectura completa, sin capacidad de modificación |
| Cliente | Ninguno. No accede al panel ni a los registros |
| Procesos automáticos | Lectura del archivo de registro del cortafuegos, en modo de solo lectura |

Todo acceso al panel queda a su vez registrado. El registro del acceso a los registros no es
redundancia: es el control que permite detectar a un operador legítimo que consulta lo que no le
corresponde.

## 8. Verificación del control

Estas comprobaciones son las que sostienen el control ante una auditoría. Se ejecutan en la revisión
semestral y su resultado se archiva.

| Verificación | Cómo se comprueba | Resultado esperado |
|---|---|---|
| El plazo configurado coincide con el declarado | Lectura del archivo de configuración del panel | 90 días en línea, 12 meses en frío |
| La ingesta avanza | Estado del marcador de ingesta | Desplazamiento creciente, sin estancamiento |
| El registro es de solo lectura para la aplicación | Inspección del montaje en la definición de contenedores | Montaje en modo de solo lectura |
| No hay credenciales en los registros | Búsqueda de patrones de contraseña y de numeración de tarjeta sobre una muestra | Ninguna coincidencia |
| El archivo frío conserva su integridad | Recálculo del resumen SHA-256 | Coincidencia con el valor registrado |
| La purga archiva antes de eliminar | Revisión del contenido del directorio de archivo frío | Presencia del lote correspondiente al período purgado |

## 9. Estado al 21 de septiembre de 2026

Los plazos están declarados en la configuración de la aplicación y la separación de privilegios sobre
el registro del cortafuegos está implementada y es verificable. La **rutina automática de purga y
archivado en frío no se ha ejecutado todavía**, por la razón evidente de que el sistema no acumula aún
90 días de operación.

El control A.8.15 se declara, en consecuencia, **implementado en su componente de registro y
definido pero no ejercido en su componente de archivado**. Así consta en la matriz de controles.

## 10. Control de versiones

| Versión | Fecha | Autor | Cambio |
|---|---|---|---|
| 1.0 | 2026-09-21 | Nivel táctico — Arquitecto de Seguridad | Emisión inicial. Cierra la brecha de política de retención señalada en el anexo del triángulo de la ciberresiliencia |
</content>
