# Matriz de Riesgos de Seguridad de la Información

| | |
|---|---|
| **Identificador** | EVI-002 |
| **Versión** | 1.0 |
| **Fecha de corte** | 21 de septiembre de 2026 |
| **Elaborado por** | Nivel táctico — Arquitecto de Seguridad |
| **Aprobado por** | Nivel estratégico — Director de Seguridad de la Información (CISO) |
| **Clasificación** | Uso interno del proyecto |
| **Metodología** | Evaluación cualitativa de probabilidad e impacto sobre escala de 5 × 5 |
| **Próxima revisión** | 21 de marzo de 2027, o tras cualquier incidente de severidad crítica |

---

## 1. Propósito y método

Esta matriz identifica los riesgos que amenazan los activos de información de MarketGT, evalúa su
magnitud antes y después de los controles aplicados, y declara qué se hace con el riesgo que queda.

El activo que el proyecto protege son **los datos de los clientes**: credenciales, información personal
e información de pago. Todo lo demás —el servidor, la aplicación, el cortafuegos— es instrumento de esa
protección y entra en la matriz solo en la medida en que su compromiso afecte al activo central o a la
continuidad del servicio.

### 1.1 Escalas

**Probabilidad.** La estimación se ancla, cuando es posible, en las frecuencias observadas de la
infografía de vulnerabilidades web analizada en el curso. Anclar la probabilidad en un dato medido
—aunque sea un dato del sector y no propio— es preferible a estimarla por intuición, porque permite
discutir la estimación en lugar de aceptarla.

| Nivel | Valor | Criterio |
|---|---|---|
| Muy alta | 5 | Ocurre de forma continua. El registro del cortafuegos lo evidencia a diario |
| Alta | 4 | Frecuencia observada superior al 10 % de las vulnerabilidades web, o materialización esperada en el trimestre |
| Media | 3 | Frecuencia observada entre el 5 % y el 10 %, o materialización esperada en el año |
| Baja | 2 | Requiere condiciones específicas que no concurren de forma habitual |
| Muy baja | 1 | Exige un atacante dirigido con recursos apreciables |

**Impacto.** Se mide sobre la triple dimensión de confidencialidad, integridad y disponibilidad,
tomando la más severa de las tres.

| Nivel | Valor | Criterio |
|---|---|---|
| Crítico | 5 | Divulgación de datos personales de clientes, o indisponibilidad superior al objetivo de recuperación de 4 horas |
| Mayor | 4 | Compromiso de una cuenta privilegiada, o pérdida de datos dentro del objetivo de punto de recuperación de 24 horas |
| Moderado | 3 | Degradación del servicio, o pérdida de integridad recuperable |
| Menor | 2 | Afectación a un único usuario, reversible |
| Insignificante | 1 | Sin efecto sobre los activos |

**Riesgo** = probabilidad × impacto, sobre un máximo de 25.

| Rango | Nivel | Criterio de tratamiento |
|---|---|---|
| 20 a 25 | Extremo | Tratamiento inmediato. No se acepta |
| 12 a 19 | Alto | Tratamiento obligatorio con plazo comprometido |
| 6 a 11 | Medio | Tratamiento planificado o aceptación razonada por el nivel estratégico |
| 1 a 5 | Bajo | Aceptable con monitoreo |

### 1.2 Sobre el riesgo residual

El riesgo residual no se calcula restando un valor arbitrario por tener un control: se reestima la
probabilidad y el impacto **suponiendo el control en operación**. Un cortafuegos que bloquea el 99 % de
las inyecciones no reduce el impacto de la que pasa; reduce su probabilidad. Confundir ambas cosas
produce matrices que muestran riesgos bajos sobre sistemas frágiles.

---

## 2. Inventario de activos

