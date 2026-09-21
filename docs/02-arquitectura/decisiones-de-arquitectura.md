# Registro de Decisiones de Arquitectura

| | |
|---|---|
| **Identificador** | ARQ-001 |
| **Versión** | 1.0 |
| **Fecha de corte** | 21 de septiembre de 2026 |
| **Responsable** | Nivel táctico — Arquitecto de Seguridad |
| **Clasificación** | Uso interno del proyecto |
| **Alcance** | Decisiones que afectan a la arquitectura del sistema y a su alcance de seguridad |

---

## Sobre este registro

Este documento recoge las decisiones de arquitectura del proyecto, cada una con el problema que la
motivó, las alternativas descartadas y **lo que se pierde al adoptarla**. Esa última parte es la que da
sentido al registro: una decisión sin costo declarado no es una decisión, es una preferencia.

Varias de las que siguen son incómodas. Documentan que un componente anunciado no se desplegó, que un
proveedor se descartó tras haberlo elegido, o que una capa quedó implementada a medias. Se registran
igual, por la misma razón que la matriz de controles declara sus vacíos: un registro que solo recoge
los aciertos no permite evaluar el criterio con el que se decidió, y el criterio es precisamente lo que
una revisión de gestión evalúa.

Las decisiones se numeran como `DA-NNN`. La decisión **DA-001** reside en su propio archivo,
`decision-capa1-perimetro.md`, por su extensión; aquí se resume y se remite a él.

| Código | Decisión | Estado | Impacto en el alcance de seguridad |
|---|---|---|---|
| DA-001 | Alcance real de la capa de perímetro | Aceptada | Capa 1 parcial; control A.8.20 parcial |
| DA-002 | Proveedor de cómputo: Google Cloud en lugar de Oracle Cloud | Aceptada | Ninguno sobre los controles; sí sobre la continuidad tras la presentación |
| DA-003 | Panel de monitoreo propio en lugar de Wazuh | Aceptada | Capacidad de detección acotada a la capa de aplicación y al cortafuegos |
| DA-004 | Control de acceso propio en lugar de un paquete de permisos | Aceptada | Ninguno negativo |
| DA-005 | Cifrado de datos personales con pérdida de búsqueda en SQL | Aceptada | Reduce el impacto de R-07 a costa de funcionalidad |
| DA-006 | Servicio de respaldo estático como origen alternativo del cortafuegos | Aceptada | Sostiene la demostración y el registro ante un fallo de la aplicación |
| DA-007 | Imágenes fijadas por etiqueta exacta | Aceptada | Reproducibilidad frente a actualización automática |
| DA-008 | Umbral de anomalía 5 con paranoia 1 en bloqueo y 2 en detección | Aceptada | Equilibrio entre detección y falsos positivos |

---

## DA-001 · Alcance real de la capa de perímetro

**Estado:** Aceptada · **Fecha:** 21 de septiembre de 2026 · **Documento completo:**
`docs/02-arquitectura/decision-capa1-perimetro.md`

**Resumen.** Cloudflare admite una zona en su plan gratuito únicamente cuando se le delegan por
completo los servidores de nombres de un dominio apto, y las modalidades que permitirían prescindir de
esa delegación están reservadas a planes de pago. Tras evaluar cinco alternativas de dominio gratuito
—descartadas por imposibilidad técnica, cierre del registro, desfase de la lista de sufijos públicos o
plazos de aprobación superiores al horizonte del proyecto—, el sistema se publica bajo
`marketgt.duckdns.org` con certificado de Let's Encrypt y **la capa de perímetro se declara
parcialmente implementada**.

**Lo que se pierde.** Mitigación de denegación de servicio en el borde, entrega desde caché
distribuida, límite de tasa antes del origen, filtrado de borde y ocultamiento de la dirección del
servidor.

