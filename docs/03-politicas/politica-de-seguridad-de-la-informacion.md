# Política de Seguridad de la Información

| | |
|---|---|
| **Identificador** | POL-001 |
| **Versión** | 1.0 |
| **Fecha de emisión** | 21 de septiembre de 2026 |
| **Elaborado por** | Nivel táctico — Arquitecto de Seguridad |
| **Aprobado por** | Nivel estratégico — Dirección General y Director de Seguridad de la Información (CISO) |
| **Clasificación** | Uso interno del proyecto |
| **Ámbito** | Plataforma MarketGT y la totalidad de su infraestructura de soporte |
| **Próxima revisión** | 21 de marzo de 2027, o antes si se produce un cambio material |

---

## 1. Propósito

Esta política establece la voluntad de la dirección de MarketGT respecto de la protección de la
información que la plataforma custodia, y fija las reglas que hacen que los controles técnicos ya
desplegados formen un sistema de gestión y no un inventario de herramientas.

La distinción no es retórica. La norma ISO/IEC 27001:2022 no certifica cortafuegos ni motores de
detección: certifica que la organización decide, documenta, ejecuta y revisa. Un servidor endurecido
sin una política que declare quién responde por él, qué información protege y con qué criterio se
resuelven los conflictos es un servidor endurecido, no un sistema de gestión de la seguridad de la
información.

## 2. Alcance

### 2.1 Lo que queda dentro

La política se aplica a la totalidad de los activos de información de la plataforma MarketGT, a las
seis capas del esquema de defensa en profundidad definido en la propuesta del proyecto y a las tres
personas que integran el equipo, con independencia del nivel organizacional al que correspondan sus
funciones.

| Ámbito | Contenido |
|---|---|
| Información | Datos personales de clientes, credenciales de acceso, información de pago tokenizada, catálogo de productos, pedidos, registros de auditoría y registros de eventos de seguridad |
| Sistemas | Aplicación Laravel, base de datos MariaDB, servidor web Nginx con ModSecurity, panel de monitoreo, conjunto de contenedores y máquina virtual anfitriona |
| Procesos | Desarrollo, despliegue, monitoreo, respuesta a incidentes, respaldo, recuperación y auditoría |
| Personas | Los tres integrantes del equipo en sus funciones estratégicas, tácticas y operativas |
| Proveedores | Google Cloud (cómputo), DuckDNS (resolución de nombres), Let's Encrypt (certificados) y GitHub (repositorio y automatización) |

### 2.2 Lo que queda fuera, y por qué

El entorno publicado es un entorno de demostración académica. No sostiene operación comercial, no
procesa transacciones con valor económico real y no custodia datos personales de personas reales: los
registros de clientes corresponden a identidades de fantasía generadas para la demostración.

Esta circunstancia no relaja los controles —se implementan como si el entorno fuera productivo, porque
ese es el objeto del ejercicio— pero sí acota tres obligaciones que una organización real tendría y
que aquí no aplican: la contratación de seguros de ciberriesgo, la verificación de antecedentes del
personal conforme al control A.6.1, y la seguridad física del centro de datos conforme a los controles
de la familia A.7, que recaen íntegramente sobre el proveedor de cómputo.

Los controles que quedan fuera del alcance se declaran uno a uno, con su justificación, en
`docs/04-evidencias/matriz-de-controles-iso27001.md`. Un control excluido sin justificación escrita es
un hallazgo de auditoría; un control excluido con justificación razonada es una decisión de gestión.

## 3. Marco normativo

| Norma o estándar | Papel en esta política |
|---|---|
| ISO/IEC 27001:2022 | Marco rector. Define los requisitos del sistema de gestión y el Anexo A de 93 controles de referencia |
| ISO/IEC 27002:2022 | Guía de implementación de los controles del Anexo A |
| NIST SP 800-61r3 | Estructura del proceso de respuesta a incidentes, desarrollado en POL-002 |
| NIST SP 800-63B-4 | Requisitos de los autenticadores y del segundo factor |
| OWASP Top 10:2025 | Catálogo de riesgos de aplicación que los controles deben atender |
| OWASP Core Rule Set 4.29 | Conjunto de reglas de detección cargado en el cortafuegos de aplicación |
| PCI DSS v4.0, requisito 6.4.2 | Obligación de desplegar una solución técnica automatizada ante aplicaciones web públicas que procesen datos de tarjetas |
| CIS Benchmark for Ubuntu Linux | Línea base de endurecimiento del sistema operativo |