| Código | Activo | Clasificación | Propietario | Por qué importa |
|---|---|---|---|---|
| ACT-01 | Datos personales de clientes | Confidencial | Nivel estratégico (CISO) | Es el activo que el proyecto declara proteger |
| ACT-02 | Credenciales de acceso y factores de autenticación | Restringido | Nivel estratégico (CISO) | Su compromiso da acceso a todo lo demás |
| ACT-03 | Información de pago tokenizada | Confidencial | Nivel estratégico (CISO) | Sujeta al estándar PCI DSS |
| ACT-04 | Pedidos y transacciones | Confidencial | Nivel táctico (Gerencia de Operaciones) | Su pérdida no se reconstruye: el cliente no repite el pedido |
| ACT-05 | Catálogo de productos | Público | Nivel táctico | Su integridad sostiene la confianza en el sitio |
| ACT-06 | Registros de auditoría y de eventos | Confidencial | Nivel operativo (rol auditor) | Sin ellos no hay detección ni evidencia |
| ACT-07 | Disponibilidad del servicio | — | Nivel estratégico | Compromiso declarado del modelo de negocio |
| ACT-08 | Reputación del dominio en motores de búsqueda | — | Nivel táctico | Activo intangible cuya pérdida se recupera en meses, no en horas |
| ACT-09 | Secretos de la plataforma: clave de aplicación, credenciales y llaves | Restringido | Nivel operativo (Responsable de Plataforma) | La clave de aplicación descifra los datos personales |

---

## 3. Matriz de riesgos

La tabla se presenta ordenada por riesgo inherente descendente. Las columnas P e I corresponden a
probabilidad e impacto.

### R-01 · Secuencias de comandos en sitios cruzados

| Campo | Contenido |
|---|---|
| **Activo** | ACT-01, ACT-02 |
| **Amenaza** | Ejecución de código en el navegador de otro usuario para robar su sesión o sus datos |
| **Vulnerabilidad** | Contenido generado por usuarios —reseñas y comentarios— insuficientemente saneado o escapado |
| **Probabilidad inherente** | 5 (muy alta). Es la categoría más frecuente de la infografía del curso, con el **14.69 %** |
| **Impacto** | 4 (mayor). Robo de sesión de un usuario; si la víctima es administradora, compromiso del panel |
| **Riesgo inherente** | **20 · Extremo** |
| **Controles aplicados** | Reglas 941 del Core Rule Set con paranoia 1 en bloqueo y umbral de anomalía 5; saneamiento del contenido generado por usuarios; escape de salida en las plantillas; cabecera de política de seguridad de contenido |
| **Evidencia** | `app/app/Services/Seo/SanitizadorUgc.php`; `app/app/Http/Middleware/CabecerasSeguridad.php`; `infra/scripts/demo-waf.sh` |
| **P residual / I residual** | 2 / 3 |
| **Riesgo residual** | **6 · Medio** |
| **Tratamiento** | **Mitigar.** Aceptado por el nivel estratégico con monitoreo. La detección de la variante que atraviese las reglas depende del registro de peticiones no interrumpidas, revisado a diario por el turno de guardia |

### R-02 · Explotación de un componente vulnerable

| Campo | Contenido |
|---|---|
| **Activo** | ACT-01, ACT-04, ACT-09 |
| **Amenaza** | Ejecución de código en el servidor mediante una vulnerabilidad publicada de una dependencia |
| **Vulnerabilidad** | Dependencias de la aplicación y del sistema operativo sin actualizar dentro de la ventana comprometida |
| **Probabilidad inherente** | 4 (alta). Segunda categoría de la infografía del curso, con el **12.36 %** |
| **Impacto** | 5 (crítico). Un compromiso del servidor alcanza los datos y los secretos |
| **Riesgo inherente** | **20 · Extremo** |
| **Controles aplicados** | Parcheo automático de seguridad del sistema operativo; ventana declarada de 72 horas para vulnerabilidades críticas; dependencias resueltas en la construcción de la imagen; red interna sin salida a Internet, que dificulta la descarga de una segunda etapa |
| **Evidencia** | `infra/scripts/02-hardening-servidor.sh` sección 1; `infra/docker/docker-compose.yml`, red `appnet` con `internal: true` |
| **P residual / I residual** | 3 / 4 |
| **Riesgo residual** | **12 · Alto** |
| **Tratamiento** | **Mitigar, con acción pendiente.** El escaneo automatizado de dependencias no está configurado y el directorio de flujos de trabajo está vacío. Es la acción correctiva de mayor prioridad de esta matriz, comprometida antes de la presentación |

