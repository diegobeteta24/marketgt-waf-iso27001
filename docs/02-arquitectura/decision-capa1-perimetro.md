# Registro de decisión de arquitectura · Capa 1 (Perímetro)

| | |
|---|---|
| **Identificador** | DA-001 |
| **Título** | Alcance real de la capa de perímetro en el entorno publicado |
| **Estado** | Aceptada |
| **Fecha** | 21 de septiembre de 2026 |
| **Responsable** | Equipo de proyecto — Nivel táctico (Arquitecto de Seguridad) |
| **Clasificación** | Uso interno del proyecto |

---

## 1. Contexto

El documento de propuesta del proyecto define un esquema de defensa en profundidad de seis
capas concéntricas. La primera de ellas, denominada *perímetro*, asigna a Cloudflare en su
modalidad gratuita las funciones de mitigación de denegación de servicio, red de entrega de
contenido, límite de tasa por dirección y filtrado de borde.

Al llevar la propuesta a un entorno publicado y verificable surgió una restricción que el
documento original no contemplaba, y que condiciona el alcance real de esa capa.

## 2. El problema

Cloudflare solo admite una zona en su plan gratuito cuando se le delegan por completo los
servidores de nombres de un dominio cuyo ápice se sitúe un nivel por debajo de una entrada de
la *Public Suffix List*. Las dos modalidades que permitirían prescindir de esa delegación
—la configuración por registro CNAME y la configuración de subdominio— están reservadas a los
planes Business y Enterprise, ambos de pago.

El proyecto opera bajo una restricción de costo cero que no admite excepciones. Se evaluaron
en consecuencia las alternativas de dominio gratuito disponibles, con los siguientes resultados:

| Alternativa | Resultado de la evaluación |
|---|---|
| `duckdns.org` | El proveedor admite únicamente registros de tipo A, AAAA y TXT. No admite registros NS, por lo que la delegación a Cloudflare es técnicamente imposible. |
| `us.kg` | El registro de `.kg` suspendió la resolución de la totalidad del espacio de nombres por abuso sistemático de terceros. El proveedor migró a otro sufijo y el registro quedó cerrado. |
| `dpdns.org` | Cloudflare rechaza la creación de la zona. Su copia interna de la *Public Suffix List* no incorpora aún esa entrada, y su actualización se produce con un desfase de entre uno y dos meses. |
| `eu.org` | Cumple todos los requisitos técnicos y Cloudflare la acepta, pero su aprobación es manual y su plazo declarado va de un día a ocho semanas. Excede el horizonte del proyecto. |
| Dominio gratuito del programa para estudiantes de GitHub | La verificación académica de una institución guatemalteca requiere revisión manual, con plazos reportados de entre dos días hábiles y cuatro semanas. |

Se descartaron asimismo los sufijos `nip.io` y `sslip.io`, que sí permitirían operar, por una
razón de seguridad y no de disponibilidad: al no figurar en la *Public Suffix List*, cualquier
otro nombre alojado bajo ellos podría declararse como parte confiante del mismo ámbito y
utilizar las credenciales de clave pública registradas por esta plataforma. Emplearlos habría
introducido una vulnerabilidad de aislamiento de origen en el propio mecanismo de autenticación
que el proyecto busca reforzar.

## 3. Decisión

Se publica el sistema bajo el nombre `marketgt.duckdns.org`, con certificado emitido por
Let's Encrypt, y **se declara la capa de perímetro como parcialmente implementada**.

Se descarta expresamente la opción de sustituir Cloudflare por una solución equivalente, así
como la de afirmar su presencia sin poder demostrarla ante una revisión.

## 4. Consecuencias

### 4.1 Lo que se conserva

Las capas dos a seis del esquema permanecen íntegras y verificables. En particular, el control
central de la propuesta —la inspección de capa 7 mediante ModSecurity y el OWASP Core Rule
Set— reside en la capa cuatro y no se ve afectado en modo alguno por esta decisión.

