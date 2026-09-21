# Matriz de Controles del Anexo A · ISO/IEC 27001:2022

| | |
|---|---|
| **Identificador** | EVI-001 |
| **Versión** | 1.0 |
| **Fecha de corte** | 21 de septiembre de 2026 |
| **Elaborado por** | Nivel táctico — Arquitecto de Seguridad |
| **Revisado por** | Nivel operativo — Hacker Ético, en su función de auditor |
| **Aprobado por** | Nivel estratégico — Director de Seguridad de la Información (CISO) |
| **Clasificación** | Uso interno del proyecto |
| **Alcance** | Plataforma MarketGT y su infraestructura de soporte, conforme a POL-001 sección 2 |

---

## 1. Propósito y advertencia de lectura

Este documento es la Declaración de Aplicabilidad del proyecto: recoge, control por control, si aplica,
cómo se implementa y **con qué evidencia puede verificarse**. Es el documento que una auditoría revisa
primero, porque es el único que puede contrastarse contra el sistema real.

Una advertencia sobre su lectura. Esta matriz **no declara la totalidad de los controles cubiertos**.
Trece controles figuran como implementados de forma parcial, seis como definidos pero no ejecutados,
uno como vacío reconocido y dieciocho como fuera de alcance con su justificación. Esa distribución es
deliberada.

El anexo del triángulo de la ciberresiliencia estableció el criterio que aquí se aplica: un control que
no puede verificarse no debe declararse implementado. Una matriz honesta que reconoce sus vacíos tiene
más valor de auditoría que una que declara todo cubierto, porque la segunda pierde toda credibilidad
en el primer control que la verificación desmiente, y arrastra consigo a los que sí eran ciertos.

## 2. Estados empleados

| Estado | Significado | Qué debe existir |
|---|---|---|
| **Implementado** | El control opera y puede demostrarse hoy | Evidencia localizable y verificable |
| **Parcial** | Opera en parte, con una limitación conocida y declarada | Evidencia de lo implementado y documento que declara la limitación |
| **Definido, no ejecutado** | El procedimiento está escrito y aprobado, pero no se ha ejecutado | Documento aprobado; la evidencia de ejecución está pendiente |
| **Pendiente** | Ni definido ni implementado | Nada. Se declara como vacío |
| **No aplica** | Fuera del alcance declarado | Justificación escrita |

## 3. Resumen del estado

| Estado | Controles | Porcentaje sobre los 69 evaluados |
|---|---|---|
| Implementado | 31 | 44.9 % |
| Parcial | 13 | 18.8 % |
| Definido, no ejecutado | 6 | 8.7 % |
| Pendiente | 1 | 1.5 % |
| No aplica | 18 | 26.1 % |
| **Total evaluado** | **69** | de los 93 del Anexo A |

De los 69 evaluados, **18 se declaran fuera de alcance** —los catorce controles físicos de la familia
A.7 y cuatro de la familia A.6 relativos a la relación laboral—, de modo que 51 controles quedan dentro
del alcance efectivo del sistema de gestión. De esos 51, **31 están implementados y verificables hoy**.

Los 24 controles restantes del Anexo A no se evalúan en esta versión por corresponder a ámbitos que el
entorno académico del proyecto no reproduce. La sección 8 los enumera uno a uno.

---

## 4. Controles organizacionales (A.5)