**Lo que no se pierde.** El control central de la propuesta —la inspección de capa 7 con ModSecurity y
el Core Rule Set— reside en la capa cuatro y no se ve afectado. Conviene además una precisión que el
documento original no hacía: el conjunto de reglas gestionado que Cloudflare ofrece en su plan gratuito
**no incluye el OWASP Core Rule Set**, de modo que la detección de inyección y de secuencias de
comandos que el proyecto demuestra se produce íntegramente sobre infraestructura propia.

**Trazabilidad.** Control A.8.20 parcial. Riesgo R-06 aceptado de forma razonada.

---

## DA-002 · Proveedor de cómputo: Google Cloud en lugar de Oracle Cloud

**Estado:** Aceptada · **Fecha:** 21 de septiembre de 2026

### Contexto

El proyecto opera bajo una restricción de costo cero que no admite excepciones, y el presupuesto
aprobado contempla Q183.00 mensuales de servidor virtual privado como única partida significativa de
desembolso. La primera opción evaluada fue la capa siempre gratuita de Oracle Cloud Infrastructure, por
una razón que ninguna otra oferta igualaba: sus instancias basadas en arquitectura Arm ofrecen hasta
cuatro núcleos y 24 GB de memoria **sin límite de tiempo**, mientras que las alternativas ofrecen
crédito temporal. Para un proyecto que debe seguir demostrable después de la entrega, la diferencia
entre «gratis para siempre» y «gratis noventa días» es sustancial.

### El problema

La capacidad de la capa siempre gratuita se asigna por región y de forma oportunista. Durante el
período de aprovisionamiento, las solicitudes de instancia Arm en las regiones accesibles resultaron
rechazadas de forma reiterada por falta de capacidad disponible, que es el comportamiento documentado
del proveedor cuando la demanda de esa capa satura una región.

A esto se sumaron dos circunstancias que agravaban el riesgo de planificación. La primera es que la
disponibilidad no es predecible: no existe cola, ni reserva, ni fecha estimada, de modo que la
estrategia se reduce a reintentar sin garantía de éxito. La segunda es que una cuenta de la capa
gratuita puede quedar sujeta a verificación adicional, cuyo plazo de resolución tampoco es predecible.

Con la presentación fijada para el 26 de septiembre de 2026, el proyecto no podía sostener una
dependencia cuyo plazo de resolución fuera desconocido. La restricción decisiva no fue técnica ni
económica: **fue de calendario**.

### Alternativas evaluadas

| Alternativa | Resultado |
|---|---|
| Oracle Cloud, capa siempre gratuita, instancia Arm | Sin capacidad disponible en las regiones accesibles durante la ventana de aprovisionamiento. Sin plazo estimado |
| Oracle Cloud, capa siempre gratuita, instancia x86 | Disponible, pero limitada a 1 GB de memoria por instancia. Insuficiente: el conjunto reúne cortafuegos, aplicación, base de datos y panel |
| Google Cloud, crédito de prueba de 300 dólares por 90 días | Capacidad inmediata y tipo de instancia suficiente. El crédito cubre con holgura el costo del período |
| Azure para estudiantes | Requiere verificación académica institucional con plazo no garantizado, el mismo problema que ya había descartado dos alternativas de dominio en DA-001 |
| Servidor virtual privado de pago | Q183.00 mensuales. Viable y presupuestado, pero innecesario si existe una alternativa sin desembolso |

### Decisión

Se aprovisiona la infraestructura en **Google Cloud**, sobre una instancia `e2-standard-4` de cuatro
núcleos y 16 GB de memoria con 50 GB de disco, en la zona `us-central1-a`, empleando el crédito de
prueba de 300 dólares con 90 días de vigencia.

El dimensionamiento no es arbitrario: responde al tamaño que exigía el conjunto completo con un sistema
de gestión de eventos basado en Wazuh, cuyo indexador reclama por sí solo alrededor de 4 GB, sobre los
que se añaden la base de datos, el intérprete de PHP, el servidor web con ModSecurity y el panel. El
costo estimado del período hasta la presentación ronda los 16 dólares del crédito disponible.