### R-03 · Compromiso de credenciales por autenticación débil

| Campo | Contenido |
|---|---|
| **Activo** | ACT-02, ACT-01 |
| **Amenaza** | Acceso no autorizado mediante credenciales robadas, reutilizadas o adivinadas |
| **Vulnerabilidad** | Dependencia de un único factor; reutilización de contraseñas entre servicios; susceptibilidad del código temporal a la suplantación de sitio |
| **Probabilidad inherente** | 4 (alta). Tercera categoría de la infografía del curso, con el **9.25 %** |
| **Impacto** | 5 (crítico) si la cuenta es administrativa; 3 si es de cliente. Se toma el peor caso |
| **Riesgo inherente** | **20 · Extremo** |
| **Controles aplicados** | Segundo factor obligatorio en cuentas privilegiadas, con código temporal o credencial de clave pública resistente a la suplantación de sitio; códigos de recuperación de un solo uso; límite de intentos y bloqueo automático tras cinco fallos; reautenticación obligatoria antes de toda operación que debilite la cuenta; registro del último acceso visible para el propio titular |
| **Evidencia** | `app/routes/seguridad.php`; `app/config/fortify.php`; `app/tests/Feature/Auth/TwoFactorChallengeTest.php` |
| **P residual / I residual** | 2 / 4 |
| **Riesgo residual** | **8 · Medio** |
| **Tratamiento** | **Mitigar.** El residual no baja más porque el factor humano permanece: la capacitación que enseña a reconocer la suplantación de sitio **no se ha impartido**, y esa es la brecha que sostiene el residual en 8 |

### R-04 · Cifrado malicioso de datos

| Campo | Contenido |
|---|---|
| **Activo** | ACT-04, ACT-01, ACT-07 |
| **Amenaza** | Un tercero cifra la base de datos y exige rescate |
| **Vulnerabilidad** | Acceso administrativo comprometido; respaldo alcanzable con las mismas credenciales que el servidor |
| **Probabilidad inherente** | 3 (media) |
| **Impacto** | 5 (crítico). Con el esquema original de respaldo semanal, pérdida de hasta siete días de transacciones |
| **Riesgo inherente** | **15 · Alto** |
| **Controles aplicados** | Esquema 3-2-1 con copia externa en proveedor distinto y credencial sin permiso de borrado; volcado diario cifrado; objetivos de 24 y 4 horas; procedimiento PR-04 con orden de contención que preserva antes de restaurar; reconstrucción desde guiones en lugar de limpieza del sistema comprometido |
| **Evidencia** | `docs/03-politicas/politica-de-respaldo-y-recuperacion.md`; `docs/03-politicas/plan-de-respuesta-a-incidentes.md` sección 7.4 |
| **P residual / I residual** | 3 / 4 |
| **Riesgo residual** | **12 · Alto** |
| **Tratamiento** | **Mitigar, con acción pendiente.** El residual sigue siendo alto porque **los guiones de respaldo no existen todavía y ninguna restauración se ha probado**. Un respaldo no probado se comporta, a efectos de riesgo, como un respaldo ausente. El residual bajará a 6 cuando el acta `RES-2026-09` exista |

### R-05 · Inyección SQL

| Campo | Contenido |
|---|---|
| **Activo** | ACT-01, ACT-02, ACT-04 |
| **Amenaza** | Extracción o alteración de la base de datos mediante manipulación de consultas |
| **Vulnerabilidad** | Construcción de consultas por concatenación de entrada no validada |
| **Probabilidad inherente** | 3 (media). Cuarta categoría de la infografía del curso, con el **5.55 %** |
| **Impacto** | 5 (crítico). Acceso directo al activo central |
| **Riesgo inherente** | **15 · Alto** |
| **Controles aplicados** | Reglas 942 del Core Rule Set con detección por análisis léxico, donde un solo acierto crítico alcanza el umbral de anomalía 5 y produce respuesta 403; sentencias preparadas mediante el mapeador objeto-relacional; usuario de base de datos con privilegio mínimo; cifrado de los datos personales en reposo, que degrada el resultado de una extracción exitosa |
| **Evidencia** | `infra/scripts/demo-waf.sh` sección 1; migración de datos personales cifrados; `infra/docker/docker-compose.yml`, `ANOMALY_INBOUND: 5` |
| **P residual / I residual** | 1 / 4 |
| **Riesgo residual** | **4 · Bajo** |
| **Tratamiento** | **Mitigar.** Es el riesgo con mejor relación entre control e impacto: dos capas independientes —cortafuegos y sentencias preparadas— deben fallar a la vez, y aun entonces los datos personales salen cifrados |