| Control | Nombre | ¿Aplica? | Estado | Implementación en MarketGT | Evidencia verificable |
|---|---|---|---|---|---|
| **A.5.1** | Políticas de seguridad de la información | Sí | Implementado | Política aprobada por el nivel estratégico, con alcance, roles por los tres niveles, clasificación de la información y uso aceptable | `docs/03-politicas/politica-de-seguridad-de-la-informacion.md` |
| **A.5.2** | Roles y responsabilidades | Sí | Implementado | Tres niveles de decisión con responsabilidades tabuladas; funciones de respuesta con titular y suplente | POL-001 secciones 5.1 a 5.4; POL-002 sección 4 |
| **A.5.7** | Inteligencia de amenazas | Sí | **Parcial** | Las reglas propias 15000 a 15099 codifican patrones de ataque de posicionamiento observados; la lista de rangos de rastreadores legítimos se actualiza mediante un guion. No existe suscripción a ninguna fuente externa de indicadores de compromiso | `infra/modsecurity/REQUEST-945-MARKETGT-SEO.conf`; `infra/modsecurity/marketgt-good-bots.data`; `infra/scripts/actualizar-ips-crawlers.sh` |
| **A.5.10** | Uso aceptable de la información y de los activos | Sí | Implementado | Reglas de uso de cuentas, acceso administrativo, desarrollo y prohibiciones expresas | POL-001 sección 7 |
| **A.5.12** | Clasificación de la información | Sí | Implementado | Cuatro niveles con ejemplos concretos del proyecto y tratamiento obligatorio asociado a cada uno | POL-001 sección 6 |
| **A.5.14** | Transferencia de información | Sí | Implementado | TLS 1.3 en tránsito; prohibición expresa de transmitir información restringida por mensajería durante un incidente | POL-003 sección 5.1; configuración TLS del conjunto de contenedores |
| **A.5.15** | Control de acceso | Sí | Implementado | Tres roles fijos —administrador, auditor y cliente— con verificación por middleware; el panel de monitoreo exige rol de auditor o administrador | `app/app/Models/Rol.php`; `app/app/Http/Middleware/VerificarRol.php`; `app/routes/siem.php` |
| **A.5.16** | Gestión de la identidad | Sí | Implementado | Cuentas nominales e intransferibles; desactivación en lugar de eliminación, para preservar la trazabilidad | `app/database/migrations/2026_09_22_000302_agregar_datos_personales_cifrados_a_users.php`, columna `activo` |
| **A.5.17** | Información de autenticación | Sí | Implementado | Contraseñas con función de derivación; segundo factor por código temporal o credencial de clave pública; códigos de recuperación de un solo uso | `app/config/fortify.php`; `app/app/Actions/Fortify/`; `app/tests/Feature/Auth/TwoFactorChallengeTest.php` |
| **A.5.18** | Derechos de acceso | Sí | **Parcial** | Los derechos se asignan por rol y se registra quién los asignó y cuándo. La revisión periódica de accesos está prevista en la revisión semestral pero **no se ha ejecutado** | Tabla `rol_usuario` con `asignado_por` y `asignado_en`; POL-001 sección 8 |
| **A.5.24** | Planificación y preparación de la gestión de incidentes | Sí | Implementado | Plan estructurado sobre las seis funciones del Marco de Ciberseguridad 2.0 conforme a NIST SP 800-61r3; equipo con funciones, autoridad y regla de separación | `docs/03-politicas/plan-de-respuesta-a-incidentes.md` secciones 2 a 4 |
| **A.5.25** | Evaluación y decisión sobre eventos de seguridad | Sí | Implementado | Distinción formal entre evento, alerta e incidente; procedimiento de triaje de cinco preguntas; escala de severidad alineada con la del panel | POL-002 secciones 3, 5 y 6.2; estados de `alertas_seguridad` |
| **A.5.26** | Respuesta a incidentes de seguridad | Sí | **Definido, no ejecutado** | Seis procedimientos operativos por tipo de incidente, con contención, erradicación, recuperación y evidencia obligatoria. **Ningún procedimiento se ha ejercitado**: el simulacro de mesa está programado y no ejecutado | POL-002 sección 7; el acta de simulacro está **pendiente** |
| **A.5.27** | Aprendizaje de los incidentes | Sí | **Definido, no ejecutado** | Revisión posterior obligatoria para S1 y S2 con seis preguntas y acta; regla de causa raíz; realimentación hacia reglas, código y políticas | POL-002 sección 11. No existen actas porque no ha habido incidentes reales |
| **A.5.28** | Recopilación de evidencias | Sí | Implementado | Cadena de custodia con orden de volatilidad, copia en lugar de original, sellado por resumen SHA-256 y registro de traspasos; formato de acta definido | POL-002 sección 9; directorio `evidencias/` previsto, **hoy vacío** |
| **A.5.29** | Seguridad de la información durante la disrupción | Sí | **Parcial** | El conjunto de contenedores incorpora un servicio de respaldo estático que permite que el cortafuegos siga inspeccionando y registrando aunque la aplicación caiga. El procedimiento de restauración está definido pero no probado | `infra/docker/docker-compose.yml`, servicio `respaldo`; POL-004 sección 7 |
| **A.5.30** | Preparación de las TIC para la continuidad del negocio | Sí | **Definido, no ejecutado** | Objetivos declarados y desglosados: punto de recuperación de 24 horas y tiempo de recuperación de 4 horas. La prueba mensual que los verifica **no se ha ejecutado** | POL-004 secciones 6 y 8; acta `RES-2026-09` **pendiente** |
| **A.5.34** | Privacidad y protección de datos personales | Sí | Implementado | Datos personales cifrados en reposo; minimización por retención declarada; procedimiento de comunicación a titulares ante brecha, adoptado de forma voluntaria ante la ausencia de ley general en Guatemala | Migración de datos personales cifrados; POL-002 sección 10; POL-005 sección 3 |
| **A.5.35** | Revisión independiente de la seguridad | Sí | **Parcial** | El rol de auditor existe, está implementado en la aplicación con acceso de solo lectura y es ejercido por un integrante distinto del que opera la plataforma. No existe revisión por un tercero externo al equipo | `app/app/Models/Rol.php`, constante `AUDITOR`; `app/routes/siem.php` |
| **A.5.37** | Procedimientos operativos documentados | Sí | Implementado | Manual de operación con arranque, despliegue, revisión de alertas, restauración y resolución de fallos frecuentes | `docs/02-arquitectura/manual-de-operacion.md` |

