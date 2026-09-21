# Plan de Respuesta a Incidentes de Seguridad

| | |
|---|---|
| **Identificador** | POL-002 |
| **Versión** | 1.0 |
| **Fecha de emisión** | 21 de septiembre de 2026 |
| **Elaborado por** | Nivel táctico — Arquitecto de Seguridad |
| **Aprobado por** | Nivel estratégico — Director de Seguridad de la Información (CISO) |
| **Clasificación** | Uso interno del proyecto |
| **Marco de referencia** | NIST SP 800-61r3 · Perfil comunitario del Marco de Ciberseguridad 2.0 |
| **Próxima revisión** | 21 de marzo de 2027, o tras el primer incidente de severidad crítica |

---

## 1. Por qué existe este documento

El anexo del triángulo de la ciberresiliencia verificó que la propuesta del proyecto cubría el vértice
de protección, dejaba el de detección instrumentado pero sin operación definida, y dejaba el de
respuesta **ausente por completo**. La observación era concreta: ninguno de los siete entregables del
cronograma correspondía a un plan de respuesta, no existían procedimientos operativos, ni matriz de
escalamiento, ni objetivos de recuperación, ni plan de comunicación ante una brecha.

Ese vacío producía además una inconsistencia interna verificable. El primer objetivo del modelo de
negocio comprometía una disponibilidad del 99.9 %, equivalente a unos 43 minutos de indisponibilidad
mensual, mientras que la plataforma tecnológica declaraba respaldos mediante instantáneas semanales.
Ante un cifrado malicioso de datos, la restauración se habría medido en días y con pérdida de hasta
una semana completa de transacciones, es decir, exactamente lo contrario de lo que el modelo exige
cuando pide restaurar en minutos y no en semanas.

Este plan cierra esa brecha. Su exigencia más estricta no es documental: el modelo del triángulo pide
procedimientos **ensayados**, y un plan que nunca se ha probado tiene el mismo valor operativo que no
tener plan alguno. Por esa razón la sección 9 fija el simulacro de mesa como requisito de cierre y no
como actividad opcional.

## 2. Estructura conforme a NIST SP 800-61r3

La revisión 3 de la guía del NIST abandonó el ciclo de cuatro fases de la revisión anterior
—preparación, detección y análisis, contención y erradicación, y actividad posterior— y reorganizó la
respuesta a incidentes sobre las seis funciones del Marco de Ciberseguridad 2.0. El cambio no es de
nomenclatura: reconoce que la respuesta no es una fase que empieza cuando suena la alarma, sino una
capacidad que se gobierna, se prepara y se mejora de forma continua.

Este plan adopta esa estructura. La tabla siguiente traduce cada función a su contenido concreto en
MarketGT y señala dónde se desarrolla.

| Función del Marco 2.0 | Qué exige | Dónde se desarrolla aquí |
|---|---|---|
| **Gobernar (GV)** | Que exista una política, roles asignados, criterio de riesgo y revisión periódica | POL-001 y secciones 3 y 4 de este plan |
| **Identificar (ID)** | Conocer los activos, las amenazas y el impacto de su pérdida | Matriz de riesgos y sección 5, clasificación de incidentes |
| **Proteger (PR)** | Los controles que reducen la probabilidad de que el incidente ocurra | Seis capas del esquema de defensa en profundidad |
| **Detectar (DE)** | Descubrir el evento y decidir si constituye incidente | Sección 6, detección y triaje |
| **Responder (RS)** | Contener, erradicar, comunicar y preservar evidencia | Secciones 7 y 8 |
| **Recuperar (RC)** | Restablecer el servicio y verificar que la restauración es íntegra | Sección 8 y POL-004 |

Las funciones de gobernar e identificar no son fases previas que se agotan: operan de forma continua y
son las que hacen posible el ciclo. La función de mejora del modelo SKiP que el proyecto ya adopta se
corresponde con la retroalimentación descrita en la sección 9.

## 3. Definiciones

Distinguir estos tres términos es lo que evita que el equipo escale todo o no escale nada.

**Evento de seguridad.** Cualquier ocurrencia observable en un sistema o red que tenga relevancia para
la seguridad. Una petición bloqueada por el cortafuegos es un evento. La plataforma registra miles.

**Alerta.** Un evento, o una correlación de eventos, que el motor de correlación considera digno de
atención humana según una regla declarada. La plataforma genera alertas con estado inicial `nueva`.

**Incidente de seguridad.** Un evento o conjunto de eventos que compromete o amenaza de forma
verosímil la confidencialidad, la integridad o la disponibilidad de la información. Toda alerta se
triará; solo algunas se declaran incidente.

**Brecha de datos.** Incidente en el que información clasificada como confidencial o restringida ha
sido, o verosímilmente ha podido ser, accedida, copiada o divulgada sin autorización. Toda brecha es
un incidente; no todo incidente es una brecha. La distinción activa las obligaciones de comunicación
de la sección 10.

