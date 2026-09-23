# Guion de presentación · 5 minutos

**Objetivo:** vender MarketGT, no explicarlo.
**Regla:** cada afirmación se demuestra en pantalla o no se dice.
**Todo lo que se muestra es un clic.** No hay nada que escribir en vivo.

---

## Antes de empezar · 15 minutos antes, no en vivo

### 1. En el servidor, copiar y pegar

```bash
cd /opt/marketgt
sudo bash infra/scripts/llenar-metricas.sh
```

Recolecta parches, hace un respaldo cifrado, ejecuta una restauración real y deja el triángulo
con datos del día. Tarda unos dos minutos y termina solo, imprimiendo el triángulo al final.

### 2. En el navegador, revisar lo pendiente

`https://marketgt.duckdns.org/siem/alertas` → *Acciones masivas*:

- Si hay más de 40 pendientes: **Marcar las N pendientes** → *En triaje* → nota con lo que se
  revisó → **Aplicar**.
- Si hay pocas: **Marcar las visibles** → *En triaje* → **Aplicar**.
- Marcar también **Incluir las de demostración** y repetir si queda alguna.

Las críticas tienen 15 minutos de plazo según el plan de respuesta. Hacerlo cerca de la hora.

### 3. Pestañas abiertas, en este orden, con sesión de administrador

| Pestaña | URL | Qué dejar listo |
|---|---|---|
| 1 | Diapositiva 5 (tabla de consultas) | En pantalla completa |
| 2 | `https://marketgt.duckdns.org/demo-waf/consola` | Cargada |
| 3 | `https://marketgt.duckdns.org/seo/laboratorio` | Cargado con el ejemplo |
| 4 | `https://marketgt.duckdns.org/siem/tablero` | Cargado |
| 5 | `https://marketgt.duckdns.org/siem/metricas` | Cargado, triángulo en verde |
| 6 | `https://marketgt.duckdns.org/siem/continuidad` | Cargado |

La pestaña 1 es la diapositiva y no la Consola de búsqueda real, para no mostrar el dominio de la
empresa. Si se decide mostrar la real, es la misma historia.

**Probar los botones una vez antes.** Si algo falla en vivo, seguir hablando: el guion funciona igual.

---

## 0:00 – 0:35 · El gancho

**[Pestaña 1]**

> «Esta es una empresa guatemalteca que vende cámaras de seguridad. Es real: es donde trabajo.
>
> Estas son las búsquedas por las que Google la muestra. Cámaras de seguridad, mantenimiento. Normal.
>
> Y aquí: **p9bet login. Cuarenta y cinco veces. Cero clics.** p9bet es un casino.
>
> Alguien metió contenido de apuestas en el sitio y Google lo indexa. Nadie se dio cuenta, porque
> al entrar el sitio se ve perfecto. Le pasa, hoy, a una empresa que vende seguridad.»

---

## 0:35 – 1:05 · Qué vendemos

> «Una PyME guatemalteca no tiene equipo de seguridad ni presupuesto para tenerlo.
>
> **MarketGT es una tienda en línea donde la seguridad no es un añadido: es el producto.** El
> comerciante vende; nosotros ponemos seis capas de defensa debajo, conforme a ISO 27001.
>
> Cuatro pruebas en tres minutos.»

---

## 1:05 – 1:45 · Prueba 1 · Lo que todos paran, y lo que nadie para

**[Pestaña 2 · consola]**

**[Pulsar «UNION SELECT (extracción de credenciales)»]**

> «Una inyección SQL real contra nuestro servidor. **403.** Aquí está la regla del OWASP que la
> cortó y su puntuación. Esto lo para cualquier WAF.»

**[Pulsar «Rastreador falsificado (Googlebot falso)»]**

> «Esto no. Un bot que se hace pasar por Google para ver una versión distinta del sitio: así se
> esconde lo que vieron hace un minuto. **Ningún WAF trae esta regla. La escribimos nosotros.**»

---

## 1:45 – 2:35 · Prueba 2 · El caso real, detenido

**[Pestaña 3 · laboratorio, pulsar «Intentar publicar»]**

> «Una reseña con spam, como la que usaron contra la empresa.»

**[Señalar la puntuación contra el umbral de 5]**

> «Muy por encima del umbral. Vocabulario de casino, enlaces acortados, texto escondido con CSS.
> Varias de estas reglas son nuestras.»