### R-06 · Denegación de servicio

| Campo | Contenido |
|---|---|
| **Activo** | ACT-07 |
| **Amenaza** | Saturación del servicio hasta hacerlo indisponible |
| **Vulnerabilidad** | Ausencia de mitigación en el borde de la red; enlace único sin redundancia |
| **Probabilidad inherente** | 3 (media) |
| **Impacto** | 4 (mayor). Indisponibilidad que consume el presupuesto de varios meses del compromiso declarado |
| **Riesgo inherente** | **12 · Alto** |
| **Controles aplicados** | Bloqueo automático tras cinco respuestas 403 desde una misma dirección en diez minutos; limitadores de tasa en la aplicación; parámetros del núcleo frente a inundación de conexiones; servicio de respaldo estático que mantiene el cortafuegos en pie si la aplicación cae |
| **Evidencia** | `infra/scripts/02-hardening-servidor.sh`; `infra/docker/docker-compose.yml`, servicio `respaldo` |
| **P residual / I residual** | 3 / 3 |
| **Riesgo residual** | **9 · Medio** |
| **Tratamiento** | **Aceptar de forma razonada.** La capa de perímetro está implementada de forma parcial conforme a DA-001 y **no existe mitigación antes del origen**: ante un ataque distribuido de volumen apreciable la respuesta realista es restaurar, no defender. Se acepta porque el entorno es de demostración académica, no sostiene operación comercial y no custodia datos reales |

### R-07 · Fuga de datos personales

| Campo | Contenido |
|---|---|
| **Activo** | ACT-01, ACT-03 |
| **Amenaza** | Divulgación no autorizada de información de clientes |
| **Vulnerabilidad** | Ruta de la aplicación que expone más datos de los necesarios; control de acceso roto; error de configuración |
| **Probabilidad inherente** | 3 (media) |
| **Impacto** | 5 (crítico). Activa la obligación de comunicación a titulares |
| **Riesgo inherente** | **15 · Alto** |
| **Controles aplicados** | Cifrado de dirección, teléfono y documento de identidad en reposo; control de acceso por rol verificado en middleware; inspección del cuerpo de respuesta habilitada para las reglas de la familia 95x; registro de todo acceso en la bitácora de auditoría; procedimiento PR-05 con determinación previa de si los datos estaban cifrados |
| **Evidencia** | Migración de datos personales cifrados; `app/app/Http/Middleware/VerificarRol.php`; `infra/docker/docker-compose.yml`, `MODSEC_RESP_BODY_ACCESS: On` |
| **P residual / I residual** | 2 / 3 |
| **Riesgo residual** | **6 · Medio** |
| **Tratamiento** | **Mitigar.** El impacto residual baja de 5 a 3 por una razón concreta y verificable: los campos personales salen cifrados, de modo que su sustracción sin la clave de la aplicación no constituye divulgación efectiva. Esa misma razón convierte a ACT-09 en el activo crítico y motiva R-08 |

### R-08 · Exposición de la clave de cifrado de la aplicación

| Campo | Contenido |
|---|---|
| **Activo** | ACT-09, y a través de él ACT-01 |
| **Amenaza** | Obtención de la clave que descifra los datos personales |
| **Vulnerabilidad** | Secreto incorporado al repositorio por error; archivo de entorno legible; clave del respaldo custodiada junto al respaldo |
| **Probabilidad inherente** | 3 (media). Es un error humano frecuente, no un ataque sofisticado |
| **Impacto** | 5 (crítico). Anula por completo la mitigación de R-07 |
| **Riesgo inherente** | **15 · Alto** |
| **Controles aplicados** | Archivo de entorno excluido del control de versiones, con plantilla de ejemplo en su lugar; clave del respaldo custodiada fuera del servidor; prohibición expresa de transmitir secretos por mensajería, incluso durante un incidente |
| **Evidencia** | `infra/docker/.env.example`; `.gitignore`; POL-003 sección 5.1; POL-004 sección 5.3 |
| **P residual / I residual** | 2 / 5 |
| **Riesgo residual** | **10 · Medio** |
| **Tratamiento** | **Mitigar, con acción pendiente.** El impacto residual permanece en 5 porque es irreductible: la clave descifra los datos y no hay control que atenúe esa consecuencia. La probabilidad bajaría a 1 con escaneo automatizado de secretos en el repositorio, que **no está configurado** |