---

## 5. Controles de personas (A.6)

| Control | Nombre | ¿Aplica? | Estado | Implementación en MarketGT | Evidencia verificable |
|---|---|---|---|---|---|
| **A.6.1** | Investigación de antecedentes | **No** | No aplica | El equipo lo integran tres estudiantes en el marco de un curso universitario. No existe relación laboral ni proceso de contratación sobre el que aplicar el control | Justificación en POL-001 sección 2.2 |
| **A.6.2** | Términos y condiciones del empleo | **No** | No aplica | Misma razón que A.6.1 | POL-001 sección 2.2 |
| **A.6.3** | Concienciación, educación y capacitación | Sí | **Definido, no ejecutado** | Plan semestral de seis módulos con material de la Fundación OWASP, registro de asistencia, evaluación con umbral del 80 % y ejercicio práctico sobre el sistema real. **Ninguna edición se ha impartido** | `docs/03-politicas/plan-de-concienciacion-y-capacitacion.md`. Métrica de personal capacitado: **0 % frente a meta del 100 %** |
| **A.6.4** | Proceso disciplinario | **No** | No aplica | No existe potestad disciplinaria en el contexto académico. El incumplimiento se trata como evento de seguridad y se corrige su causa raíz | POL-001 sección 10 |
| **A.6.7** | Trabajo remoto | Sí | Implementado | El acceso administrativo se realiza exclusivamente por SSH con autenticación por llave, con contraseña y acceso directo del superusuario deshabilitados | `infra/scripts/02-hardening-servidor.sh` |
| **A.6.8** | Reporte de eventos de seguridad | Sí | Implementado | Canal de guardia con prioridades declaradas y acuse de recibo explícito; el reporte de una persona figura como fuente de detección de pleno derecho | POL-003 sección 5; POL-002 sección 6.1 |

---

## 6. Controles físicos (A.7)