## 4. El equipo de respuesta y sus funciones

El equipo del proyecto lo integran tres personas, de modo que una persona desempeña varias funciones.
Esto se declara de forma explícita porque ocultarlo tras un organigrama ficticio sería precisamente el
tipo de declaración que una verificación desmiente. Lo que el plan sí garantiza es que **cada función
tiene un titular nombrado y un suplente**, y que la función de custodia de la evidencia no recae en
quien ejecuta la contención.

| Función | Nivel | Responsabilidad durante el incidente | Autoridad |
|---|---|---|---|
| **Coordinador de Respuesta** | Táctico | Dirige el incidente de principio a fin, mantiene la bitácora, decide el escalamiento y declara el cierre | Puede ordenar la contención y solicitar la aprobación de medidas disruptivas |
| **Analista de Turno** | Operativo | Recibe la alerta, ejecuta el triaje, aplica la contención de primer nivel y notifica | Puede bloquear una dirección de origen y forzar el cierre de sesiones sin autorización previa |
| **Responsable de Plataforma** | Operativo | Ejecuta las acciones sobre la infraestructura: aislamiento, restauración, rotación de secretos y despliegue de correcciones | Puede detener el servicio bajo instrucción del Coordinador |
| **Custodio de Evidencia** | Operativo (rol de auditor) | Preserva, sella y registra la evidencia; mantiene la cadena de custodia | Puede impedir una acción que destruya evidencia no preservada, salvo que el Coordinador la ordene por escrito |
| **Responsable de Comunicación** | Estratégico | Redacta y emite las comunicaciones internas y externas | Única función autorizada a comunicar con terceros |
| **Autoridad de Declaración** | Estratégico (CISO) | Declara el incidente de severidad crítica, aprueba las medidas de impacto en el negocio y autoriza la notificación a titulares | Indelegable; en su ausencia la asume la Dirección General |

### 4.1 Regla de separación

El Custodio de Evidencia no ejecuta acciones de contención sobre el mismo incidente. La razón es
elemental: quien apaga un contenedor destruye su memoria, y quien decide apagarlo no debe ser quien
certifica que no se perdió nada. Cuando la disponibilidad de personas impida cumplir esta separación,
se hace constar en la bitácora del incidente como limitación conocida, con nombre de la función que
quedó acumulada.

## 5. Clasificación de incidentes por severidad

La severidad se determina por el impacto verificado o verosímil, no por el ruido que genere la alerta.
Un escaneo automatizado de miles de peticiones bloqueadas es severidad baja; un único inicio de sesión
exitoso desde una dirección improbable en una cuenta administrativa es severidad crítica.

| Severidad | Criterio de asignación | Ejemplos |
|---|---|---|
| **S1 · Crítica** | Compromiso confirmado de una cuenta administrativa, evidencia de acceso no autorizado a datos confidenciales, cifrado malicioso de datos, o indisponibilidad total del servicio | Sesión administrativa establecida desde origen no reconocido; base de datos cifrada por un tercero; exfiltración confirmada |
| **S2 · Alta** | Intento de compromiso con indicios de éxito parcial, o degradación grave del servicio | Cadena de inyección que atravesó el cortafuegos y alcanzó la aplicación; denegación de servicio que degrada la respuesta; extracción masiva del catálogo en curso |
| **S3 · Media** | Actividad hostil contenida por los controles, con patrón sostenido o dirigido | Ráfaga de inyección SQL bloqueada por el cortafuegos; intentos repetidos de fuerza bruta contra el segundo factor; rastreador falsificado detectado |
| **S4 · Baja** | Actividad hostil aislada y contenida, sin patrón ni persistencia | Petición individual bloqueada; escaneo de puertos desde una dirección única; intento de redirección abierta rechazado |
| **Informativa** | Evento registrado con valor de contexto, sin carácter hostil | Cambio de configuración, despliegue, inicio de sesión legítimo de cuenta privilegiada |

La escala se corresponde uno a uno con las severidades que la plataforma asigna a los eventos en el
panel de monitoreo —`critica`, `alta`, `media`, `baja` e `informativa`—, de modo que el operador no
traduce entre dos vocabularios durante un incidente, que es cuando peor se traduce.

### 5.1 Reglas de elevación automática

Tres circunstancias elevan la severidad con independencia del criterio anterior, porque cambian la
naturaleza del problema:

1. Si la actividad involucra una cuenta con rol de administrador o de auditor, la severidad sube un
   nivel.
2. Si existe indicio verosímil de acceso a datos clasificados como confidenciales, el incidente se
   trata como S1 hasta que se demuestre lo contrario. La carga de la prueba recae sobre quien afirma
   que no hubo acceso, no sobre quien lo sospecha.