Se establece además una regla operativa explícita, incorporada como advertencia en el propio guion de
aprovisionamiento: **nadie activa la cuenta completa ni acepta la mejora de plan en la consola del
proveedor**. Mientras la cuenta permanezca en modalidad de prueba no puede generarse cargo alguno,
porque al agotarse el crédito los recursos se detienen en lugar de facturarse. Esta regla es la que
sostiene la restricción de costo cero, y su incumplimiento por descuido es el único camino por el que
el proyecto podría generar un gasto no previsto.

### Consecuencias

**Lo que se gana.** Capacidad inmediata, sin dependencia de un plazo que el equipo no controla, y un
tipo de instancia que sostiene el conjunto completo sin degradación durante la demostración.

**Lo que se pierde, y conviene decirlo sin rodeos.** El crédito vence a los 90 días. La plataforma
**dejará de estar publicada** una vez agotado, salvo que se migre a otra infraestructura. La opción de
Oracle habría ofrecido permanencia indefinida; la de Google ofrece disponibilidad inmediata. Se
priorizó la segunda porque una plataforma que no está disponible el día de la presentación no tiene
ninguna utilidad, con independencia de cuánto tiempo pudiera haber durado.

**Mitigación.** La totalidad de la infraestructura se define mediante guiones idempotentes y un
conjunto de contenedores bajo control de versiones, y el guion de endurecimiento está escrito de forma
deliberada para funcionar en cualquier proveedor. La migración a otra infraestructura —incluida una
instancia de Oracle si la capacidad se libera— consiste en ejecutar los mismos guiones contra otra
máquina. La dependencia del proveedor se limita, por tanto, al aprovisionamiento, que es el único
guion específico.

**Trazabilidad.** Control A.8.31, separación de entornos: el mismo guion construye desarrollo y
producción. Control A.5.19 a A.5.23, relación con proveedores: no evaluados, por tratarse de un
servicio gratuito aceptado en sus términos publicados.

---

## DA-003 · Panel de monitoreo propio en lugar de Wazuh

**Estado:** Aceptada · **Fecha:** 21 de septiembre de 2026

### Contexto

Los documentos previos del proyecto declaran Wazuh como sistema de gestión de eventos e información de
seguridad. Aparece en la tabla de herramientas de seguridad del primer entregable, en la banda de
monitoreo transversal del diagrama de lógica funcional, y el anexo del triángulo lo sitúa como el
componente que sostiene el vértice de detección. El guion de aprovisionamiento dimensionó la máquina
virtual precisamente para alojarlo.

### El problema

Wazuh no se desplegó. El conjunto de contenedores que constituye el sistema comprende el cortafuegos,
la aplicación, la base de datos y el servicio de respaldo estático; **no incluye ni el indexador, ni el
gestor, ni el tablero de Wazuh**.

La razón es de proporción entre esfuerzo y resultado demostrable. Un despliegue completo de Wazuh exige
tres componentes adicionales, un motor de indexación que reclama por sí solo alrededor de 4 GB de
memoria, un ajuste del parámetro de mapeo de memoria del núcleo, la generación de certificados propios
entre componentes, y la escritura de decodificadores y reglas específicas para interpretar el registro
JSON de ModSecurity. Ese trabajo habría consumido buena parte del tiempo restante para producir, como
resultado visible, un tablero genérico que muestra los mismos eventos que el registro de auditoría ya
contiene.

Frente a ello, el vértice de detección exigía cuatro cosas concretas que el anexo enumeraba: saber
quién revisa las alertas y en qué horario, declarar bajo qué casos de uso se generan, fijar la política
de retención de los registros, e integrar las fuentes que quedaban fuera de la correlación. Ninguna de
las cuatro es una carencia de herramienta: las cuatro son carencias de operación. El propio anexo lo
formuló con precisión al señalar que la brecha no residía en las herramientas sino en su operación, y
que una herramienta desplegada sin operación asociada produce evidencia para la auditoría pero no
capacidad real de detección.