Los once controles de esta familia **no aplican**. La infraestructura de cómputo reside en un centro de
datos del proveedor Google Cloud, cuya seguridad física, control de acceso, suministro eléctrico,
protección ambiental y eliminación segura de soportes son responsabilidad íntegra del proveedor y
están cubiertas por sus propias certificaciones.

El equipo no administra ningún activo físico propio dentro del alcance del sistema de gestión. Declarar
estos controles como implementados sobre la base de las certificaciones del proveedor sería trasladar
una evidencia ajena, y declararlos implementados por cuenta propia sería falso.

| Controles | Tratamiento |
|---|---|
| A.7.1 a A.7.14 | No aplican. Responsabilidad del proveedor de infraestructura. La verificación de esa responsabilidad corresponde al control A.5.19, que esta versión no evalúa por tratarse de un servicio gratuito sin contrato negociado |

---

## 7. Controles tecnológicos (A.8)

| Control | Nombre | ¿Aplica? | Estado | Implementación en MarketGT | Evidencia verificable |
|---|---|---|---|---|---|
| **A.8.1** | Dispositivos de usuario final | **No** | No aplica | El proyecto no administra los equipos personales de los integrantes ni define una flota corporativa | POL-001 sección 2.2 |
| **A.8.2** | Derechos de acceso privilegiado | Sí | Implementado | El usuario de la base de datos que emplea la aplicación no es administrador; el contenedor de la aplicación no corre como superusuario; el acceso administrativo del sistema exige llave | `infra/docker/docker-compose.yml`; `infra/scripts/02-hardening-servidor.sh` |
| **A.8.3** | Restricción del acceso a la información | Sí | Implementado | El panel de monitoreo exige sesión iniciada y rol de auditor o administrador, porque expone rutas sondeadas y cargas útiles de los ataques recibidos | `app/routes/siem.php`, middleware `rol:admin,auditor` |
| **A.8.5** | Autenticación segura | Sí | Implementado | Segundo factor obligatorio en cuentas privilegiadas: código temporal conforme al RFC 6238 o credencial de clave pública WebAuthn; códigos de recuperación de un solo uso; reautenticación obligatoria antes de cualquier operación que debilite la cuenta | `app/routes/seguridad.php`, middleware `password.confirm`; `app/resources/views/pages/settings/⚡security.blade.php`; `app/tests/Feature/Settings/SecurityTest.php` |
| **A.8.6** | Gestión de la capacidad | Sí | **Parcial** | La máquina virtual se dimensionó de forma explícita —cuatro núcleos y 16 GB— y se ajustaron los parámetros de núcleo y memoria de intercambio que el conjunto exige. No existe monitoreo continuo de capacidad con alerta por umbral | `infra/scripts/03-crear-vm-gcp.sh`; `infra/scripts/04-desplegar.sh` |
| **A.8.7** | Protección contra código malicioso | Sí | **Pendiente** | No hay antivirus ni análisis de archivos subidos por usuarios. La plataforma admite carga de imágenes de producto, de modo que el vacío es real y no teórico. Mitigación parcial vigente: límite de tamaño de cuerpo y rechazo de excedentes en el cortafuegos | Ninguna. Se declara como vacío en la matriz de riesgos |
| **A.8.8** | Gestión de vulnerabilidades técnicas | Sí | **Parcial** | Parcheo automático de seguridad del sistema operativo habilitado y ventana máxima de 72 horas declarada para vulnerabilidades críticas. La auditoría de dependencias y el escaneo automatizado del repositorio **no están configurados**: el directorio de flujos de trabajo está vacío | `infra/scripts/02-hardening-servidor.sh` sección 1. `.github/workflows/` **vacío**; reportes de OWASP ZAP, Nmap y Lynis **pendientes** |
| **A.8.9** | Gestión de la configuración | Sí | Implementado | La configuración completa vive bajo control de versiones: conjunto de contenedores, reglas del cortafuegos, exclusiones y guiones de aprovisionamiento. Las imágenes se fijan por etiqueta exacta y no por etiqueta rodante | `infra/docker/docker-compose.yml`, imagen fijada a `4.29.0-nginx-202609180209` |
| **A.8.10** | Eliminación de información | Sí | **Definido, no ejecutado** | Purga a los 90 días con archivado previo en frío y eliminación definitiva a los 15 meses. La rutina **no se ha ejercido** porque el sistema no acumula aún 90 días de operación | `app/config/siem.php`, bloque `retencion`; POL-005 sección 3 |
| **A.8.11** | Enmascaramiento de datos | Sí | **Parcial** | El conjunto de reglas oculta el valor de campos sensibles cuando la regla correspondiente está activa. No existe enmascaramiento sistemático en la interfaz ni en los informes | `infra/modsecurity/REQUEST-900-EXCLUSION-RULES-BEFORE-CRS.conf`; POL-005 sección 5.1 |
| **A.8.12** | Prevención de fuga de datos | Sí | Implementado | La inspección del cuerpo de la respuesta permanece habilitada de forma expresa para que operen las reglas de la familia 95x; las reglas propias detectan extracción masiva del catálogo | `infra/docker/docker-compose.yml`, `MODSEC_RESP_BODY_ACCESS: On`; `infra/modsecurity/REQUEST-946-MARKETGT-ANTISCRAPING.conf` |
| **A.8.13** | Respaldo de la información | Sí | **Definido, no ejecutado** | Esquema 3-2-1 con volcado diario cifrado hacia almacenamiento externo de proveedor distinto, credencial sin permiso de borrado, objetivos de 24 y 4 horas y prueba mensual con acta. **Los guiones de respaldo y de restauración no constan en el repositorio** | `docs/03-politicas/politica-de-respaldo-y-recuperacion.md`. Guiones y acta `RES-2026-09` **pendientes** |
| **A.8.15** | Registro de eventos | Sí | **Parcial** | Registro en formato JSON del cortafuegos, bitácora de seguridad de la aplicación y tabla de auditoría consultable; plazos de 90 días y 12 meses declarados en la configuración; separación de privilegios verificable. El archivado en frío **no se ha ejercido** | `app/config/siem.php`; `app/app/Models/RegistroAuditoria.php`; `app/app/Http/Middleware/RegistrarAuditoria.php`; `infra/scripts/leer-audit-log.sh` |
| **A.8.16** | Actividades de seguimiento | Sí | Implementado | Ingesta incremental del registro del cortafuegos, normalización, motor de correlación con reglas y umbrales, alertas con ciclo de estados y tablero de métricas contrastadas contra sus metas | `app/app/Console/Commands/IngerirEventosWaf.php`; `app/app/Console/Commands/CorrelacionarEventos.php`; `app/app/Services/Siem/MotorCorrelacion.php`; `app/routes/siem.php` |
| **A.8.17** | Sincronización de relojes | Sí | Implementado | Sincronización por protocolo de tiempo de red en el anfitrión; los contenedores heredan su reloj | `infra/scripts/02-hardening-servidor.sh`; POL-005 sección 6.4 |
| **A.8.19** | Instalación de software en sistemas operativos | Sí | Implementado | El sistema se construye desde guiones idempotentes bajo control de versiones; las dependencias de la aplicación se resuelven en la construcción de la imagen, no en ejecución | `infra/docker/Dockerfile.app`; `infra/scripts/00-setup-wsl.sh` |
| **A.8.20** | Seguridad de redes | Sí | **Parcial** | Cortafuegos de red con únicamente los puertos 80 y 443 expuestos y bloqueo automático por intentos fallidos. **La capa de perímetro está implementada de forma parcial**: no hay mitigación de denegación de servicio en el borde ni límite de tasa antes del origen | `infra/scripts/02-hardening-servidor.sh`; limitación declarada y justificada en `docs/02-arquitectura/decision-capa1-perimetro.md` |
| **A.8.21** | Seguridad de los servicios de red | Sí | Implementado | TLS 1.3 con certificado emitido y renovado de forma automática; cabeceras de seguridad de transporte estricto y política de contenido | `app/app/Http/Middleware/CabecerasSeguridad.php`; `infra/scripts/04-desplegar.sh` |
| **A.8.22** | Segregación en redes | Sí | Implementado | La red interna está declarada como no enrutable: la aplicación y la base de datos no son alcanzables desde Internet ni desde el anfitrión, y el único camino hacia la aplicación atraviesa el cortafuegos. Es evidencia directa de ausencia de elusión | `infra/docker/docker-compose.yml`, red `appnet` con `internal: true` |
| **A.8.23** | Filtrado web | Sí | Implementado | ModSecurity 3 con OWASP Core Rule Set 4.29, nivel de paranoia 1 en bloqueo y 2 en detección, umbral de anomalía entrante 5 y saliente 4; veinte reglas propias entre los identificadores 15000 y 15099 | `infra/docker/docker-compose.yml`; `infra/modsecurity/`; demostración reproducible en `infra/scripts/demo-waf.sh` y `infra/scripts/demo-seo.sh` |
| **A.8.24** | Uso de criptografía | Sí | **Parcial** | TLS 1.3 en tránsito y cifrado simétrico de los datos personales en reposo. El cifrado del respaldo está definido pero no implementado, y no existe gestión formal del ciclo de vida de las claves más allá de la custodia declarada | Migración de datos personales cifrados; POL-004 sección 5.1 |
| **A.8.25** | Ciclo de vida de desarrollo seguro | Sí | **Parcial** | Criterios de codificación segura declarados y análisis estático configurado. No existe revisión de código por pares formalizada, lo que en un equipo de tres personas es una limitación real | `app/phpstan.neon`; `app/pint.json`; POL-001 sección 7.3 |
| **A.8.26** | Requisitos de seguridad de las aplicaciones | Sí | Implementado | Autenticación multifactor, control de acceso por roles, validación de entradas en el servidor, protección contra falsificación de petición entre sitios y cabeceras de seguridad | `app/app/Http/Middleware/`; `app/tests/Feature/Auth/`; `app/config/seguridad.php` |
| **A.8.27** | Arquitectura de sistemas seguros | Sí | Implementado | Defensa en profundidad de seis capas con el supuesto explícito de que cualquiera puede fallar; decisiones registradas, incluidas las que reconocen limitaciones | `docs/02-arquitectura/decisiones-de-arquitectura.md` |
| **A.8.28** | Codificación segura | Sí | Implementado | Sentencias preparadas mediante el mapeador objeto-relacional; saneamiento del contenido generado por usuarios; escape de salida; ausencia de secretos en el repositorio | `app/app/Services/Seo/SanitizadorUgc.php`; `app/app/Services/Seo/RedireccionSegura.php`; `infra/docker/.env.example` |
| **A.8.29** | Pruebas de seguridad en desarrollo y aceptación | Sí | **Parcial** | Existe una batería de pruebas automatizadas sobre autenticación, segundo factor, confirmación de contraseña y controles de la pantalla de seguridad, y guiones de demostración que verifican el cortafuegos de extremo a extremo. **Las pruebas de intrusión con OWASP ZAP y Nmap no se han ejecutado** | `app/tests/Feature/`; `infra/scripts/demo-waf.sh`. Reportes en `evidencias/` **pendientes** |
| **A.8.31** | Separación de entornos | Sí | Implementado | Entorno de desarrollo sobre WSL2 y entorno de producción sobre la máquina virtual, ambos construidos desde los mismos guiones para evitar divergencia | `infra/scripts/00-setup-wsl.sh`; `infra/scripts/03-crear-vm-gcp.sh` |
| **A.8.32** | Gestión de cambios | Sí | **Parcial** | Los cambios se registran en el control de versiones y las decisiones de arquitectura quedan documentadas. No existe procedimiento formal de aprobación previa al despliegue con segregación entre quien desarrolla y quien aprueba | `docs/02-arquitectura/decisiones-de-arquitectura.md`; POL-001 sección 5.4 |
| **A.8.33** | Información de prueba | Sí | Implementado | Los datos de demostración emplean identidades de fantasía. No existen datos personales reales en ningún entorno | `app/database/seeders/UsuariosDemoSeeder.php`; `app/database/seeders/EventosDemoSeeder.php` |