3. Si el cortafuegos deja de registrar o el panel deja de ingerir eventos, el incidente es S2 como
   mínimo: la pérdida de visibilidad es en sí misma un incidente, no una avería.

## 6. Detección y triaje

### 6.1 Fuentes de detección

| Fuente | Qué aporta | Ruta o mecanismo |
|---|---|---|
| Registro de auditoría del cortafuegos | Peticiones inspeccionadas, reglas activadas, puntuación de anomalía y si la petición fue interrumpida | `/var/log/modsecurity/audit/audit.json`, un objeto JSON por línea |
| Bitácora de seguridad de la aplicación | Autenticación, cambios de segundo factor, operaciones sensibles y accesos denegados | `storage/logs/seguridad.log` |
| Tabla de registros de auditoría | Los mismos hechos, consultables y cruzables con pedidos y usuarios | Tabla `registros_auditoria` |
| Motor de correlación | Alertas derivadas de reglas con umbral y ventana temporal | Tablas `reglas_correlacion` y `alertas_seguridad` |
| Vigilancia de integridad de posicionamiento | Alteración de contenido indexable y metadatos | Tabla `lineas_base_seo` y comando de vigilancia |
| Reporte de una persona | Un cliente que denuncia un cargo no reconocido, o un integrante que observa algo anómalo | Canal de guardia declarado en POL-003 |

Conviene registrar una limitación real: los registros de la capa de perímetro no se integran al panel,
porque esa capa está implementada de forma parcial conforme a la decisión DA-001. La detección se
sostiene íntegramente sobre las capas dos a seis.

### 6.2 Procedimiento de triaje

El Analista de Turno ejecuta el triaje sobre toda alerta en estado `nueva`, dentro del plazo que fija
POL-003 según su severidad. El triaje responde cinco preguntas en este orden:

1. **¿Es real?** ¿Existe un hecho verificable detrás de la alerta, o es un falso positivo de una regla
   demasiado amplia? La meta declarada del proyecto es mantener los falsos positivos por debajo del
   2 % del tráfico legítimo.
2. **¿Tuvo éxito?** El campo que indica si la transacción fue interrumpida distingue el ataque
   bloqueado del ataque que pasó. Esa distinción separa una S3 de una S1.
3. **¿Qué alcanzó?** Qué activo, qué datos y qué cuentas quedaron dentro del alcance verosímil.
4. **¿Sigue ocurriendo?** Un ataque en curso se contiene antes de analizarse; uno concluido se analiza
   antes de actuar.
5. **¿Qué severidad corresponde?** Conforme a la sección 5 y a sus reglas de elevación.

El resultado del triaje es siempre uno de cuatro: se descarta como falso positivo y se registra como
tal para ajustar la regla; se cierra como evento sin consecuencia; se declara incidente con su
severidad; o se escala por insuficiencia de información, que es una respuesta legítima y preferible a
una clasificación inventada.

Los estados de la alerta en el panel acompañan este ciclo: `nueva`, `en_triaje`, `contenida`,
`cerrada` y `falso_positivo`. Ninguna alerta se cierra sin que conste su estado final.

## 7. Procedimientos operativos por tipo de incidente

Cada procedimiento se estructura igual: cómo se detecta, qué se contiene primero, cómo se erradica,
cómo se recupera y qué evidencia debe quedar. El orden de las acciones no es arbitrario: **primero se
preserva lo volátil, después se contiene, y solo al final se restaura**, salvo que la contención
inmediata evite un daño mayor, decisión que corresponde al Coordinador y que se registra.

---

### 7.1 PR-01 · Compromiso de credenciales

**Severidad inicial:** S1 si la cuenta tiene rol administrativo o de auditor; S2 en cuenta de cliente.

**Indicadores de detección.** Inicio de sesión exitoso desde una geografía o dirección sin precedente
para la cuenta; segundo factor retirado o regenerado sin solicitud del titular; secuencia de intentos
fallidos seguida de un intento exitoso; cliente que denuncia actividad que no reconoce; acceso
correcto fuera del patrón horario habitual de una cuenta privilegiada.

**Contención.**

1. Cerrar la totalidad de las sesiones activas de la cuenta afectada. La aplicación expone esta
   operación en la pantalla de sesiones activas y registra cada cierre en la bitácora.
2. Desactivar la cuenta sin eliminarla. Eliminarla destruiría la trazabilidad de sus pedidos y de sus
   registros de auditoría, que es la evidencia del propio incidente.
3. Invalidar los códigos de recuperación vigentes y, si la cuenta tenía segundo factor activo,
   presumir comprometido su secreto compartido.
4. Bloquear la dirección de origen si el patrón lo justifica, con la precaución de verificar que no se
   trate de una dirección compartida por usuarios legítimos.