## 4. Principios rectores

**Defensa en profundidad.** Ninguna capa concentra por sí sola la seguridad del sistema. El diseño
asume que cualquier control puede fallar y exige que el fallo de uno no comprometa el conjunto.

**Privilegio mínimo.** Toda cuenta, proceso y servicio opera con los permisos estrictamente necesarios
para su función. El usuario de la base de datos no es administrador; el contenedor de la aplicación
monta el registro del cortafuegos en modo de solo lectura; la red interna no alcanza Internet.

**Verificabilidad.** Un control que no puede demostrarse no se declara implementado. Cada control de
esta política se acompaña de una evidencia localizable: una ruta de archivo, un registro, una captura
o un acta. Este principio se aplica sin excepción, incluso cuando el resultado es incómodo, como
ocurre con la capa de perímetro documentada en DA-001.

**Proporcionalidad.** Los controles se dimensionan según el riesgo que atienden y según los recursos
efectivamente disponibles. Declarar un objetivo de recuperación que la infraestructura contratada no
puede sostener no aumenta la seguridad: solo traslada el fallo del plano técnico al plano documental.

**Equilibrio del triángulo de la ciberresiliencia.** La inversión se distribuye entre protección,
detección y respuesta. El anexo del proyecto verificó que la propuesta original concentraba su
esfuerzo en el primer vértice; esta política, junto con POL-002 y POL-003, corrige ese desequilibrio.

## 5. Roles y responsabilidades

La estructura se organiza en los tres niveles de decisión definidos en el primer entregable. Cada
nivel responde por una clase distinta de decisión, y la separación entre quien define la política y
quien la ejecuta es la que permite auditar el sistema sin conflicto de interés.

### 5.1 Nivel estratégico

Lo integran la Dirección General y el Director de Seguridad de la Información. Responden por las
decisiones que comprometen recursos o que aceptan riesgo en nombre de la organización.

| Responsabilidad | Alcance concreto en el proyecto |
|---|---|
| Aprobar la política de seguridad y sus revisiones | Este documento y los que de él derivan |
| Establecer el apetito de riesgo | Fijar qué riesgos residuales se aceptan y cuáles exigen tratamiento adicional, conforme a `docs/04-evidencias/matriz-de-riesgos.md` |
| Asignar el presupuesto | Los Q1,069.20 de desembolso real aprobados en el primer entregable, incluida la reserva de contingencia |
| Declarar un incidente de severidad crítica | Decisión indelegable: la asume el CISO y, en su ausencia, la Dirección General |
| Autorizar la comunicación externa | Toda notificación a titulares de datos o a terceros requiere su aprobación previa |
| Revisar el sistema de gestión | Revisión formal semestral, con acta |

### 5.2 Nivel táctico

Lo integran la Gerencia de Operaciones y el Arquitecto de Seguridad. Traducen la política en diseño y
en criterios verificables.

| Responsabilidad | Alcance concreto en el proyecto |
|---|---|
| Diseñar el esquema de defensa por capas | Las seis capas y sus interfaces |
| Clasificar los activos de información | Sección 6 de esta política y el inventario de la matriz de riesgos |
| Definir las reglas del cortafuegos y su umbral | Paranoia 1 en bloqueo, paranoia 2 en detección, umbral de anomalía entrante 5 y saliente 4 |
| Coordinar la respuesta a incidentes | Función de Coordinador de Respuesta definida en POL-002 |
| Mantener las decisiones de arquitectura | `docs/02-arquitectura/decisiones-de-arquitectura.md` |
| Aprobar excepciones temporales | Con registro escrito, plazo de vencimiento y riesgo residual declarado |

### 5.3 Nivel operativo

Lo integran el Departamento de TI y el Hacker Ético, en su condición de analista de vulnerabilidades.
Ejecutan, monitorean y verifican.