### Decisión

Se implementa un **panel de monitoreo propio dentro de la aplicación Laravel**, con ingesta incremental
del registro de auditoría del cortafuegos y de la bitácora de seguridad de la aplicación,
normalización de eventos, motor de correlación con reglas y umbrales declarados, alertas con ciclo de
estados y tablero de métricas contrastadas contra las metas del triángulo.

Se retira Wazuh de la declaración de componentes desplegados. El README del repositorio, que lo
enuncia como «panel SIEM propio + Wazuh», debe corregirse en consecuencia: **el sistema desplegado es
el panel propio, sin Wazuh**.

### Consecuencias

**Lo que se gana.** El panel conoce el dominio del proyecto: sus severidades son las de la aplicación,
sus reglas de correlación están escritas contra los identificadores de regla del Core Rule Set y contra
las reglas propias 15000 a 15099, y sus métricas son exactamente las siete metas declaradas en el anexo,
que el tablero contrasta una a una. Un tablero genérico habría exigido construir esa correspondencia de
todos modos. Además, la totalidad del código que decide qué es una alerta puede leerse y auditarse
durante la defensa, que es una ventaja de evaluación nada despreciable.

**Lo que se pierde, y no es poco.**

| Capacidad de Wazuh | Qué ocurre sin ella |
|---|---|
| Agente en el anfitrión con monitoreo de integridad de archivos | No se detecta la alteración de archivos del sistema operativo. La vigilancia de integridad propia cubre únicamente el contenido indexable del sitio |
| Detección de intrusiones en el sistema operativo | Los registros de autenticación del sistema y las acciones con elevación de privilegios **no se correlacionan** con los eventos de la aplicación |
| Recolección de registros del cortafuegos de red | Los bloqueos automáticos por intentos fallidos no alimentan el panel |
| Evaluación de configuración contra el CIS Benchmark | La verificación del endurecimiento depende de una ejecución manual de Lynis |
| Repositorio de reglas mantenido por una comunidad | Las reglas de correlación son las que el equipo escriba, con su cobertura y sus puntos ciegos |
| Almacenamiento indexado para búsqueda a gran escala | La consulta histórica se apoya en la base de datos relacional, suficiente para el volumen del proyecto y no para uno mayor |

La consecuencia agregada se declara sin atenuantes: **la capacidad de detección del sistema cubre la
capa cuatro y la capa cinco, y no cubre las capas dos y tres**. Un compromiso que se produzca
íntegramente en el sistema operativo, sin atravesar el cortafuegos de aplicación, no genera alerta.

**Alcance sobre los controles.** El control A.8.16, actividades de seguimiento, se declara implementado
porque el seguimiento existe y opera; su alcance, no obstante, es el que esta decisión delimita. El
control A.8.7, protección contra código malicioso, queda como vacío reconocido, y parte de la razón es
que un agente de Wazuh habría aportado detección en ese ámbito.

**Condición de revisión.** Si el proyecto evoluciona hacia operación real, esta decisión debe
revisarse: un sistema de gestión de eventos que no observa el sistema operativo es aceptable en una
demostración académica de cinco días y no lo es en producción.

---

## DA-004 · Control de acceso propio en lugar de un paquete de permisos

**Estado:** Aceptada · **Fecha:** 21 de septiembre de 2026

**Contexto.** El ecosistema de Laravel ofrece un paquete ampliamente adoptado para gestión de permisos,
que resuelve permisos granulares, jerarquías de roles y equipos.

**Decisión.** El control de acceso se implementa a mano, con tres roles fijos —administrador, auditor y
cliente— y un middleware de verificación.