**Erradicación.** Determinar el vector: contraseña reutilizada, suplantación de sitio, dispositivo
comprometido o defecto de la aplicación. El vector determina el alcance: si fue un defecto de la
aplicación, el incidente no afecta a una cuenta sino a todas, y la severidad se recalcula.

**Recuperación.** Restablecer el acceso solo tras verificar la identidad del titular por un canal
distinto del comprometido, exigir el registro de un nuevo segundo factor —preferentemente una
credencial de clave pública, por su resistencia a la suplantación de sitio— y entregar códigos de
recuperación nuevos. Reactivar la cuenta y vigilarla de forma específica durante los siete días
siguientes.

**Evidencia obligatoria.** Registros de la tabla de auditoría correspondientes a la cuenta en la
ventana del incidente; entradas de la bitácora de seguridad relativas a autenticación y a cambios de
segundo factor; relación de sesiones cerradas con su dirección y su agente de usuario; y, si la hubo,
la denuncia del titular con su fecha y su canal.

---

### 7.2 PR-02 · Ataque de inyección detectado por el cortafuegos

**Severidad inicial:** S3 si la transacción fue interrumpida; **S1 si no lo fue**.

**Indicadores de detección.** Eventos del registro de auditoría con reglas activadas de las familias
941 —secuencias de comandos en sitios cruzados— o 942 —inyección SQL—, con puntuación de anomalía
igual o superior al umbral entrante de 5. Con el umbral configurado, un único acierto de severidad
crítica basta para alcanzarlo y producir la respuesta 403.

**Triaje diferencial.** La primera lectura del evento debe responder si el cortafuegos interrumpió la
petición. El campo correspondiente del registro lo indica de forma explícita.

| Situación | Lectura | Acción |
|---|---|---|
| Petición interrumpida, origen único, intentos aislados | Sondeo automatizado | S4. Registrar y observar |
| Petición interrumpida, patrón sostenido o dirigido a rutas con parámetros | Ataque dirigido en curso | S3. Contener origen y vigilar |
| Petición **no** interrumpida con carga útil de inyección | El control falló o existe una exclusión mal acotada | S1. Activar contención completa |
| Regla activada sobre tráfico legítimo | Falso positivo | Registrar, ajustar exclusión acotada a la regla y a la ruta |

**Contención.** Ante una petición no interrumpida, verificar de inmediato si la aplicación llegó a
ejecutar la carga: consultar la tabla de auditoría en busca de operaciones anómalas en la ventana
temporal exacta del evento, y revisar el registro de consultas de la base de datos si estuviera
habilitado. Bloquear el origen en el cortafuegos de red. Si existe sospecha de que la inyección
alcanzó la base de datos, el incidente escala a PR-05, fuga de datos.

**Erradicación.** Si el ataque atravesó el cortafuegos, la causa raíz está en la aplicación o en una
exclusión demasiado amplia. Corregir la validación de entrada o la consulta afectada y revisar el
archivo de exclusiones. Nunca se resuelve un falso positivo poniendo el motor de reglas en modo de
solo detección: se resuelve con una exclusión acotada, documentada y con identificador de regla.

**Recuperación.** Desplegar la corrección, verificar con el guion de demostración del cortafuegos que
el vector concreto vuelve a producir respuesta 403, y confirmar que el tráfico legítimo equivalente no
resulta afectado.

**Evidencia obligatoria.** Objeto JSON completo de la transacción del registro de auditoría, incluido
su identificador único, la dirección de origen, la marca temporal, el método, la URI, el cuerpo, las
reglas activadas con su identificador y su severidad, y el código de respuesta. Ese identificador
único es la clave que permite reconstruir el incidente completo y debe citarse en toda la bitácora.

---

### 7.3 PR-03 · Denegación de servicio

**Severidad inicial:** S2; S1 si el servicio queda íntegramente indisponible.

**Indicadores de detección.** Aumento abrupto del volumen de peticiones desde un conjunto acotado de
direcciones; agotamiento de conexiones del servidor web; degradación del tiempo de respuesta;
saturación de memoria o de procesador en la máquina virtual; y, en el caso de la capa de datos,
consultas encoladas por bloqueo.

**Limitación conocida que debe declararse.** Conforme a la decisión DA-001, la capa de perímetro está
implementada de forma parcial y la plataforma **no cuenta con mitigación de denegación de servicio en
el borde de la red**. La contención disponible actúa una vez que el tráfico ha alcanzado el servidor,
de modo que no protege frente a la saturación del enlace. Ante un ataque distribuido de volumen
apreciable, la respuesta realista es la restauración del servicio y no su defensa. Declarar lo
contrario sería el tipo de afirmación que este plan prohíbe.

**Contención.**

1. Identificar el patrón: direcciones de origen, rutas objetivo, agente de usuario y distribución
   temporal.
2. Bloquear los orígenes en el cortafuegos de red. El bloqueo automático por intentos fallidos ya
   actúa tras cinco respuestas 403 desde una misma dirección en diez minutos.