Conviene precisar algo que el documento original no distinguía: el conjunto de reglas
gestionado que Cloudflare ofrece en su plan gratuito **no incluye el OWASP Core Rule Set**;
cubre un repertorio acotado de vulnerabilidades conocidas. La detección de inyección SQL y de
secuencias de comandos en sitios cruzados que el proyecto demuestra se produce íntegramente en
la capa cuatro, sobre infraestructura propia. La ausencia de la capa uno no reduce, por tanto,
la capacidad de detección demostrada.

Se conserva igualmente el cifrado del transporte, mediante certificado de Let's Encrypt
renovado de forma automática, y la resistencia a la suplantación de sitio en la autenticación:
`duckdns.org` figura en la *Public Suffix List*, por lo que `marketgt.duckdns.org` constituye
un dominio registrable de pleno derecho y un identificador de parte confiante válido y aislado
para las credenciales de clave pública.

### 4.2 Lo que no se cubre

Las siguientes funciones quedan fuera del alcance del entorno publicado y así deben constar en
la evaluación del proyecto:

- Mitigación de denegación de servicio distribuida en el borde de la red.
- Entrega de contenido estático desde caché distribuida.
- Límite de tasa por dirección aplicado antes de alcanzar el servidor de origen.
- Filtrado de borde y contención de tráfico automatizado.
- Ocultamiento de la dirección del servidor de origen.

### 4.3 Mitigaciones adoptadas

La ausencia de contención en el borde se compensa de forma parcial en las capas inferiores,
sin que ello suponga equivalencia funcional:

| Función ausente | Mitigación en capas inferiores | Limitación de la mitigación |
|---|---|---|
| Límite de tasa en el borde | Limitadores nombrados en la aplicación y bloqueo automático por `fail2ban` tras cinco respuestas 403 del cortafuegos desde una misma dirección en diez minutos | Actúa una vez que el tráfico alcanzó el servidor; no protege frente a la saturación del enlace |
| Mitigación de denegación de servicio | Parámetros del núcleo frente a inundación de SYN y limitación de tasa del cortafuegos de red | Insuficiente ante un ataque distribuido de volumen apreciable |
| Ocultamiento del origen | Ninguna | La dirección del servidor es pública; se compensa manteniendo expuestos exclusivamente los puertos 80 y 443, verificado mediante descubrimiento de puertos desde el exterior |

## 5. Trazabilidad con la norma

La decisión afecta al control **A.8.20, Seguridad de redes**, que permanece implementado de
forma parcial. El control **A.8.21, Seguridad de los servicios de red**, y el control
**A.8.22, Segregación en redes**, se sostienen íntegramente en las capas dos y cuatro.

Conforme a lo que establece la norma ISO/IEC 27001:2022 sobre la Declaración de Aplicabilidad,
un control implementado de forma parcial debe registrarse como tal, con su justificación y su
riesgo residual. Este documento constituye esa justificación.

El riesgo residual derivado —interrupción del servicio por saturación del enlace— se acepta de
manera consciente, en atención a que el entorno publicado es de demostración académica, no
sostiene operación comercial y no custodia datos reales de personas.

## 6. Revisión

Esta decisión debe revisarse si se cumple cualquiera de las condiciones siguientes:

1. Se aprueba una de las solicitudes de dominio gratuito en curso, en cuyo caso la delegación
   a Cloudflare se completa y la capa uno queda implementada en su totalidad.
2. El proyecto evoluciona hacia un entorno de operación real, situación en la cual la capa de
   perímetro deja de ser opcional y su ausencia constituiría una deficiencia material.

---

## Anexo · Nota sobre el método

Las alternativas recogidas en la sección 2 se verificaron de forma directa y no por referencia
documental: se descargó la *Public Suffix List* vigente para comprobar la presencia efectiva de
cada sufijo, y se contrastaron las tablas de disponibilidad por plan publicadas por el
proveedor. Dos de las opciones inicialmente recomendadas en la fase de investigación resultaron
inviables al someterlas a esa comprobación, y una tercera se apoyaba en una fecha que no pudo
corroborarse en ninguna fuente.

Se deja constancia de ello porque ilustra un principio que el propio proyecto defiende: un
control que no puede verificarse no debe declararse implementado.