---

## 8. Controles no evaluados en esta versión

Los siguientes grupos no se evalúan porque el contexto del proyecto no reproduce el supuesto que los
motiva. Se enumeran para que la ausencia sea explícita y no se confunda con un olvido.

| Grupo | Controles | Cantidad | Razón |
|---|---|---|---|
| Gobierno organizativo | A.5.3, A.5.4, A.5.5, A.5.6 | 4 | Segregación de funciones, responsabilidades de la dirección y contacto con autoridades y con grupos de interés. Un equipo de tres personas en contexto académico no reproduce estas estructuras. La limitación de segregación se declara de forma expresa en POL-001 sección 5.4 |
| Gestión de proyectos | A.5.8 | 1 | El proyecto es en sí mismo el objeto del sistema de gestión, no una cartera de proyectos que gestionar |
| Inventario y devolución de activos | A.5.9, A.5.11 | 2 | No existen activos asignados a personas ni proceso de devolución |
| Etiquetado de información | A.5.13 | 1 | La clasificación se aplica por tipo de dato y por ubicación, no mediante etiquetado de documentos |
| Relación con proveedores | A.5.19 a A.5.23 | 5 | Los servicios empleados son gratuitos y se aceptan en sus términos publicados. No existe contrato negociado, ni acuerdo de nivel de servicio, ni capacidad de auditar al proveedor |
| Cumplimiento legal y contractual | A.5.31, A.5.32, A.5.33, A.5.36 | 4 | El proyecto no procesa datos reales ni opera comercialmente. La única obligación normativa considerada es el requisito 6.4.2 del estándar PCI DSS, que motiva el despliegue del cortafuegos y sí está atendida |
| Terminación de la relación y confidencialidad | A.6.5, A.6.6 | 2 | Misma razón que A.6.1: no existe relación laboral |
| Tecnológicos no aplicables al diseño vigente | A.8.4, A.8.14, A.8.18, A.8.30, A.8.34 | 5 | Acceso al código fuente por terceros, redundancia de instalaciones, uso de utilidades privilegiadas, desarrollo subcontratado y protección durante auditorías. Ninguno de los cuatro supuestos se presenta, y la redundancia de instalaciones se descarta de forma expresa en POL-004 sección 6.2 por restricción de presupuesto |
| **Total no evaluado** | — | **24** | 69 evaluados más 24 no evaluados suman los 93 controles del Anexo A |