### R-09 · Carga de archivo malicioso

| Campo | Contenido |
|---|---|
| **Activo** | ACT-05, ACT-07 |
| **Amenaza** | Subida de un archivo malicioso disfrazado de imagen de producto |
| **Vulnerabilidad** | **Ausencia de análisis de contenido de los archivos subidos.** El control A.8.7 está declarado como vacío |
| **Probabilidad inherente** | 2 (baja). Requiere una cuenta con permiso de carga |
| **Impacto** | 4 (mayor) |
| **Riesgo inherente** | **8 · Medio** |
| **Controles aplicados** | Límite de tamaño del cuerpo con rechazo del excedente; validación de tipo declarado; los archivos no se sirven desde una ruta que el intérprete de PHP procese |
| **Evidencia** | `infra/docker/docker-compose.yml`, `MODSEC_REQ_BODY_LIMIT` y `MODSEC_REQ_BODY_LIMIT_ACTION: Reject` |
| **P residual / I residual** | 2 / 3 |
| **Riesgo residual** | **6 · Medio** |
| **Tratamiento** | **Aceptar de forma razonada.** Se acepta el vacío del control A.8.7 para el entorno de demostración, en atención a que la carga exige cuenta autenticada con rol administrativo y a que los archivos no se ejecutan. En un entorno productivo este riesgo exigiría tratamiento |

### R-10 · Envenenamiento de posicionamiento

| Campo | Contenido |
|---|---|
| **Activo** | ACT-08, ACT-05 |
| **Amenaza** | Apropiación de la reputación del dominio mediante contenido inyectado, encubrimiento, redirección abierta o extracción masiva |
| **Vulnerabilidad** | Contenido generado por usuarios indexable; parámetro de redirección sin restricción de destino; catálogo accesible sin límite de cadencia |
| **Probabilidad inherente** | 4 (alta). Es un ataque de bajo costo y alto retorno para el atacante |
| **Impacto** | 3 (moderado). No compromete datos, pero su recuperación se mide en semanas porque depende de la reindexación del motor de búsqueda |
| **Riesgo inherente** | **12 · Alto** |
| **Controles aplicados** | Veinticinco reglas propias en el espacio 15000-15121; verificación de rastreadores contra los rangos publicados; detector de contenido no solicitado; redirección restringida a destinos permitidos; línea base de contenido indexable con vigilancia de integridad |
| **Evidencia** | `infra/modsecurity/REQUEST-945-MARKETGT-SEO.conf`; `infra/modsecurity/REQUEST-946-MARKETGT-ANTISCRAPING.conf`; `app/app/Console/Commands/VigilarIntegridadSeo.php`; `infra/scripts/demo-seo.sh` |
| **P residual / I residual** | 2 / 2 |
| **Riesgo residual** | **4 · Bajo** |
| **Tratamiento** | **Mitigar.** Es el riesgo donde el proyecto aporta controles propios más allá del conjunto de reglas genérico |

### R-11 · Alteración o pérdida de los registros de auditoría