3. Aplicar los limitadores de tasa de la aplicación sobre las rutas afectadas.
4. Si la aplicación se degrada pero el cortafuegos sigue en pie, conmutar el destino del proxy inverso
   al servicio de respaldo estático. El conjunto de contenedores incluye este servicio precisamente
   para que un fallo de la aplicación no arrastre la inspección del cortafuegos: las reglas siguen
   evaluando y el registro de auditoría se sigue escribiendo.

**Erradicación y recuperación.** Determinar si el ataque explotaba una ruta costosa en particular
—una búsqueda sin índice, una exportación— y corregirla. Restablecer el destino del proxy hacia la
aplicación y verificar la respuesta con tráfico legítimo.

**Evidencia obligatoria.** Registro de acceso del servidor web con el volumen por origen y por minuto;
métricas de recursos de la máquina virtual durante la ventana; relación de direcciones bloqueadas con
la hora del bloqueo; y duración total de la indisponibilidad, que es el dato que permite contrastar el
compromiso de disponibilidad declarado.

---

### 7.4 PR-04 · Cifrado malicioso de datos

**Severidad inicial:** S1 sin excepción.

**Indicadores de detección.** Archivos renombrados de forma masiva o con extensión desconocida; nota
de rescate; fallo del motor de base de datos al abrir sus archivos; proceso desconocido con consumo
sostenido de disco y procesador; alteración de la línea base de integridad del contenido indexable.

**Contención — y el orden importa.**

1. **Aislar antes que apagar.** Desconectar la máquina virtual de la red pública manteniendo el acceso
   administrativo por consola del proveedor. Apagar destruye la memoria, que puede contener la clave
   de cifrado.
2. **No pagar y no negociar.** La decisión es del nivel estratégico y está tomada de antemano: no se
   establece contacto con el atacante.
3. **Preservar antes de restaurar.** Tomar una instantánea del disco en su estado cifrado. Es la
   evidencia del incidente y, en ocasiones, la única vía de recuperación si más tarde se publica un
   descifrador.
4. **Verificar el respaldo antes de tocarlo.** Comprobar que la copia externa más reciente es anterior
   a la infección y que su firma coincide. Restaurar desde un respaldo ya infectado reinicia el
   incidente y destruye el único juego de datos sano.

**Recuperación.** Reconstruir el servidor desde cero mediante los guiones de aprovisionamiento y
endurecimiento —nunca limpiando el sistema comprometido— y restaurar los datos desde el respaldo
cifrado externo verificado. Rotar la totalidad de los secretos: clave de la aplicación, credenciales
de base de datos, llaves de acceso administrativo y secreto de la consola de demostración.

**Objetivos aplicables.** Objetivo de punto de recuperación de 24 horas y objetivo de tiempo de
recuperación de 4 horas, conforme a POL-004. Estos objetivos son la razón por la que el esquema de
respaldo semanal original fue sustituido: con respaldo semanal, este procedimiento habría significado
la pérdida de hasta siete días de transacciones.

**Evidencia obligatoria.** Instantánea del disco cifrado con su suma de verificación; nota de rescate
si existe, preservada sin abrir enlaces; identificación del vector de entrada; hora del primer archivo
afectado; y acta de restauración con la hora de inicio, la hora de servicio restablecido y el volumen
de datos perdidos entre el último respaldo y el momento del cifrado.

---

### 7.5 PR-05 · Fuga de datos

**Severidad inicial:** S1 sin excepción, hasta que se demuestre que no hubo acceso.

**Indicadores de detección.** Volumen anómalo de respuesta hacia un único origen; reglas de la familia
95x activadas sobre el cuerpo de la respuesta, que es la razón por la que la inspección del cuerpo de
respuesta permanece habilitada; consultas que devuelven conjuntos completos de tablas sensibles;
aparición pública de datos de la plataforma; acceso a la tabla de usuarios desde una ruta que no lo
requiere.

**Contención.**

1. Cortar el canal de salida: bloquear el origen, revocar la sesión o el testigo empleado y, si el
   vector es una ruta de la aplicación, deshabilitarla.
2. Delimitar el alcance con precisión antes de comunicar nada. Qué tablas, qué columnas, cuántos
   registros y qué titulares. Una comunicación con alcance equivocado hay que rectificarla, y una
   rectificación cuesta más credibilidad que la demora.
3. Determinar si los datos sustraídos estaban cifrados. Las columnas de dirección, teléfono y
   documento de identidad se almacenan cifradas, de modo que su sustracción sin la clave de la
   aplicación no constituye divulgación efectiva. Esta verificación cambia por completo la obligación
   de comunicación, y debe hacerse antes de emitirla.