---

## 9. Lo que esta matriz reconoce como no cubierto

Un auditor irá directamente a esta sección. Se presenta agrupada y sin atenuantes.

### 9.1 Vacíos reales

| Control | Vacío | Riesgo que genera | Tratamiento |
|---|---|---|---|
| A.8.7 · Pendiente | Sin protección contra código malicioso en los archivos que suben los usuarios | Carga de un archivo malicioso disfrazado de imagen de producto | Aceptado para el entorno de demostración. Registrado como R-09 en la matriz de riesgos |
| A.8.8 · componente pendiente del control parcial | Escaneo automatizado de dependencias no configurado; el directorio `.github/workflows/` está vacío | Una dependencia vulnerable puede permanecer sin detectar. Los componentes vulnerables representan el 12.36 % de las vulnerabilidades web | Pendiente de implementación antes de la presentación |

### 9.2 Definidos pero no ejecutados

Estos seis controles tienen procedimiento escrito y aprobado, y **ninguna evidencia de ejecución**. Es
la categoría más delicada de la matriz, porque es la que resulta más fácil de declarar como
implementada sin serlo.

| Control | Qué falta exactamente |
|---|---|
| A.5.26 | Simulacro de mesa: el plan de respuesta nunca se ha ejercitado |
| A.5.27 | Actas de revisión posterior: no ha habido incidentes reales que revisar |
| A.5.30 | Prueba de restauración que verifique los objetivos de 24 y 4 horas |
| A.6.3 | Primera edición de la capacitación. Métrica de personal capacitado: 0 % |
| A.8.10 | Rutina de purga y archivado en frío, no ejercida por antigüedad insuficiente del sistema |
| A.8.13 | Guiones de respaldo y de restauración, ausentes del repositorio |