| Responsabilidad | Alcance concreto en el proyecto |
|---|---|
| Operar el turno de guardia | Revisión de alertas conforme a la ventana declarada en POL-003 |
| Aplicar parches | Ventana máxima de 72 horas para vulnerabilidades críticas |
| Ejecutar los respaldos y probar la restauración | Volcado diario cifrado y prueba mensual con acta, conforme a POL-004 |
| Desarrollar con criterios de codificación segura | Validación de entradas, sentencias preparadas, cifrado de datos personales en reposo |
| Ejecutar pruebas de intrusión | OWASP ZAP, Nmap, Lynis y `testssl`, con reporte archivado en `evidencias/` |
| Atender el primer nivel de respuesta | Triaje, contención inicial y escalamiento |

### 5.4 Segregación de funciones

Quien desarrolla no aprueba su propio despliegue a producción, y quien opera el sistema no es quien
audita sus registros. En un equipo de tres personas la segregación absoluta es imposible, y afirmar lo
contrario sería falso. Lo que sí se sostiene y se verifica es la segregación de la evidencia: el
registro de auditoría de la aplicación se escribe en una tabla que ningún rol de la aplicación puede
modificar, y el registro del cortafuegos se genera en un contenedor distinto del que ejecuta la
aplicación y se monta en ella en modo de solo lectura. El operador puede leer lo que hizo; no puede
reescribirlo.

## 6. Clasificación de la información

Toda la información del proyecto se clasifica en uno de cuatro niveles. La clasificación determina el
tratamiento, no al revés: un dato no es confidencial porque esté cifrado, sino que se cifra porque es
confidencial.

| Nivel | Definición | Ejemplos en MarketGT | Tratamiento obligatorio |
|---|---|---|---|
| **Público** | Información destinada a difusión abierta, cuya divulgación no causa perjuicio | Catálogo de productos, precios, contenido del sitio, `sitemap.xml` | Ninguna restricción de lectura. Se protege su integridad, no su confidencialidad |
| **Uso interno** | Información operativa cuya divulgación no causa perjuicio grave pero sí facilita un ataque | Documentación de arquitectura, decisiones técnicas, configuración no secreta, esta política | Acceso limitado al equipo. No se publica en el repositorio de acceso abierto sin revisión previa |
| **Confidencial** | Información cuya divulgación perjudica a un titular identificable o a la operación | Datos personales de clientes: dirección, teléfono y documento de identidad; historial de pedidos; registros de eventos de seguridad con carga útil de ataques | Cifrado en reposo, acceso restringido por rol, registro de todo acceso en la bitácora de auditoría |
| **Restringido** | Información cuya divulgación compromete el sistema completo de forma inmediata | Contraseñas, secretos de segundo factor, claves de cifrado, `APP_KEY`, credenciales de la base de datos, llaves privadas TLS, secreto de la consola de demostración | Nunca en texto claro, nunca en el repositorio, nunca en un registro. Rotación obligatoria ante cualquier sospecha de exposición |

### 6.1 Reglas derivadas de la clasificación

Los datos personales de los clientes —dirección, teléfono y documento de identidad— se almacenan
cifrados mediante el mecanismo de cifrado simétrico de Laravel, en columnas de tipo texto. La
consecuencia operativa se acepta de forma consciente y consta en el propio código: sobre esas columnas
no puede buscarse ni ordenarse en SQL, porque dos cifrados del mismo valor son distintos.

Los números de tarjeta de pago no se almacenan en ningún caso. La plataforma opera con tokenización, y
el requisito 3.4 del estándar PCI DSS se satisface por la vía de no custodiar el dato, que es la única
forma de custodiarlo bien.

La información restringida no se elimina cuando deja de usarse: se rota. Un secreto expuesto no se
vuelve seguro porque se deje de utilizar.

## 7. Uso aceptable

### 7.1 De las cuentas y credenciales

Cada persona opera bajo una cuenta nominal e intransferible. Las cuentas con rol administrativo o de
auditoría exigen un segundo factor de autenticación activo: código temporal conforme al RFC 6238 o
credencial de clave pública conforme a la especificación WebAuthn, esta última preferente por su
resistencia a la suplantación de sitio.

Las contraseñas no se comparten, no se reutilizan entre servicios y no se transmiten por canales de
mensajería. Los códigos de recuperación se custodian fuera del dispositivo que genera el segundo
factor; guardarlos junto al autenticador anula su función.

