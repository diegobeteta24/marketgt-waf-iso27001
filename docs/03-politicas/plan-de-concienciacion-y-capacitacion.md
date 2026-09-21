# Plan de Concienciación y Capacitación en Seguridad

| | |
|---|---|
| **Identificador** | POL-006 |
| **Versión** | 1.0 |
| **Fecha de emisión** | 21 de septiembre de 2026 |
| **Elaborado por** | Nivel táctico — Arquitecto de Seguridad |
| **Aprobado por** | Nivel estratégico — Director de Seguridad de la Información (CISO) |
| **Clasificación** | Uso interno del proyecto |
| **Control del Anexo A** | A.6.3 · Concienciación, educación y capacitación en seguridad de la información |
| **Próxima revisión** | 21 de marzo de 2027 |

---

## 1. Por qué este plan existe

El anexo del triángulo de la ciberresiliencia detectó una omisión que resultaba llamativa por su
origen: **el propio modelo del triángulo incluye la formación continua del personal dentro del vértice
de protección**, y la propuesta del proyecto no la documentaba. El modelo identifica el error humano
como una de las dos causas por las que la prevención termina fallando —la otra son las
vulnerabilidades de día cero— y aun así el documento vigente no contemplaba ninguna actividad
formativa.

La omisión tiene además consecuencia normativa. El control A.6.3 del Anexo A de la norma ISO/IEC
27001:2022 exige que el personal reciba concienciación, educación y capacitación apropiadas y
actualizaciones periódicas de las políticas que le conciernen. Un sistema de gestión sin este control
no supera una revisión, por sólidos que sean sus controles técnicos.

Existe una razón operativa que pesa más que las dos anteriores. Las seis capas del esquema de defensa
en profundidad protegen frente a peticiones hostiles; ninguna de ellas protege frente a un integrante
del equipo que entrega su contraseña en un sitio falso, que sube un secreto al repositorio o que
desactiva el motor de reglas del cortafuegos para depurar un fallo funcional y olvida volver a
activarlo. El eslabón humano no está detrás de las capas: las atraviesa.

## 2. Alcance y destinatarios

El plan alcanza a los tres integrantes del equipo, en la totalidad de sus funciones estratégicas,
tácticas y operativas. No existen personas exentas: el nivel estratégico es, de hecho, el objetivo
preferente de los ataques de suplantación dirigida, porque concentra la autoridad de decisión.

| Destinatario | Contenido obligatorio | Contenido adicional |
|---|---|---|
| Nivel estratégico | Módulos 1, 2 y 6 | Criterio de decisión ante un incidente crítico y contenido de la comunicación a titulares |
| Nivel táctico | Módulos 1 a 6 | Ajuste de reglas y de exclusiones del cortafuegos; interpretación de la matriz de riesgos |
| Nivel operativo | Módulos 1 a 6 | Operación del turno de guardia y triaje de alertas |

## 3. Periodicidad

| Actividad | Frecuencia | Duración | Responsable |
|---|---|---|---|
| **Capacitación formal completa** | **Semestral** | 4 horas | Arquitecto de Seguridad |
| Inducción para nueva incorporación | Antes de otorgar cualquier acceso | 2 horas | Arquitecto de Seguridad |
| Actualización tras un incidente S1 o S2 | Dentro de los 10 días hábiles del cierre | 30 minutos | Coordinador de Respuesta |
| Simulacro de mesa de respuesta a incidentes | Semestral | 2 horas | Coordinador de Respuesta |
| Recordatorio breve de una práctica concreta | Mensual | 10 minutos | Analista de Turno saliente |

La meta declarada en el anexo del proyecto es del **100 % del equipo capacitado en el semestre
vigente**, y el panel de monitoreo la contrasta como métrica del vértice de protección. Un integrante
sin capacitación vigente cuenta como incumplimiento de la meta, no como caso pendiente.

La regla de inducción no admite excepción: **la capacitación precede al acceso**. Entregar credenciales
administrativas a alguien que todavía no sabe qué está prohibido hacer con ellas invierte el orden de
los controles.

## 4. Contenido del programa

El material se construye sobre recursos de la Fundación OWASP, conforme a la acción correctiva
acordada en el anexo del proyecto. La elección obedece a tres razones: son gratuitos, lo que preserva
la restricción de costo cero; son los mismos que fundamentan el catálogo de ataques que el cortafuegos
detecta, de modo que la formación y el control técnico hablan del mismo riesgo; y están
públicamente disponibles, lo que permite que la evidencia de capacitación cite una fuente verificable.

### 4.1 Módulo 1 · Los riesgos que enfrenta esta plataforma

Se parte de las cifras de la infografía analizada en el curso, porque discutir riesgos con números
propios funciona mejor que discutirlos en abstracto.