### 9.3 Limitaciones declaradas de los controles parciales

Trece controles operan solo en parte. Cada uno declara aquí lo que le falta.

| Control | Lo que opera | Lo que falta |
|---|---|---|
| A.5.7 | Reglas propias y lista de rastreadores legítimos actualizable | Fuente externa de indicadores de compromiso |
| A.5.18 | Asignación de derechos por rol, con registro de quién y cuándo | Revisión periódica de accesos |
| A.5.29 | Servicio de respaldo estático que sostiene la inspección del cortafuegos si la aplicación cae | Procedimiento de restauración probado |
| A.5.35 | Rol de auditor implementado y ejercido por integrante distinto del operador | Revisión independiente externa al equipo |
| A.8.6 | Dimensionamiento explícito y ajuste de parámetros del núcleo | Monitoreo continuo de capacidad con alerta por umbral |
| A.8.8 | Parcheo automático del sistema operativo y ventana de 72 horas declarada | Auditoría automatizada de dependencias |
| A.8.11 | Ocultación de campos sensibles en el registro del cortafuegos | Enmascaramiento sistemático en interfaz e informes |
| A.8.15 | Registro en tres fuentes, plazos configurados y separación de privilegios verificable | Archivado en frío, no ejercido |
| A.8.20 | Cortafuegos de red con dos puertos expuestos y bloqueo automático | Mitigación de denegación de servicio en el borde, conforme a DA-001 |
| A.8.24 | TLS 1.3 en tránsito y cifrado de datos personales en reposo | Cifrado del respaldo y gestión formal del ciclo de vida de las claves |
| A.8.25 | Criterios de codificación segura y análisis estático configurado | Revisión de código por pares formalizada |
| A.8.29 | Pruebas automatizadas de autenticación y guiones de demostración del cortafuegos | Pruebas de intrusión con OWASP ZAP y Nmap |
| A.8.32 | Cambios bajo control de versiones y decisiones documentadas | Aprobación formal previa al despliegue |