**Fundamento.** El proyecto tiene tres roles que no crecen. Adoptar el paquete significaría añadir una
dependencia con su propio ciclo de versiones, cinco tablas y una caché de permisos que hay que
invalidar manualmente. Para tres roles, eso es más superficie de ataque y más mantenimiento del que
ahorra. Existe además una razón de auditoría: durante la defensa, el profesor puede leer las cuarenta
líneas que deciden quién entra al panel de monitoreo, en lugar de auditar código de terceros.

**Lo que se pierde.** Si el modelo de permisos creciera hacia permisos granulares por recurso, habría
que reescribirlo o adoptar el paquete entonces. Se asume: es un costo futuro y condicional frente a un
beneficio presente y cierto.

**Trazabilidad.** Controles A.5.15 y A.8.3, implementados. Reduce la exposición al riesgo R-02.

---

## DA-005 · Cifrado de datos personales con pérdida de búsqueda en SQL

**Estado:** Aceptada · **Fecha:** 21 de septiembre de 2026

**Contexto.** La dirección, el teléfono y el documento de identidad de los clientes son información
clasificada como confidencial conforme a POL-001.

**Decisión.** Los tres campos se almacenan cifrados mediante el mecanismo de cifrado simétrico de
Laravel, en columnas de tipo texto y no de longitud variable acotada, porque lo que se guarda no es el
dato sino su sobre cifrado, que multiplica varias veces la longitud original.

**Lo que se pierde.** Sobre esas columnas **no puede buscarse ni ordenarse en SQL**, porque dos
cifrados del mismo texto son distintos. Para los datos personales de un cliente la limitación es
asumible: se leen de uno en uno, con el usuario ya cargado. No lo sería para un campo sobre el que
hubiera que filtrar.

**Lo que se gana, y es medible.** Reduce el impacto residual del riesgo R-07 de crítico a moderado: una
extracción de la base de datos sin la clave de la aplicación no constituye divulgación efectiva de
datos personales. Esa misma dependencia eleva la criticidad de la clave, y por eso existe el riesgo
R-08 y la regla de custodia de POL-004 sección 5.3.

**Trazabilidad.** Controles A.8.24 parcial y A.5.34 implementado.

---

## DA-006 · Servicio de respaldo estático como origen alternativo del cortafuegos

**Estado:** Aceptada · **Fecha:** 21 de septiembre de 2026

**Contexto.** La imagen del cortafuegos resuelve el nombre de su destino al arrancar. Si la aplicación
no responde, el servidor web no levanta y **el cortafuegos tampoco**.

**El problema.** La consecuencia es desproporcionada: un fallo de la aplicación —una migración lenta,
una base de datos que tarda en responder— tumbaría también la demostración del cortafuegos, que es el
centro del proyecto.

**Decisión.** Se incorpora un servicio de página estática mínima como destino alternativo. El
cortafuegos siempre encuentra un origen que resuelve; si la aplicación cae, basta apuntar el destino a
ese servicio y las reglas de las fases uno y dos siguen evaluando: los ataques siguen devolviendo 403 y
el registro de auditoría se sigue escribiendo.

**Lo que se gana.** Se salvan la demostración del cortafuegos y la generación de evidencia aunque la
aplicación esté caída. En términos de gestión, es una medida de continuidad que sostiene parcialmente
el control A.5.29.

**Lo que se pierde.** Un contenedor adicional, de consumo despreciable, y la necesidad de recordar que
un sitio que responde no significa que la aplicación funcione.

---

## DA-007 · Imágenes fijadas por etiqueta exacta

**Estado:** Aceptada · **Fecha:** 21 de septiembre de 2026

**Decisión.** La imagen del cortafuegos se fija a la etiqueta exacta
`owasp/modsecurity-crs:4.29.0-nginx-202609180209`, verificada el 21 de septiembre de 2026 contra
ModSecurity 3.0.16, conector 1.0.4, nginx 1.30.5 y Core Rule Set 4.29.0. **No se emplea la etiqueta
rodante.**