| Riesgo | Frecuencia | Qué lo contiene aquí | Qué depende de la persona |
|---|---|---|---|
| Secuencias de comandos en sitios cruzados | 14.69 % | Reglas 941 del conjunto de reglas y saneamiento del contenido generado por usuarios | Escapar toda salida; no confiar en la validación del navegador |
| Componentes vulnerables | 12.36 % | Auditoría de dependencias y parcheo automático | No introducir dependencias sin revisar; atender la ventana de 72 horas |
| Autenticación débil | 9.25 % | Segundo factor, límite de intentos y bloqueo automático | No reutilizar contraseñas; custodiar los códigos de recuperación fuera del autenticador |
| Inyección SQL | 5.55 % | Reglas 942 del conjunto de reglas y sentencias preparadas | No construir consultas por concatenación, nunca, ni en una prueba |

**Material de apoyo:** OWASP Top 10:2025 y las fichas de la serie *Cheat Sheet* correspondientes a
cada categoría.

### 4.2 Módulo 2 · Autenticación, segundo factor y suplantación de sitio

Por qué una contraseña no basta; qué protege el código temporal y qué no; por qué la credencial de
clave pública resiste la suplantación de sitio mientras el código temporal no lo hace, y cuál es el
mecanismo exacto de esa diferencia: el código se escribe y puede retransmitirse a un sitio impostor,
mientras que la credencial queda vinculada criptográficamente al nombre del sitio legítimo.

Se practica el caso concreto: un correo que solicita el código temporal «para verificar la cuenta». El
ejercicio consiste en identificar las tres señales que lo delatan y en saber a quién notificar, que es
lo que la matriz de escalamiento responde.

**Material de apoyo:** NIST SP 800-63B-4 en sus apartados sobre resistencia a la suplantación, y la
ficha de OWASP sobre autenticación multifactor.

### 4.3 Módulo 3 · Codificación segura

Validación en el servidor con independencia de la validación en el navegador; sentencias preparadas
sin excepción; saneamiento de entrada y escape de salida; gestión de secretos fuera del repositorio;
y la regla operativa que más se incumple bajo presión: **un falso positivo del cortafuegos no se
resuelve desactivando el motor de reglas**, sino con una exclusión acotada a la regla y a la ruta,
documentada y con su identificador.

Se revisa código real del proyecto, incluido el que se hizo bien y el que se corrigió. Revisar los
aciertos propios enseña menos que revisar las correcciones propias.

**Material de apoyo:** OWASP Proactive Controls y las fichas de prevención de inyección SQL y de
secuencias de comandos en sitios cruzados.

### 4.4 Módulo 4 · Clasificación y manejo de la información

Los cuatro niveles definidos en POL-001 y qué implica cada uno en la práctica diaria. Qué nunca se
transmite por mensajería instantánea, ni siquiera durante un incidente. Por qué el material de la
defensa del proyecto emplea identidades de fantasía. Qué información nunca debe aparecer en un
registro, y el caso incómodo del cuerpo de una petición de autenticación bloqueada, que puede contener
una contraseña en claro y obliga a rotarla.

**Material de apoyo:** POL-001, sección 6, y POL-005, sección 5.

### 4.5 Módulo 5 · Qué hacer cuando algo pasa

Cómo se reconoce un incidente; cuál es la diferencia entre evento, alerta e incidente; cómo se ejecuta
el triaje; a quién se llama, en cuánto tiempo y por qué medio; y por qué la contención de primer nivel
no espera autorización.

Se insiste en dos reglas que el plan de respuesta establece y que la conducta natural contradice: que
escalar por insuficiencia de información es el uso correcto del procedimiento y no un error de
criterio, y que la revisión posterior no busca culpables, porque un equipo que teme la revisión oculta
información y un plan que se alimenta de información oculta no funciona.

**Material de apoyo:** POL-002 y POL-003, con el resumen de una página de esta última.

### 4.6 Módulo 6 · Novedades del semestre

Incidentes ocurridos y sus lecciones aprendidas; cambios en las políticas; reglas del cortafuegos
añadidas o ajustadas; resultados de la última prueba de restauración; y estado de las métricas del
triángulo frente a sus metas.

Este módulo es el que convierte el plan en un ciclo. Sin él, la capacitación repite cada semestre el
mismo contenido y el equipo deja de prestarle atención al tercer semestre.

## 5. Simulacro de mesa

El simulacro no forma parte de la capacitación formal: es el ejercicio que verifica si la capacitación
sirvió. El modelo del triángulo de la ciberresiliencia lo exige de forma expresa al pedir
procedimientos ensayados y no solamente documentados.

| Aspecto | Definición |
|---|---|
| Frecuencia | Semestral, y uno obligatorio antes de la puesta en producción |
| Duración | 2 horas |
| Formato | Recorrido verbal de un escenario completo, con las funciones repartidas y cronometraje de las decisiones |
| Escenario | Distinto en cada edición. El primero corresponde a un cifrado malicioso de datos, por ser el que verifica simultáneamente la respuesta y la recuperación |
| Regla | Se emplea la documentación tal como está escrita. Si un procedimiento no se entiende durante el simulacro, la deficiencia es del procedimiento y se corrige |
| Evidencia | Acta con el escenario, los participantes por función, los tiempos alcanzados frente a los objetivos y las deficiencias detectadas con su responsable de corrección |