Toda operación que debilita una cuenta —retirar el segundo factor, eliminar una credencial de clave
pública, regenerar los códigos de recuperación, cambiar la contraseña o cerrar las sesiones activas—
exige volver a demostrar el conocimiento de la contraseña dentro de la ventana de reautenticación
configurada. Una sesión robada permite navegar; no puede permitir secuestrar.

### 7.2 Del acceso administrativo

El acceso al servidor se realiza exclusivamente mediante SSH con autenticación por llave. La
autenticación por contraseña y el acceso directo del superusuario están deshabilitados, y el
cortafuegos de red mantiene expuestos únicamente los puertos 80 y 443 hacia Internet.

El panel de monitoreo expone el detalle de los ataques recibidos, incluidas las rutas sondeadas y las
cargas útiles empleadas. Ese detalle es exactamente el mapa de lo que el cortafuegos deja pasar, de
modo que la sección completa exige sesión iniciada y rol de administrador o de auditor.

### 7.3 Del desarrollo

Ningún secreto se incorpora al repositorio. Las credenciales viven en archivos de entorno excluidos
del control de versiones, y el repositorio incluye una plantilla con valores de ejemplo.

Toda consulta a la base de datos se ejecuta mediante sentencias preparadas. Toda entrada de usuario se
valida en el servidor, con independencia de que exista validación en el navegador. Todo contenido
generado por usuarios se sanea antes de almacenarse y se escapa antes de presentarse.

Las dependencias se auditan antes de cada despliegue. Los componentes vulnerables representan el
12.36 % de las vulnerabilidades web según la infografía analizada en el curso, y son la segunda causa
por frecuencia: una dependencia desactualizada anula el trabajo de las seis capas.

### 7.4 De lo que está prohibido

Se prohíbe desactivar un control de seguridad para facilitar una prueba, salvo mediante excepción
escrita aprobada por el nivel táctico, con plazo de vencimiento declarado y con registro del riesgo
asumido durante la ventana. En particular, el motor de reglas del cortafuegos no se pone en modo de
solo detección ni se desactiva para depurar un fallo funcional: se depura con exclusiones específicas,
documentadas y acotadas a la regla y a la ruta que las requieren.

Se prohíbe registrar información restringida en cualquier bitácora, así como extraer datos de clientes
del entorno controlado por cualquier medio, incluidas las capturas de pantalla destinadas a la
presentación. El material de la defensa emplea identidades de fantasía.

## 8. Gestión de accesos

El control de acceso se basa en tres roles fijos —administrador, auditor y cliente— implementados
directamente en la aplicación. La decisión de no adoptar un paquete de permisos de terceros consta en
el registro de decisiones de arquitectura: para tres roles que no crecen, la dependencia añadiría
superficie de ataque y mantenimiento sin ahorro proporcional, y además permite que el código que
decide quién entra al panel de monitoreo se lea completo en una revisión.

| Rol | Alcance | Segundo factor |
|---|---|---|
| Administrador | Gestión del catálogo, de los pedidos y de los usuarios; acceso completo al panel de monitoreo | Obligatorio |
| Auditor | Lectura del panel de monitoreo, de los eventos, de las alertas y de las métricas; sin capacidad de modificación | Obligatorio |
| Cliente | Su propia cuenta, su carrito y sus pedidos | Recomendado y disponible |

Los accesos se revisan al cierre de cada ciclo semestral de revisión del sistema de gestión. Las
cuentas no se eliminan: se desactivan, porque eliminar un usuario destruye la trazabilidad de sus
pedidos y de sus registros de auditoría, que es justamente lo que una auditoría necesita conservar.

## 9. Referencia a los controles del Anexo A

Esta política constituye el control **A.5.1, Políticas de seguridad de la información**, y da soporte
a los controles que se listan a continuación. La correspondencia completa, con el estado de
implementación y la evidencia verificable de cada uno, consta en
`docs/04-evidencias/matriz-de-controles-iso27001.md`, que es el documento que una auditoría revisará.