**Fundamento.** Una etiqueta rodante cambia sin aviso. Un cambio del conjunto de reglas la víspera de
la presentación podría alterar el comportamiento de una regla demostrada y convertir un 403 esperado en
un 200 inexplicable ante el auditor.

**Lo que se pierde.** Las correcciones publicadas después de esa fecha no se incorporan de forma
automática, lo que entra en tensión con la ventana de parcheo de 72 horas del control A.8.8. La tensión
se resuelve así: el parcheo automático cubre el sistema operativo, mientras que la actualización de la
imagen del cortafuegos es una decisión deliberada que se ejecuta fuera de la ventana de congelamiento
previa a la presentación.

**Trazabilidad.** Controles A.8.9 implementado y A.8.8 parcial.

---

## DA-008 · Umbral de anomalía 5 con paranoia 1 en bloqueo y 2 en detección

**Estado:** Aceptada · **Fecha:** 21 de septiembre de 2026

**Contexto.** El Core Rule Set opera por acumulación: cada regla que coincide suma puntos según su
severidad, y la petición se bloquea cuando la suma alcanza el umbral. El nivel de paranoia determina
cuántas reglas participan, a costa de más falsos positivos.

**Decisión.** Nivel de paranoia 1 para bloqueo y 2 para detección, con umbral de anomalía entrante 5 y
saliente 4.

**Fundamento.** Con umbral 5, un único acierto de severidad crítica —una inyección SQL detectada por
análisis léxico, por ejemplo— alcanza el umbral por sí solo y produce la respuesta 403, que es
exactamente el comportamiento que la demostración necesita mostrar. El nivel 1 en bloqueo mantiene la
tasa de falsos positivos por debajo de la meta del 2 %, mientras que el nivel 2 en detección permite
observar lo que un umbral más estricto habría capturado, **sin bloquear tráfico legítimo por ello**.

**Lo que se pierde.** Un atacante que fragmente su carga útil en varias peticiones, cada una por debajo
del umbral, no lo alcanza. La mitigación es la correlación: el panel agrupa eventos por origen y
ventana temporal, y esa agregación es precisamente lo que una regla individual no puede ver.

**Regla operativa derivada, y es la más importante de esta decisión.** Un falso positivo **nunca** se
resuelve poniendo el motor de reglas en modo de solo detección ni desactivándolo. Se resuelve con una
exclusión acotada a la regla y a la ruta, documentada y con su identificador. Esta regla figura además
en la política de uso aceptable y en el módulo 3 del plan de capacitación, porque es la que más se
incumple bajo presión.

**Trazabilidad.** Control A.8.23 implementado. Reduce los riesgos R-01 y R-05.

---

## Decisiones pendientes de registrar

Estas decisiones no se han tomado y se enumeran para que su ausencia conste.

| Asunto | Por qué está pendiente | Quién decide |
|---|---|---|
| Proveedor del almacenamiento externo de respaldos | POL-004 exige un proveedor distinto al de cómputo y una credencial sin permiso de borrado. La elección concreta no se ha hecho | Nivel táctico |
| Destino de la plataforma al agotarse el crédito de cómputo | Conforme a DA-002, el crédito vence a los 90 días | Nivel estratégico |
| Incorporación de análisis de archivos subidos | El control A.8.7 está declarado como vacío y el riesgo R-09 aceptado de forma razonada | Nivel táctico |

---

## Control de versiones

| Versión | Fecha | Autor | Cambio |
|---|---|---|---|
| 1.0 | 2026-09-21 | Nivel táctico — Arquitecto de Seguridad | Emisión inicial. Recoge ocho decisiones, incluidas las que documentan un componente anunciado y no desplegado (DA-003) y un cambio de proveedor (DA-002) |
</content>
