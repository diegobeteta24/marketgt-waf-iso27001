# MarketGT — Plataforma de Comercio Electrónico con WAF bajo ISO/IEC 27001

> **Proyecto de curso — Seguridad y Auditoría de Sistemas**
> Universidad Mariano Gálvez de Guatemala · Sede El Naranjo
> Facultad de Ingeniería en Sistemas de Información · Ingeniería en Ciencias y Sistemas

| | |
|---|---|
| **Sitio publicado** | https://marketgt.duckdns.org |
| **Panel de seguridad** | https://marketgt.duckdns.org/siem (requiere cuenta de administrador o auditor) |
| **Alojamiento** | VM Ubuntu 24.04 en Microsoft Azure (México Central), crédito de Azure for Students |
| **Norma rectora** | ISO/IEC 27001:2022 (Anexo A) |
| **Estándares de apoyo** | ISO/IEC 27002:2022 · OWASP Top 10:2025 · OWASP CRS 4.x · PCI DSS v4.0 req. 6.4.2 · CIS Benchmark · NIST SP 800-61r3 · NIST SP 800-63B-4 |
| **Costo de licenciamiento** | Q0.00 |

## Integrantes

| Nombre | Carné |
|---|---|
| Diego Antonio Beteta García | 9490-22-12878 |
| Jonathan Rogelio Herrera Soto | 9490-22-11551 |
| Mario Roberto Rompich Yoc | 9490-17-1752 |

---

## 1. Qué es esto

MarketGT es una plataforma de comercio electrónico bajo modelo SaaS, orientada a pequeñas y medianas
empresas del mercado guatemalteco. Este repositorio contiene **la implementación real y desplegable**
del modelo de seguridad propuesto en los entregables del curso: no es una maqueta ni un documento,
es el sistema funcionando, con sus configuraciones, sus scripts de despliegue y sus evidencias de auditoría.

El activo que se protege son **los datos de los clientes**: credenciales, información personal e
información de pago.

## 2. Esquema de seguridad por capas (defensa en profundidad)

| Capa | Componente | Control implementado | Amenaza que mitiga |
|---|---|---|---|
| 1 · Perímetro | DuckDNS + Let's Encrypt (Cloudflare documentado, **implementación parcial**) | TLS válido; ver docs/02-arquitectura/decision-capa1-perimetro.md | Denegación de servicio y tráfico automatizado |
| 2 · Red | UFW + fail2ban | Solo 80/443 abiertos; SSH por llave; bloqueo tras intentos fallidos | Escaneo de puertos y fuerza bruta |
| 3 · Sistema operativo | Ubuntu Server LTS | Hardening CIS Benchmark, actualizaciones automáticas, auditoría con Lynis | Escalada de privilegios y servicios obsoletos |
| 4 · Servidor web y WAF | Nginx + ModSecurity 3 + OWASP CRS 4.x | Inspección de capa 7, puntuación de anomalía, TLS 1.3 | Inyección SQL, XSS e inclusión de archivos |
| 5 · Aplicación | Laravel | MFA (TOTP + passkeys), RBAC, validación de entradas, CSP y HSTS | Control de acceso roto y secuestro de sesión |
| 6 · Datos | MariaDB | Cifrado en reposo, respaldo diario AES-256 verificado, en el servidor fuera de todo contenedor | Exfiltración y divulgación de datos sensibles |
| Transversal | Panel SIEM propio | Ingesta del WAF, correlación, triaje, contención en el borde y 8 métricas medidas | Detección tardía de incidentes |

## 3. Triángulo de la ciberresiliencia

El anexo del proyecto detectó que la propuesta original cubría el vértice de **protección**,
dejaba **detección** parcial y **respuesta** ausente. Esta implementación cierra los tres:

| Vértice | Estado | Qué lo sostiene aquí |
|---|---|---|
| **Protección** | Implementado | Las seis capas del esquema anterior |
| **Detección** | Implementado | Panel SIEM con ingesta del audit log de ModSecurity, access log de Nginx y eventos de Laravel; reglas de correlación con umbrales; política de retención declarada |
| **Respuesta** | Implementado | Plan de respuesta a incidentes conforme a NIST SP 800-61r3, matriz de escalamiento, respaldo diario cifrado, prueba de restauración real (RTO medido: 2,9 s; metas RTO 4 h y RPO 24 h) |

## 4. Estructura del repositorio

```
.
├── app/                        Aplicación Laravel (tienda + MFA + panel SIEM)
├── infra/
│   ├── docker/                 docker-compose y Dockerfiles del stack completo
│   ├── modsecurity/            Reglas propias y exclusiones del Core Rule Set
│   ├── nginx/                  Configuración TLS, cabeceras de seguridad y proxy
│   └── scripts/                Bootstrap de la VM, hardening CIS, respaldos, restauración
├── docs/
│   ├── 01-entregables-previos/ Los cuatro documentos del curso ya entregados
│   ├── 02-arquitectura/        Decisiones de arquitectura y diagramas
│   ├── 03-politicas/           Política de seguridad, plan de respuesta a incidentes, matriz de escalamiento
│   ├── 04-evidencias/          Matriz de controles ISO 27001 y trazabilidad
│   └── 05-segundo-entregable/  Documento, presentación, guion de 5 min y acceso para el evaluador
├── evidencias/                 Reportes archivados: OWASP ZAP, nmap, Lynis, testssl, composer audit
└── .github/workflows/          Escaneos automatizados de seguridad y de cadena de suministro
```

## 5. Demostraciones en vivo

| Qué | Dónde | Qué se ve |
|---|---|---|
| WAF bloqueando ataques | /demo-waf/consola | Ataques reales contra el servidor: 403 con la regla y la puntuación de anomalía |
| Reglas propias de SEO | /demo-waf/consola, /seo/laboratorio | Googlebot falso, cloaking y spam de contenido bloqueados por reglas 15000–15099 |
| Consultas envenenadas | /seo/consultas | Analizador de Search Console sobre el caso real (dominio anonimizado) |
| Panel SIEM | /siem/tablero, /siem/alertas | Eventos del WAF, tráfico hostil real de Internet, triaje y acciones masivas |
| Triángulo | /siem/metricas | Las 8 métricas calculadas, cada una con su origen |
| Continuidad y capacitación | /siem/continuidad, /siem/capacitacion | Restauraciones reales del respaldo y asistencias de POL-006 |
| MFA | Configuración → Seguridad | TOTP (RFC 6238), passkeys (WebAuthn) y códigos de recuperación |

Acceso del evaluador: docs/05-segundo-entregable/Acceso-MarketGT.docx. El guion está en
docs/02-arquitectura/guion-presentacion-5min.md.

## 6. Estado

Desplegado y operativo. Suite de pruebas: 118 pruebas, 502 aserciones.

**Despliegue en el servidor** (todo con sudo):

```bash
cd /opt/marketgt
sudo bash infra/scripts/04-desplegar.sh marketgt.duckdns.org
sudo bash infra/scripts/llenar-metricas.sh
```

Presentación: **sábado 26 de septiembre de 2026**.

---

_El costo de licenciamiento de la totalidad de las herramientas empleadas asciende a Q0.00.
Todas son de código abierto o se utilizan en su modalidad gratuita permanente._