| Control | Nombre | Documento o mecanismo que lo desarrolla |
|---|---|---|
| A.5.7 | Inteligencia de amenazas | Reglas propias del cortafuegos y panel de monitoreo |
| A.5.15 | Control de acceso | Sección 8 de esta política |
| A.5.24 | Planificación y preparación de la gestión de incidentes | POL-002 |
| A.5.25 | Evaluación y decisión sobre eventos de seguridad | POL-002, sección de triaje |
| A.5.26 | Respuesta a incidentes | POL-002, procedimientos por tipo |
| A.5.27 | Aprendizaje de los incidentes | POL-002, sección de lecciones aprendidas |
| A.5.28 | Recopilación de evidencias | POL-002, cadena de custodia |
| A.5.29 | Seguridad durante la disrupción | POL-004 |
| A.5.30 | Preparación de las TIC para la continuidad del negocio | POL-004, objetivos de recuperación |
| A.6.3 | Concienciación, educación y capacitación | POL-006 |
| A.8.5 | Autenticación segura | Sección 7.1 de esta política |
| A.8.8 | Gestión de vulnerabilidades técnicas | Sección 7.3 y ventana de parcheo de 72 horas |
| A.8.13 | Respaldo de la información | POL-004 |
| A.8.15 | Registro de eventos | POL-005 |
| A.8.16 | Actividades de seguimiento | Panel de monitoreo y reglas de correlación |
| A.8.20 a A.8.24 | Seguridad de redes, servicios de red, segregación, filtrado web y criptografía | Esquema de capas y DA-001 |
| A.8.26 | Requisitos de seguridad de las aplicaciones | Sección 7.3 |
| A.8.28 | Codificación segura | Sección 7.3 |

## 10. Cumplimiento y consecuencias

El incumplimiento de esta política se trata como un evento de seguridad y se registra como tal. En el
contexto académico del proyecto, la consecuencia de un incumplimiento es su documentación en el acta
de revisión y la corrección de la causa raíz, no una sanción disciplinaria.

Lo que sí constituye una falta grave, y así debe constar, es declarar implementado un control que no
lo está. La política admite controles parciales, controles pendientes y controles fuera de alcance
—todos ellos debidamente justificados—; no admite una declaración que una verificación pueda
desmentir.

## 11. Excepciones

Toda excepción a esta política se solicita por escrito al nivel táctico y se registra con cuatro
elementos: el control afectado, la razón de negocio o técnica que la motiva, el riesgo residual que se
asume durante su vigencia y la fecha de vencimiento. Una excepción sin fecha de vencimiento no es una
excepción: es una modificación de la política, y como tal requiere la aprobación del nivel
estratégico.

La decisión DA-001, relativa al alcance real de la capa de perímetro, constituye el primer caso
registrado bajo este mecanismo y sirve de modelo de la forma esperada.

## 12. Vigencia y revisión

Esta política entra en vigor el 21 de septiembre de 2026 y permanece vigente hasta su sustitución por
una versión posterior. Se revisa de forma ordinaria cada seis meses, dentro de la revisión del sistema
de gestión, y de forma extraordinaria ante cualquiera de estos supuestos: un incidente de severidad
crítica, un cambio material en la arquitectura, la incorporación de un nuevo proveedor o la
publicación de una revisión de la norma rectora.

## 13. Control de versiones

| Versión | Fecha | Autor | Cambio |
|---|---|---|---|
| 1.0 | 2026-09-21 | Nivel táctico — Arquitecto de Seguridad | Emisión inicial. Cierra la brecha de gobierno señalada en el anexo del triángulo de la ciberresiliencia |

---

## Documentos relacionados

| Código | Documento | Ruta |
|---|---|---|
| POL-002 | Plan de respuesta a incidentes | `docs/03-politicas/plan-de-respuesta-a-incidentes.md` |
| POL-003 | Matriz de escalamiento | `docs/03-politicas/matriz-de-escalamiento.md` |
| POL-004 | Política de respaldo y recuperación | `docs/03-politicas/politica-de-respaldo-y-recuperacion.md` |
| POL-005 | Política de retención de registros | `docs/03-politicas/politica-de-retencion-de-registros.md` |
| POL-006 | Plan de concienciación y capacitación | `docs/03-politicas/plan-de-concienciacion-y-capacitacion.md` |
| — | Matriz de controles del Anexo A | `docs/04-evidencias/matriz-de-controles-iso27001.md` |
| — | Matriz de riesgos | `docs/04-evidencias/matriz-de-riesgos.md` |
| — | Registro de decisiones de arquitectura | `docs/02-arquitectura/decisiones-de-arquitectura.md` |
| — | Manual de operación | `docs/02-arquitectura/manual-de-operacion.md` |
</content>
</invoke>