## 10. Correspondencia con los vértices del triángulo

| Vértice | Controles que lo sostienen | Estado agregado |
|---|---|---|
| **Protección** | A.5.15, A.5.17, A.6.3, A.8.2, A.8.5, A.8.8, A.8.20 a A.8.24, A.8.26, A.8.28 | Sólido en su componente técnico; **el componente humano, A.6.3, permanece sin ejecutar** |
| **Detección** | A.5.7, A.8.15, A.8.16, A.8.12, A.8.17 | Operativo, con la operación de guardia ya definida en POL-003 |
| **Respuesta** | A.5.24 a A.5.30, A.8.13 | **Documentado por completo, verificado en ninguna parte.** Es el estado que esta matriz debe reflejar con exactitud |

El anexo del triángulo estableció que la propuesta original tenía el vértice de respuesta ausente. Tras
la incorporación de POL-002 a POL-006, ese vértice pasa de **ausente** a **definido**. No pasa a
**verificado**, y esa distinción es el hallazgo principal de esta matriz: alcanzar el estado verificado
exige ejecutar el simulacro y la prueba de restauración, que son las dos actividades pendientes de
mayor peso del proyecto.

## 11. Control de versiones

| Versión | Fecha | Autor | Cambio |
|---|---|---|---|
| 1.0 | 2026-09-21 | Nivel táctico — Arquitecto de Seguridad | Emisión inicial. Declaración de Aplicabilidad con 47 controles evaluados y estado honesto de cada uno |
</content>