El simulacro previsto antes de la puesta en producción corresponde a la ampliación de la fase 6 del
cronograma acordada en el anexo del proyecto, que se incorpora sin modificar la duración total de 16
semanas.

## 6. Registro de asistencia

Cada actividad produce un registro que se archiva en `evidencias/capacitacion/` y que constituye la
evidencia del control A.6.3 ante una auditoría.

| Campo | Contenido |
|---|---|
| Identificador | `CAP-AAAA-SN`, donde `S1` o `S2` indica el semestre |
| Fecha y duración | — |
| Modalidad | Presencial o remota |
| Instructor | Función, no nombre de persona |
| Módulos impartidos | Con su numeración |
| Material empleado | Con la referencia concreta del recurso de la Fundación OWASP |
| Asistentes | Función y confirmación de asistencia de cada uno |
| Resultado de la evaluación | Puntuación por asistente |
| Observaciones | Dudas recurrentes y temas que exigen refuerzo |

Un registro de asistencia sin resultado de evaluación acredita presencia, no capacitación. La
distinción importa porque el control A.6.3 exige lo segundo.

## 7. Evaluación

### 7.1 Formato

Al término de cada capacitación formal se aplica una evaluación de veinte preguntas, distribuidas
entre los seis módulos, con predominio de las situaciones prácticas sobre las definiciones. La
pregunta tipo no es «qué es la inyección SQL», sino «el cortafuegos registró esta petición y el campo
de interrupción vale falso: qué severidad corresponde y a quién se llama».

El umbral de aprobación es del 80 %. Quien no lo alcance repite la evaluación tras un refuerzo
dirigido a los módulos deficientes, dentro de los quince días siguientes.

### 7.2 Ejercicio práctico

La evaluación incluye un componente que se ejecuta y no se responde. Cada integrante debe demostrar,
en el sistema real:

1. Localizar en el registro del cortafuegos un evento a partir de su identificador único de
   transacción y determinar si la petición fue interrumpida.
2. Identificar en el panel una alerta en estado `nueva`, ejecutar su triaje y dejarla con su estado
   final correctamente asignado.
3. Registrar una credencial de clave pública en su propia cuenta y utilizarla para iniciar sesión.
4. Indicar, ante un escenario dado, qué severidad corresponde y a qué función se notifica, en qué
   plazo y por qué medio.

Un integrante que no logra completar estos cuatro ejercicios no puede asumir el turno de guardia. La
consecuencia es operativa y no disciplinaria: quien no sabe leer el registro no puede monitorearlo.

### 7.3 Medición de la eficacia

La norma exige evaluar la eficacia de la formación, no solo su ejecución. Además de la puntuación, se
siguen tres indicadores a lo largo del semestre:

| Indicador | Qué revela | Meta |
|---|---|---|
| Alertas triadas dentro del plazo de POL-003 | Si el procedimiento se aplica o solo se conoce | Igual o superior al 90 % |
| Falsos positivos resueltos con exclusión acotada, frente a los resueltos desactivando reglas | Si la regla de codificación segura se respeta bajo presión | 100 % con exclusión acotada |
| Secretos detectados en el repositorio por el escaneo automatizado | Si la regla de gestión de secretos se cumple | Cero |

Un indicador que empeora tras la capacitación no significa que el equipo aprendió menos: con
frecuencia significa que aprendió a detectar lo que antes pasaba inadvertido. La interpretación de
estos indicadores forma parte del módulo 6 del semestre siguiente.

## 8. Estado al 21 de septiembre de 2026

**No se ha ejecutado ninguna capacitación formal ni ningún simulacro.** La primera edición está
programada para la semana previa a la presentación del proyecto, junto con el simulacro obligatorio de
puesta en producción.

En consecuencia, el control A.6.3 se declara **definido pero no ejecutado**, y la métrica de personal
capacitado se reporta como 0 % frente a su meta del 100 %. Así consta en la matriz de controles y así
debe presentarse, porque el anexo del triángulo reprochaba exactamente esto: declarar como resuelto
aquello que solo está escrito.

## 9. Control de versiones

| Versión | Fecha | Autor | Cambio |
|---|---|---|---|
| 1.0 | 2026-09-21 | Nivel táctico — Arquitecto de Seguridad | Emisión inicial. Cierra la brecha de formación continua señalada en el anexo del triángulo de la ciberresiliencia |

---

## Referencias

OWASP Foundation. (2025). *OWASP Top 10:2025.* https://owasp.org/Top10/2025/

OWASP Foundation. (2026). *OWASP Cheat Sheet Series.* https://cheatsheetseries.owasp.org/

OWASP Foundation. (2026). *OWASP Proactive Controls.* https://top10proactive.owasp.org/

National Institute of Standards and Technology. (2025). *Digital identity guidelines: Authentication
and authenticator management* (NIST SP 800-63B-4).

Organización Internacional de Normalización. (2022). *ISO/IEC 27002:2022. Controles de seguridad de la
información.*
