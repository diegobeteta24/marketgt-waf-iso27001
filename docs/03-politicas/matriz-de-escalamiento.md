# Matriz de Escalamiento de Incidentes

| | |
|---|---|
| **Identificador** | POL-003 |
| **Versión** | 1.0 |
| **Fecha de emisión** | 21 de septiembre de 2026 |
| **Elaborado por** | Nivel táctico — Gerencia de Operaciones |
| **Aprobado por** | Nivel estratégico — Director de Seguridad de la Información (CISO) |
| **Clasificación** | Uso interno del proyecto |
| **Documento rector** | POL-002 · Plan de respuesta a incidentes |
| **Próxima revisión** | 21 de marzo de 2027 |

---

## 1. Propósito

El anexo del triángulo de la ciberresiliencia identificó dos brechas que este documento atiende de
forma directa: que el proyecto tenía herramientas de detección desplegadas pero **no definía quién
revisa las alertas ni en qué horario**, y que **no existía matriz de escalamiento ni plan de
comunicación ante una brecha**.

Una alerta que nadie mira no es detección. Un procedimiento que no dice a quién llamar a las tres de
la mañana no es respuesta. Esta matriz responde cuatro preguntas para cada severidad: quién se entera,
en cuánto tiempo, por qué medio y quién decide.

Las funciones se designan por puesto y nunca por nombre de persona. Un documento de gestión que nombra
personas caduca en la primera rotación del equipo; uno que nombra funciones sobrevive a ella.

## 2. Funciones que intervienen

| Abreviatura | Función | Nivel | Papel en el escalamiento |
|---|---|---|---|
| **AT** | Analista de Turno | Operativo | Recibe toda alerta, ejecuta el triaje y aplica la contención de primer nivel |
| **RP** | Responsable de Plataforma | Operativo | Ejecuta acciones sobre la infraestructura cuando la contención las exige |
| **CE** | Custodio de Evidencia | Operativo (rol auditor) | Preserva la evidencia y mantiene la cadena de custodia |
| **CR** | Coordinador de Respuesta | Táctico | Dirige el incidente, decide el escalamiento y declara el cierre |
| **GO** | Gerencia de Operaciones | Táctico | Autoriza medidas que degradan el servicio |
| **RC** | Responsable de Comunicación | Estratégico | Redacta y emite toda comunicación externa |
| **CISO** | Director de Seguridad de la Información | Estratégico | Declara el incidente crítico y autoriza la notificación a titulares |
| **DG** | Dirección General | Estratégico | Asume las decisiones del CISO en su ausencia y las que comprometen recursos |

## 3. Matriz de escalamiento por severidad

La columna de plazo se cuenta **desde la generación de la alerta**, no desde que alguien la observa.
Esta precisión no es formal: la diferencia entre ambos momentos es justamente lo que mide el tiempo
medio de detección, cuya meta es de 30 minutos.

### 3.1 Severidad S1 · Crítica

| Nivel | Quién se entera | Plazo | Medio | Quién decide |
|---|---|---|---|---|
| 1 | AT | Inmediato | Notificación automática del panel | AT aplica contención inmediata sin esperar autorización |
| 2 | CR | 15 minutos | Llamada telefónica al número de guardia. Si no contesta en dos intentos, se pasa al nivel 3 | CR toma la dirección del incidente |
| 3 | CISO | 30 minutos | Llamada telefónica; en su defecto, DG | **CISO declara el incidente crítico.** Decisión indelegable |
| 4 | RP y CE | 30 minutos | Grupo de mensajería de guardia, convocados por CR | CR asigna tareas |
| 5 | GO | 1 hora | Grupo de mensajería | GO autoriza la degradación o la detención del servicio si procede |
| 6 | RC | 2 horas, o de inmediato si hay indicio de brecha | Llamada de CR | RC prepara la comunicación; **la emisión requiere aprobación previa del CISO** |
| 7 | DG | 4 horas, o de inmediato si el servicio está detenido | Llamada del CISO | DG decide sobre la continuidad y sobre compromisos con terceros |

**Regla de no bloqueo.** La contención de primer nivel —cerrar sesiones, desactivar una cuenta,
bloquear una dirección de origen— **no espera autorización**. El Analista de Turno la ejecuta y
notifica después. Exigir aprobación para detener una hemorragia garantiza que la hemorragia continúe
durante la espera.

**Regla de decisión indelegable.** La declaración formal del incidente crítico corresponde al CISO y,
en su ausencia acreditada, a la Dirección General. Nadie más la asume. Si ninguno de los dos responde
en un plazo de 60 minutos, el Coordinador de Respuesta actúa **como si el incidente estuviera
declarado**, deja constancia escrita de la ausencia de respuesta y de la hora de cada intento, y la
declaración se formaliza cuando la autoridad se incorpore.

### 3.2 Severidad S2 · Alta