| Campo | Contenido |
|---|---|
| **Activo** | ACT-06 |
| **Amenaza** | Un atacante borra o modifica su rastro; o los registros se pierden por rotación no prevista |
| **Vulnerabilidad** | Registros alcanzables desde el sistema comprometido; ingesta que no contempla la rotación del archivo |
| **Probabilidad inherente** | 3 (media) |
| **Impacto** | 4 (mayor). Sin registros no hay detección, ni investigación, ni evidencia |
| **Riesgo inherente** | **12 · Alto** |
| **Controles aplicados** | Registro del cortafuegos generado en contenedor distinto y montado en la aplicación en modo de solo lectura; duplicación deliberada entre archivo y tabla consultable; ingesta incremental que detecta la rotación por reducción del archivo; sellado por resumen SHA-256 del archivo frío; sincronización horaria |
| **Evidencia** | `infra/docker/docker-compose.yml`, montaje de solo lectura; `app/config/siem.php`; `app/app/Models/RegistroAuditoria.php` |
| **P residual / I residual** | 2 / 3 |
| **Riesgo residual** | **6 · Medio** |
| **Tratamiento** | **Mitigar.** El residual reconoce una limitación declarada en POL-005 sección 6.2: un atacante con credenciales administrativas del motor de base de datos sí puede alterar la tabla. La protección es de la aplicación, no del motor |

### R-12 · Detección tardía por ausencia de operación de guardia

| Campo | Contenido |
|---|---|
| **Activo** | Transversal a todos |
| **Amenaza** | Un incidente permanece sin advertir durante horas porque nadie revisa las alertas |
| **Vulnerabilidad** | Equipo de tres personas sin capacidad de guardia activa permanente |
| **Probabilidad inherente** | 4 (alta). Era la brecha señalada en el anexo del triángulo: herramientas desplegadas sin operación definida |
| **Impacto** | 4 (mayor). Multiplica el impacto de cualquier otro riesgo de esta matriz |
| **Riesgo inherente** | **16 · Alto** |
| **Controles aplicados** | Turno de guardia con rotación semanal, titular y suplente; ventana activa de 14 horas diarias; alertas de severidad crítica con notificación telefónica en cualquier horario; revisión diaria del marcador de ingesta, porque una ingesta detenida deja el panel vacío sin mostrar error |
| **Evidencia** | `docs/03-politicas/matriz-de-escalamiento.md` sección 4 |
| **P residual / I residual** | 3 / 3 |
| **Riesgo residual** | **9 · Medio** |
| **Tratamiento** | **Aceptar de forma razonada.** La limitación es estructural: tres personas no sostienen guardia activa permanente. Se acepta que un incidente de severidad alta originado a las 23:00 pueda esperar hasta las 08:00, y se mitiga que lo crítico despierte a alguien por teléfono |

### R-13 · Error humano del propio equipo

| Campo | Contenido |
|---|---|
| **Activo** | Transversal a todos |
| **Amenaza** | Un integrante desactiva un control, expone un secreto o entrega su credencial ante una suplantación |
| **Vulnerabilidad** | **Ausencia de capacitación impartida.** El control A.6.3 está definido pero no ejecutado |
| **Probabilidad inherente** | 4 (alta). El propio modelo del triángulo identifica el error humano como una de las dos causas por las que la prevención falla |
| **Impacto** | 4 (mayor). El error humano atraviesa las seis capas, no se detiene en ninguna |
| **Riesgo inherente** | **16 · Alto** |
| **Controles aplicados** | Prohibiciones expresas en la política de uso aceptable; reautenticación antes de operaciones que debiliten una cuenta; regla de que la capacitación precede al acceso; plan de seis módulos con evaluación y ejercicio práctico |
| **Evidencia** | POL-001 sección 7; `docs/03-politicas/plan-de-concienciacion-y-capacitacion.md` |
| **P residual / I residual** | 4 / 3 |
| **Riesgo residual** | **12 · Alto** |
| **Tratamiento** | **Mitigar, con acción pendiente.** La probabilidad residual **no baja** porque el control que la reduciría no se ha ejecutado: ninguna capacitación se ha impartido y la métrica de personal capacitado es del 0 %. Es, junto con R-04, el riesgo residual más alto de la matriz, y su corrección no cuesta dinero: cuesta cuatro horas |

---

## 4. Resumen