**Erradicación.** Corregir el defecto que permitió el acceso, rotar la clave de cifrado de la
aplicación si existe sospecha de que fue expuesta —lo que obliga a recifrar los datos personales— y
revisar si el mismo defecto existe en otras rutas.

**Comunicación.** Activa de forma obligatoria el procedimiento de la sección 10.

**Evidencia obligatoria.** Consultas ejecutadas en la ventana, volumen de respuesta por petición,
identificador único de las transacciones implicadas, relación nominal de titulares afectados
—custodiada como información confidencial y nunca incorporada al cuerpo de la bitácora— y
determinación documentada de si los datos estaban cifrados.

---

### 7.6 PR-06 · Envenenamiento de posicionamiento

**Severidad inicial:** S3; S2 si el contenido alterado llegó a ser servido a un rastreador legítimo.

Este procedimiento atiende una familia de ataques que el proyecto detecta con reglas propias —las que
ocupan el espacio de identificadores 15000-15121— y que persiguen un objetivo distinto del habitual:
no buscan robar datos ni tumbar el servicio, sino apropiarse de la reputación del dominio en los
motores de búsqueda. Su efecto es diferido y su detección exige comparar contra una línea base, porque
el sitio sigue funcionando con normalidad mientras el ataque surte efecto.

**Variantes e indicadores.**

| Variante | Qué hace el atacante | Señal de detección |
|---|---|---|
| Rastreador falsificado | Se presenta como un rastreador legítimo para recibir contenido distinto | El agente de usuario declara un rastreador conocido pero la dirección de origen no pertenece a sus rangos publicados |
| Encubrimiento | Sirve contenido distinto al rastreador y al visitante | Divergencia entre el contenido indexable y la línea base registrada |
| Inyección de enlaces | Inserta enlaces hacia sitios de terceros en contenido generado por usuarios | Reglas propias sobre reseñas y comentarios; detector de contenido no solicitado |
| Redirección abierta | Usa un parámetro de redirección del sitio para enviar tráfico a un destino externo | Parámetro de destino con origen externo no permitido |
| Extracción masiva | Descarga el catálogo completo para republicarlo | Volumen y cadencia de peticiones sobre rutas de catálogo |

**Contención.** Retirar el contenido inyectado; revocar la sesión o la cuenta que lo publicó; bloquear
el origen falsificado; y, ante una redirección abierta, restringir el destino a la lista de dominios
permitidos.

**Erradicación y recuperación.** Restablecer el contenido desde la línea base, verificar que el
archivo de mapa del sitio y las directivas de indexación no fueron alterados y, cuando el contenido
alterado haya sido efectivamente indexado, solicitar su reindexación al motor de búsqueda. La
recuperación de este incidente no termina cuando el sitio queda limpio, sino cuando el índice del
motor de búsqueda lo refleja, lo que puede tardar días.

**Evidencia obligatoria.** Registro del incidente en la tabla correspondiente; comparación entre la
línea base y el contenido alterado; evento del cortafuegos con la regla propia activada y su
identificador; y, cuando exista, captura del resultado de búsqueda que muestre el contenido envenenado.

---

## 8. Tiempos objetivo

Los plazos se cuentan desde la generación de la alerta, no desde que alguien la lee. La diferencia
entre ambos momentos es precisamente lo que mide el tiempo medio de detección.

| Severidad | Triaje iniciado | Notificación al Coordinador | Contención | Erradicación | Cierre y lecciones |
|---|---|---|---|---|---|
| **S1 · Crítica** | 15 minutos | Inmediata | 2 horas | 24 horas | 5 días hábiles |
| **S2 · Alta** | 1 hora | 1 hora | 8 horas | 72 horas | 10 días hábiles |
| **S3 · Media** | 4 horas | Siguiente turno | 72 horas | Siguiente ciclo de despliegue | Revisión semestral |
| **S4 · Baja** | Siguiente turno | No requiere | Siguiente ciclo | Siguiente ciclo | Revisión semestral |

Las metas transversales declaradas en el anexo del proyecto y configuradas en el panel de monitoreo
son las siguientes, y el panel las contrasta contra el valor calculado:

| Métrica | Meta | Vértice |
|---|---|---|
| Tiempo medio de detección | Menor o igual a 30 minutos | Detección |
| Tasa de falsos positivos | Menor al 2 % | Detección |
| Tiempo medio de contención | Menor o igual a 2 horas | Respuesta |
| Objetivo de tiempo de recuperación | 4 horas | Respuesta |
| Objetivo de punto de recuperación | 24 horas | Respuesta |
| Cobertura de parcheo crítico | 100 % en 72 horas | Protección |
| Personal capacitado | 100 % semestral | Protección |

Cuando una métrica no puede calcularse con los datos disponibles, el panel debe mostrarla como «sin
datos» en lugar de suponer un valor. Una métrica inventada invalida la auditoría completa.

## 9. Cadena de custodia de la evidencia