| Nivel | Quién se entera | Plazo | Medio | Quién decide |
|---|---|---|---|---|
| 1 | AT | Inmediato | Notificación del panel | AT ejecuta el triaje y la contención de primer nivel |
| 2 | CR | 1 hora | Grupo de mensajería de guardia; llamada si no hay acuse en 30 minutos | CR confirma la severidad y dirige |
| 3 | RP | 2 horas, si la contención requiere infraestructura | Grupo de mensajería | CR asigna |
| 4 | CE | 2 horas, si hay evidencia que preservar | Grupo de mensajería | CE determina el orden de recolección |
| 5 | CISO | 8 horas, o al cierre de la jornada | Resumen escrito por CR | CISO decide si el incidente se eleva a S1 |
| 6 | GO | Solo si se requiere degradar el servicio | Llamada | GO autoriza |

### 3.3 Severidad S3 · Media

| Nivel | Quién se entera | Plazo | Medio | Quién decide |
|---|---|---|---|---|
| 1 | AT | Inmediato | Panel | AT ejecuta el triaje |
| 2 | CR | Siguiente turno de guardia, máximo 24 horas | Resumen del turno | CR decide si procede investigación |
| 3 | CISO | Informe semanal agregado | Documento escrito | CISO revisa patrones |

Un incidente S3 que se repite tres veces en siete días **deja de ser S3**. La repetición sostenida
indica que el control que lo contiene está siendo sondeado de forma sistemática, y el Coordinador de
Respuesta lo eleva a S2.

### 3.4 Severidad S4 · Baja

| Nivel | Quién se entera | Plazo | Medio | Quién decide |
|---|---|---|---|---|
| 1 | AT | Siguiente turno | Panel | AT registra y cierra |
| 2 | CR | Informe semanal agregado | Documento escrito | CR revisa tendencia |

## 4. Turno de guardia

El anexo del proyecto señaló de manera expresa que el sistema de gestión de eventos estaba desplegado
pero que no se definía **quién monitorea las alertas ni en qué horario**. Esta sección cubre esa
brecha.

### 4.1 Régimen del turno

| Aspecto | Definición |
|---|---|
| **Titularidad** | Un integrante del equipo, en su función de Analista de Turno |
| **Rotación** | Semanal, de lunes a domingo, con relevo los lunes a las 08:00 (UTC−6) |
| **Ventana de atención activa** | De 08:00 a 22:00, todos los días, incluidos fines de semana |
| **Ventana de atención pasiva** | De 22:00 a 08:00. Solo se atienden alertas S1, que generan llamada telefónica |
| **Suplencia** | Cada turno tiene un suplente designado que asume si el titular no acusa recibo en 15 minutos ante una S1 |
| **Registro** | Ficha de turno en `evidencias/guardia/`, con titular, suplente, teléfono de contacto y alertas atendidas |

### 4.2 Qué revisa el turno, y con qué frecuencia

| Actividad | Frecuencia | Qué se busca |
|---|---|---|
| Revisión de alertas nuevas | Dos veces al día en ventana activa: al inicio y al cierre | Alertas en estado `nueva` sin triar |
| Revisión del tablero de métricas | Diaria | Desviación de las metas del triángulo |
| Verificación de la ingesta | Diaria | Que el marcador de ingesta avance. Una ingesta detenida deja el panel vacío sin mostrar error, y un panel vacío se confunde con ausencia de ataques |
| Revisión del registro del cortafuegos | Diaria | Peticiones **no** interrumpidas con reglas de ataque activadas, que es la señal de que un control falló |
| Revisión de falsos positivos | Semanal | Reglas que bloquean tráfico legítimo, para ajustar exclusiones acotadas |
| Informe de turno | Al relevo semanal | Resumen de alertas atendidas, incidentes abiertos y pendientes que se traspasan |

### 4.3 Traspaso de turno

El relevo no es automático. El turno saliente entrega al entrante un informe escrito con los
incidentes abiertos, su estado, las acciones pendientes con su responsable y cualquier regla ajustada
durante la semana. Un incidente abierto no puede cambiar de turno sin traspaso explícito acusado por
el entrante.

### 4.4 Limitación declarada

Un equipo de tres personas no sostiene una guardia de 24 horas los siete días de la semana con
atención activa permanente. Este documento no lo afirma: declara una ventana activa de 14 horas
diarias y una ventana pasiva con atención exclusiva a incidentes críticos por llamada telefónica.

La consecuencia se asume de forma consciente y se registra como riesgo residual en la matriz de
riesgos: un incidente S2 originado a las 23:00 puede permanecer sin triaje hasta las 08:00 del día
siguiente. La mitigación aplicada es que las reglas de correlación que producen alertas S1 generan
notificación telefónica en cualquier horario, de modo que lo que puede esperar espera y lo que no,
despierta a alguien.

## 5. Canales de comunicación

Los canales se emplean en orden de prioridad. Un canal se considera agotado tras dos intentos sin
acuse de recibo, momento en el cual se pasa al siguiente.