| Riesgo | Descripción | Inherente | Residual | Variación | Tratamiento |
|---|---|---|---|---|---|
| R-01 | Secuencias de comandos en sitios cruzados | 20 Extremo | 6 Medio | −14 | Mitigar |
| R-02 | Componente vulnerable | 20 Extremo | **12 Alto** | −8 | Mitigar, pendiente |
| R-03 | Autenticación débil | 20 Extremo | 8 Medio | −12 | Mitigar |
| R-04 | Cifrado malicioso de datos | 15 Alto | **12 Alto** | −3 | Mitigar, pendiente |
| R-05 | Inyección SQL | 15 Alto | 4 Bajo | −11 | Mitigar |
| R-06 | Denegación de servicio | 12 Alto | 9 Medio | −3 | Aceptar razonadamente |
| R-07 | Fuga de datos personales | 15 Alto | 6 Medio | −9 | Mitigar |
| R-08 | Exposición de la clave de cifrado | 15 Alto | 10 Medio | −5 | Mitigar, pendiente |
| R-09 | Carga de archivo malicioso | 8 Medio | 6 Medio | −2 | Aceptar razonadamente |
| R-10 | Envenenamiento de posicionamiento | 12 Alto | 4 Bajo | −8 | Mitigar |
| R-11 | Alteración de registros | 12 Alto | 6 Medio | −6 | Mitigar |
| R-12 | Detección tardía por guardia limitada | 16 Alto | 9 Medio | −7 | Aceptar razonadamente |
| R-13 | Error humano del equipo | 16 Alto | **12 Alto** | −4 | Mitigar, pendiente |

### 4.1 Distribución

| Nivel | Inherente | Residual |
|---|---|---|
| Extremo (20-25) | 3 | 0 |
| Alto (12-19) | 9 | 3 |
| Medio (6-11) | 1 | 8 |
| Bajo (1-5) | 0 | 2 |
| **Total** | **13** | **13** |

Ningún riesgo permanece en nivel extremo. **Tres permanecen en nivel alto**, y los tres comparten la
misma causa: el control que los reduciría está definido pero no ejecutado.

## 5. Los tres riesgos que siguen altos

Esta sección existe porque es la conclusión operativa de toda la matriz.

| Riesgo | Por qué sigue alto | Qué lo bajaría | Costo | Plazo comprometido |
|---|---|---|---|---|
| **R-04** · Cifrado malicioso | Los guiones de respaldo no existen y ninguna restauración se ha probado. Un respaldo no probado se comporta como un respaldo ausente | Implementar el respaldo diario cifrado y levantar el acta `RES-2026-09` | Q20.00 mensuales, absorbidos por la reserva de contingencia | Antes de la presentación |
| **R-13** · Error humano | Ninguna capacitación se ha impartido. La métrica de personal capacitado es del 0 % frente a su meta del 100 % | Impartir la primera edición de los seis módulos y ejecutar el simulacro de mesa | **Q0.00.** Cuesta cuatro horas | Antes de la presentación |
| **R-02** · Componente vulnerable | El escaneo automatizado de dependencias no está configurado; el directorio de flujos de trabajo está vacío | Configurar la auditoría de dependencias y el escaneo de secretos | **Q0.00** | Antes de la presentación |

Dos de las tres correcciones no tienen costo económico alguno, y la tercera está presupuestada. Esto
merece decirse con claridad: **el riesgo residual alto que subsiste en este proyecto no obedece a una
limitación de recursos, sino a actividades pendientes de ejecución.** Es exactamente el tipo de
conclusión que una matriz de riesgos debe producir, y la razón por la que se elabora antes de la
presentación y no después.

## 6. Criterio de aceptación del riesgo

El nivel estratégico acepta los riesgos residuales de nivel medio y bajo con monitoreo. Los tres
riesgos residuales de nivel alto **no se aceptan**: quedan con tratamiento comprometido y plazo
declarado.

Los tres riesgos con tratamiento «aceptar de forma razonada» —R-06, R-09 y R-12— se aceptan en
atención a una circunstancia que debe desaparecer si el proyecto evoluciona hacia operación real: el
entorno publicado es de demostración académica, no sostiene operación comercial y no custodia datos
reales de personas. En un entorno productivo, los tres exigirían tratamiento.

## 7. Control de versiones

| Versión | Fecha | Autor | Cambio |
|---|---|---|---|
| 1.0 | 2026-09-21 | Nivel táctico — Arquitecto de Seguridad | Emisión inicial. Trece riesgos evaluados con probabilidad anclada en las frecuencias de la infografía del curso |
</content>