La evidencia de un incidente cumple dos funciones: sostener el análisis técnico y sostener cualquier
afirmación posterior sobre lo ocurrido. La segunda función se pierde si no puede demostrarse que la
evidencia no fue alterada entre su recolección y su presentación.

### 9.1 Principios

**Orden de volatilidad.** Se recolecta primero lo que antes desaparece: memoria y procesos en
ejecución, conexiones activas, contenido de archivos temporales, registros en disco, y por último
copias de respaldo. Apagar un contenedor antes de recolectar destruye de forma irreversible los tres
primeros niveles.

**Copia, no original.** El análisis se ejecuta sobre una copia. El original se sella y se guarda.

**Sellado por resumen criptográfico.** Toda pieza de evidencia se acompaña de su resumen SHA-256,
calculado en el momento de la recolección y registrado en el acta. Cualquier alteración posterior
resulta detectable.

**Registro de cada traspaso.** Cada vez que la evidencia cambia de manos o de ubicación se anota
quién, cuándo y con qué propósito.

### 9.2 Formato del acta de evidencia

Cada pieza se registra en `evidencias/incidentes/<identificador-del-incidente>/` con un acta que
contiene los campos siguientes:

| Campo | Contenido |
|---|---|
| Identificador de la pieza | Correlativo dentro del incidente, por ejemplo `INC-2026-003-E04` |
| Descripción | Qué es y por qué es relevante |
| Origen | Sistema, ruta y método de obtención, con el comando exacto empleado |
| Fecha y hora de recolección | En huso horario de Guatemala, UTC−6, y con su equivalente en UTC |
| Recolectado por | Función, no nombre de persona |
| Resumen SHA-256 | Calculado sobre el archivo tal como se preservó |
| Tamaño | En bytes |
| Traspasos | Relación de fecha, función de origen, función de destino y propósito |

### 9.3 Identificación de los incidentes

Los incidentes se numeran de forma correlativa con el formato `INC-AAAA-NNN`. La bitácora del
incidente recoge, en orden cronológico y con marca temporal, cada hecho observado y cada acción
ejecutada, incluidas las que resultaron infructuosas. Una bitácora que solo recoge los aciertos no
sirve para aprender del incidente, que es el objeto de la sección 11.

Cuando la evidencia proviene del registro del cortafuegos, la bitácora cita el identificador único de
la transacción. Es la clave que permite reconstruir la petición completa sin depender de una búsqueda
por hora aproximada.

## 10. Comunicación a los titulares de los datos

### 10.1 Marco aplicable

La República de Guatemala no cuenta con una ley general de protección de datos personales en vigor que
imponga un plazo de notificación ante una brecha. Esta circunstancia se hace constar de forma expresa
porque determina la naturaleza de la obligación: en ausencia de mandato legal, **el proyecto adopta la
notificación de forma voluntaria**, por criterio de diligencia y no por imperativo normativo.

El criterio adoptado toma como referencia el plazo de 72 horas consolidado en la práctica
internacional y las obligaciones de comunicación del estándar PCI DSS cuando exista afectación de
datos de pago. La adopción voluntaria de un estándar más exigente que el mínimo legal es una decisión
del nivel estratégico y consta como tal.

### 10.2 Criterio de activación

Se comunica cuando concurren dos condiciones: que exista acceso no autorizado verificado o verosímil a
información clasificada como confidencial, y que ese acceso afecte a titulares identificables. No se
comunica cuando los datos sustraídos estaban cifrados y no existe indicio de que la clave haya sido
comprometida; esa determinación se documenta con su fundamento, porque es la diferencia entre una
decisión razonada y un silencio conveniente.

### 10.3 Plazos y contenido

| Momento | Destinatario | Contenido |
|---|---|---|
| Dentro de las 24 horas de la declaración | Nivel estratégico completo | Hechos conocidos, alcance preliminar y medidas de contención adoptadas |
| Dentro de las 72 horas | Titulares afectados | Comunicación conforme al contenido mínimo siguiente |
| Al cierre del incidente | Titulares afectados | Resultado final, si el alcance cambió respecto de la primera comunicación |

El contenido mínimo de la comunicación al titular comprende: la naturaleza del incidente en lenguaje
comprensible y sin tecnicismos innecesarios; las categorías de datos afectadas, con precisión sobre
cuáles no lo fueron; la fecha o el intervalo en que ocurrió; las medidas adoptadas para contenerlo;
las medidas que el propio titular debería tomar, como cambiar su contraseña o revisar movimientos; y
un canal de contacto para consultas.

La comunicación la emite exclusivamente el Responsable de Comunicación, con aprobación previa del
CISO. Ningún otro integrante del equipo comunica nada a un tercero sobre un incidente en curso.

### 10.4 Lo que la comunicación no hace