| Prioridad | Canal | Uso | Acuse de recibo |
|---|---|---|---|
| 1 | Llamada telefónica al número de guardia registrado en la ficha de turno | Toda S1 y todo escalamiento a nivel estratégico | Verbal, registrado en la bitácora con la hora |
| 2 | Grupo de mensajería instantánea del equipo, canal de guardia | S2 y coordinación operativa durante un incidente | Mensaje explícito de acuse; la marca de lectura no cuenta |
| 3 | Correo electrónico institucional | Informes escritos, resúmenes y comunicaciones formales | Respuesta escrita |
| 4 | Bitácora del incidente en el repositorio | Registro permanente de todo lo anterior | No aplica: es el registro, no el canal |

### 5.1 Lo que no se transmite por mensajería

Ninguna credencial, clave, secreto de segundo factor ni dato personal de un cliente se transmite por
mensajería instantánea durante un incidente, por urgente que sea. La información restringida que deba
compartirse se referencia por su ubicación, no por su valor: se indica dónde está, no cuál es.

### 5.2 Comunicación externa

Ninguna función distinta del Responsable de Comunicación emite información sobre un incidente hacia
fuera del equipo, y la emisión requiere aprobación previa del CISO. Esto incluye, de forma expresa,
las conversaciones informales y las publicaciones en redes sociales.

## 6. Autoridad de decisión por tipo de acción

La pregunta operativa durante un incidente no es quién manda, sino quién puede autorizar una acción
concreta sin consultar. Esta tabla la responde.

| Acción | Quién puede ordenarla sin consulta previa | Quién debe enterarse después |
|---|---|---|
| Bloquear una dirección de origen | AT | CR, en el resumen del turno |
| Cerrar las sesiones activas de una cuenta | AT | CR, de inmediato si la cuenta es privilegiada |
| Desactivar una cuenta de cliente | AT | CR |
| Desactivar una cuenta administrativa o de auditor | CR | CISO, de inmediato |
| Aislar el servidor de la red pública | CR | CISO y GO, de inmediato |
| Conmutar el proxy al servicio de respaldo estático | RP con aviso a CR | GO |
| Detener el servicio por completo | GO, a solicitud de CR | CISO y DG, de inmediato |
| Restaurar desde un respaldo | CR, previa verificación del respaldo por CE | CISO |
| Rotar la totalidad de los secretos | CR | CISO y RP |
| Declarar un incidente crítico | CISO; DG en su ausencia | Equipo completo |
| Comunicar a titulares de datos | CISO autoriza, RC emite | Equipo completo |
| Cerrar formalmente un incidente | CR para S2 a S4; CISO para S1 | Equipo completo |

## 7. Escalamiento por insuficiencia de información

Un escalamiento no requiere certeza. El Analista de Turno **debe** escalar cuando no pueda determinar
la severidad con la información disponible, y hacerlo no constituye un error de criterio: constituye
el uso correcto del procedimiento.

El error que esta matriz busca evitar no es el escalamiento innecesario, sino el silencio prudente.
Un incidente escalado de más cuesta una llamada; un incidente escalado de menos cuesta el incidente.

## 8. Resumen de una página

Esta tabla es la que debe imprimirse y tenerse a la vista durante la guardia.

| Si ocurre esto… | …es severidad | Llamar a | En | Por |
|---|---|---|---|---|
| Sesión administrativa desde origen desconocido | S1 | CR, después CISO | 15 y 30 min | Teléfono |
| Base de datos cifrada o nota de rescate | S1 | CR, después CISO | 15 y 30 min | Teléfono |
| Indicio de acceso a datos de clientes | S1 | CR, después CISO | 15 y 30 min | Teléfono |
| Servicio totalmente indisponible | S1 | CR, después CISO y GO | 15 y 30 min | Teléfono |
| Ataque de inyección **no** interrumpido por el cortafuegos | S1 | CR | 15 min | Teléfono |
| El panel dejó de ingerir eventos | S2 | CR | 1 hora | Mensajería |
| Degradación grave del servicio | S2 | CR | 1 hora | Mensajería |
| Extracción masiva del catálogo en curso | S2 | CR | 1 hora | Mensajería |
| Ráfaga de inyección bloqueada por el cortafuegos | S3 | CR | Siguiente turno | Informe |
| Fuerza bruta contra el segundo factor, contenida | S3 | CR | Siguiente turno | Informe |
| Rastreador falsificado detectado y bloqueado | S3 | CR | Siguiente turno | Informe |
| Petición hostil aislada, bloqueada | S4 | Nadie; registrar | Siguiente turno | Panel |

## 9. Control de versiones

| Versión | Fecha | Autor | Cambio |
|---|---|---|---|
| 1.0 | 2026-09-21 | Nivel táctico — Gerencia de Operaciones | Emisión inicial. Cierra las brechas de turno de guardia y de escalamiento señaladas en el anexo del triángulo de la ciberresiliencia |