**[Señalar «cómo se vería publicado»]**

> «El texto oculto y el script desaparecieron. **No se publicó.**»

---

## 2:35 – 3:10 · Prueba 3 · Alguien se entera

**[Pestaña 4 · tablero, recargar]**

> «Lo que acaban de ver ya está aquí: regla, hora, dirección de origen.
>
> Y no solo lo nuestro. Este dominio no lo conoce nadie, y aun así recibe tráfico hostil de
> Internet todo el día: **cientos de alertas en tres días**, de bots que nos encontraron solos.
> Bloquear un ataque y que nadie se entere no es seguridad: es suerte.»

---

## 3:10 – 4:15 · Prueba 4 · El triángulo, medido

**[Pestaña 5 · métricas]**

> «Protección, detección y respuesta. Las tres en verde, y **cada cifra sale de algo que pasó**.
>
> Parches: el cien por ciento aplicado dentro de 72 horas, leído de los registros del servidor.
> Detección: minuto y medio de media. Falsos positivos: por debajo del dos por ciento.»

**[Pestaña 6 · continuidad]**

> «Y la que nadie mide. Recuperación: esta mañana el sistema tomó un respaldo cifrado con AES-256,
> lo restauró en una base aparte y comprobó tabla por tabla. **Treinta y tres tablas, más de
> cuatro mil filas, en tres segundos.**
>
> No es la configuración del respaldo. Es una restauración que se ejecutó.»

---

## 4:15 – 5:00 · El cierre

> «Tres razones para comprarlo.
>
> **Precio:** el licenciamiento de todo esto cuesta cero; se cobra el servicio, en quetzales.
>
> **Protege lo que nadie protege:** todas las plataformas cuidan el pago. Ninguna cuida lo que le
> pasó a esta empresa, que es el activo más caro de un negocio pequeño: aparecer en Google.
>
> **Es auditable:** cada control tiene su evidencia y su lugar en el Anexo A de ISO 27001.»

*(Pausa.)*

> «MarketGT: vendé en línea sin tener que aprender seguridad. Gracias.»

---

## Respuestas preparadas

**«¿Por qué había tantas alertas críticas?»** — la mejor pregunta que pueden hacer.
> «Porque el panel las marcaba mal, y nos dimos cuenta mirándolo. El OWASP etiqueta casi todas sus
> reglas como críticas, pero esa etiqueta es un peso, no un veredicto. Cada sondeo que el WAF
> registraba sin bloquear salía crítico: ochocientas en dos días. Lo recalibramos. Eso es operar
> un sistema, no solo instalarlo.»

**«¿El respaldo es externo?»**
> «Está en el mismo servidor, fuera del volumen de la base y de todos los contenedores: si toman la
> tienda, no alcanzan los respaldos. No protege contra perder la máquina entera; una copia fuera
> del servidor cuesta dinero y la dejamos declarada como limitación.»

**«¿La cobertura de triaje no la ajustaron para que salga verde?»**
> «La medimos contra los plazos de nuestro plan de respuesta: quince minutos una crítica, una hora
> una alta. Antes exigía el cien por cien al instante, y una alerta llegada hace un minuto contaba
> igual que una olvidada tres días. Lo que es falla sigue siéndolo.»

**«¿Por qué no usar Shopify?»**
> «Shopify es mejor producto en casi todo. Ganamos en tres cosas: precio en quetzales, facturación
> local y protección del posicionamiento, que ellos no cubren.»

**«¿Y si alguien entra igual?»**
> «Asumimos que va a pasar. El registro de auditoría se monta en solo lectura para la aplicación: si
> comprometen la tienda, no pueden borrar la evidencia.»

**«¿Cuánto costaría de verdad?»**
> «El servidor, unos cincuenta dólares al mes. Las herramientas, cero. Se cobra la operación.»

---

## Si algo falla en vivo

No disculparse ni depurar en pantalla. Decir:

> «El entorno está publicado: pueden abrirlo desde su teléfono en **marketgt.duckdns.org**. Sigo.»

Si la consola no carga, la misma batería de ataques sale desde la terminal:

```bash
bash infra/scripts/demo-waf.sh https://marketgt.duckdns.org
```

**El guion funciona sin las demostraciones**; las demostraciones solo lo hacen más fuerte.