No minimiza el alcance conocido, no atribuye responsabilidad a un tercero sin prueba, no promete
resultados de una investigación en curso y no se demora a la espera de tener la investigación
completa. La primera comunicación se emite con lo que se sabe, declarando explícitamente lo que
todavía no se sabe.

## 11. Lecciones aprendidas y mejora continua

### 11.1 Revisión posterior al incidente

Todo incidente S1 o S2 exige una revisión formal dentro de los plazos de cierre de la sección 8. Los
incidentes S3 y S4 se revisan de forma agregada en la revisión semestral, buscando patrones que un
caso aislado no muestra.

La revisión responde seis preguntas y produce un acta:

1. ¿Qué ocurrió exactamente, en orden cronológico?
2. ¿Qué control debió haberlo impedido y por qué no lo hizo?
3. ¿Cuánto tardó la detección y por qué tardó eso?
4. ¿Qué acción de la respuesta funcionó y cuál no?
5. ¿Qué información faltó en el momento de decidir?
6. ¿Qué cambia a partir de ahora, con responsable y fecha?

### 11.2 Regla de la causa raíz

La revisión no se detiene en la causa inmediata. Que un atacante haya explotado una dependencia
desactualizada no es la causa raíz: la causa raíz es que la dependencia estaba desactualizada, y la
pregunta pertinente es por qué el proceso de parcheo no la alcanzó. La acción correctiva se dirige
siempre al proceso, no únicamente al síntoma.

### 11.3 Búsqueda de culpables

La revisión posterior no busca responsables individuales. Un equipo que teme la revisión oculta
información, y un plan de respuesta que se alimenta de información oculta no funciona. Lo que sí se
registra sin atenuantes es el hecho: qué se hizo, cuándo y con qué consecuencia.

### 11.4 Realimentación hacia los controles

Cada lección aprendida produce al menos una de estas salidas, con responsable y fecha comprometida:
un ajuste de una regla del cortafuegos o de una regla de correlación; una corrección en el código de
la aplicación; una modificación de esta política o de la matriz de escalamiento; una actualización de
la matriz de riesgos; o un punto en el plan de concienciación, cuando la causa fue humana.

Esta realimentación es la fase de mejora del ciclo SKiP que el proyecto adopta y, en la estructura del
Marco de Ciberseguridad 2.0, corresponde a la función de gobernar: la que convierte la respuesta en
una capacidad que mejora y no en una reacción que se repite.

## 12. Ensayo del plan

El modelo del triángulo de la ciberresiliencia exige procedimientos ensayados. Este plan fija dos
ejercicios:

| Ejercicio | Frecuencia | Alcance | Evidencia |
|---|---|---|---|
| **Simulacro de mesa** | Semestral, y uno obligatorio antes de la puesta en producción | Recorrido verbal de un escenario completo por el equipo, con cronometraje de las decisiones | Acta con el escenario, los participantes por función, los tiempos alcanzados y las deficiencias detectadas |
| **Prueba de restauración** | Mensual | Restauración efectiva del respaldo en entorno aislado, conforme a POL-004 | Acta de resultado con tiempo empleado y verificación de integridad |

El simulacro previsto antes de la puesta en producción corresponde a la ampliación de la fase 6 del
cronograma acordada en el anexo del proyecto, que se incorpora sin modificar la duración total de 16
semanas.

**Estado al 21 de septiembre de 2026:** el simulacro de mesa está programado y **no se ha ejecutado
todavía**. Hasta que se ejecute y se levante su acta, el vértice de respuesta debe considerarse
documentado pero no verificado. Así consta en la matriz de controles.

## 13. Control de versiones

| Versión | Fecha | Autor | Cambio |
|---|---|---|---|
| 1.0 | 2026-09-21 | Nivel táctico — Arquitecto de Seguridad | Emisión inicial. Cierra el vértice de respuesta declarado ausente en el anexo del triángulo de la ciberresiliencia |

---

## Referencias

Nelson, A., Rekhi, S., Souppaya, M., y Scarfone, K. (2025). *Incident response recommendations and
considerations for cybersecurity risk management: A CSF 2.0 community profile* (NIST SP 800-61r3).
National Institute of Standards and Technology. https://doi.org/10.6028/NIST.SP.800-61r3

National Institute of Standards and Technology. (2024). *The NIST Cybersecurity Framework (CSF) 2.0*
(NIST CSWP 29). https://doi.org/10.6028/NIST.CSWP.29

Organización Internacional de Normalización. (2022). *ISO/IEC 27001:2022. Seguridad de la información,
ciberseguridad y protección de la privacidad. Sistemas de gestión de la seguridad de la información.
Requisitos.*

Organización Internacional de Normalización. (2022). *ISO/IEC 27002:2022. Controles de seguridad de la
información.*

PCI Security Standards Council. (2024). *Payment Card Industry Data Security Standard: Requirements and
testing procedures (v4.0.1).*
</content>
