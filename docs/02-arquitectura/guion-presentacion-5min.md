# Guion de presentación · 5 minutos

**Objetivo:** vender MarketGT, no explicarlo.
**Regla:** cada afirmación se demuestra en pantalla o no se dice.

---

## Antes de empezar · preparación (hacer 10 minutos antes, no en vivo)

| Pestaña | Qué abrir | Estado |
|---|---|---|
| 1 | Search Console de la empresa real, en **Rendimiento → Consultas** | Visible `p9bet login` |
| 2 | `marketgt.duckdns.org/demo-waf/consola` | Sesión iniciada |
| 3 | `marketgt.duckdns.org/seo/laboratorio` | Cargado con el ejemplo |
| 4 | `marketgt.duckdns.org/siem/tablero` | Recién recargado |

Probar los tres botones **antes**. Si algo falla en vivo, seguir hablando: el guion funciona igual.

---

## 0:00 – 0:40 · El gancho

> «Esta empresa vende cámaras de seguridad en Guatemala. Es real, y es donde trabajo.»

**[Pestaña 1 — señalar la pantalla]**

> «Estas son las búsquedas por las que Google la muestra. Aquí abajo: cámaras de seguridad, mantenimiento, instalación. Todo normal.
>
> Y aquí arriba: **p9bet login. Cuarenta y cinco veces.** Cero clics.
>
> p9bet es un casino en línea. Alguien metió contenido de apuestas en el sitio de mi empresa y Google lo está indexando. Lleva meses. Nadie se dio cuenta, porque cuando entrás al sitio se ve perfectamente normal.»

*(Pausa de un segundo.)*

> «Eso le pasa hoy a una empresa guatemalteca que vende, precisamente, seguridad.»

---

## 0:40 – 1:10 · El problema, en costo

> «Cuando esto le pasa a una tienda en línea, no pierde un sitio web. Pierde tres cosas:
>
> El **posicionamiento**, que costó años construir y Google castiga en semanas.
> La **confianza**, porque un cliente que ve tu dominio junto a un casino no vuelve.
> Y los **datos de sus clientes**, porque quien pudo meter contenido pudo leer la base.
>
> Las plataformas grandes resuelven esto con equipos de seguridad. **Una PyME guatemalteca no tiene ese equipo, ni ese presupuesto.**»

---

## 1:10 – 1:40 · Qué vendemos

> «MarketGT es una plataforma de comercio electrónico donde la seguridad **no es un añadido: es el producto.**
>
> El comerciante no configura nada, no contrata a nadie y no aprende nada. Vende. Nosotros ponemos seis capas de defensa debajo, conforme a la norma ISO 27001.
>
> Y les voy a demostrar tres cosas en dos minutos.»

---

## 1:40 – 3:40 · Las pruebas

### Prueba 1 · El ataque que nadie ve (45 s)

**[Pestaña 2 — consola de ataques]**

> «Esto no es una simulación. Este botón lanza una inyección SQL real contra nuestro propio servidor.»

**[Pulsar `UNION SELECT`]**

> «**403.** Bloqueado. Y no es una caja negra: aquí está la regla que se activó, del OWASP Core Rule Set, con su puntuación. El ataque nunca tocó la base de datos.»

### Prueba 2 · El caso real (50 s)

**[Pestaña 3 — laboratorio, pulsar *Intentar publicar*]**

> «Esto es una reseña con contenido de spam, como la que usaron contra mi empresa. Miren.»

**[Señalar el 31]**

> «Treinta y uno sobre un umbral de cinco. Ocho reglas: vocabulario de farmacia, de casino, enlaces acortados, texto escondido con CSS.
>
> **Y tres de esas reglas las escribimos nosotros.** OWASP no trae ni una sola regla de posicionamiento. Ese hueco lo llenamos nosotros.»

**[Señalar *cómo se vería publicado*]**

> «El texto oculto desapareció. El script desapareció. **No se publicó.**»

### Prueba 3 · Que alguien se entera (25 s)

**[Pestaña 4 — tablero, recargar]**

> «Y todo lo que acaban de ver ya está aquí. Con su regla, su hora y su dirección de origen.
>
> Porque bloquear un ataque y que nadie se entere **no es seguridad: es suerte.**»

---

## 3:40 – 4:20 · Por qué esto se vende

> «Tres razones por las que una PyME nos compra:
>
> **Primera: el precio.** Shopify cobra en dólares más comisión por venta. Para una tienda guatemalteca, eso es exposición cambiaria todos los meses.
>
> **Segunda: nadie más protege el posicionamiento.** Todas las plataformas protegen la transacción. Ninguna protege lo que le pasó a mi empresa, que es un ataque al activo más caro de un negocio pequeño: **aparecer en Google.**
>
> **Tercera: auditable.** Cada control tiene su evidencia y su trazabilidad al Anexo A de ISO 27001. Cuando a un comerciante le pregunten cómo protege los datos de sus clientes, tiene una respuesta documentada.»

---

## 4:20 – 5:00 · El cierre

> «Dos datos para terminar.
>
> **El costo de licenciamiento de todo esto es cero.** Cortafuegos, sistema operativo, base de datos, certificados, monitoreo: todo de código abierto o en modalidad gratuita permanente. Lo que se cobra es el servicio, no las herramientas.
>
> Y el segundo, que es del que estoy más orgulloso.»

**[Volver a la pestaña 4, ir a Métricas]**

> «Este panel tiene ocho métricas. **Cuatro dicen "sin datos".**
>
> Podríamos haber puesto un número en todas y se vería mejor. No lo hicimos, porque un panel que inventa cifras no sirve para decidir nada.
>
> **Lo que no se puede medir, se declara.** Eso es lo que separa un sistema de gestión de la seguridad de un tablero bonito.»

*(Pausa.)*

> «MarketGT: vendé en línea sin tener que aprender seguridad. Gracias.»

---

## Respuestas preparadas

**«¿Por qué no usar Shopify?»**
> «Shopify es mejor producto en casi todo, y lo decimos en el informe. Ganamos en tres cosas concretas: precio en quetzales, facturación local, y protección del posicionamiento, que ellos no cubren.»

**«¿Esto es más seguro que WordPress?»**
> «Sí, y esa comparación sí la sostenemos. El ecosistema de complementos de WordPress publica miles de vulnerabilidades al año. Nuestra superficie es mucho menor y tenemos un WAF delante que ellos no traen de fábrica.»

**«¿Y si alguien entra igual?»**
> «Asumimos que va a pasar: es la premisa del modelo que usamos. Por eso el registro de auditoría se monta en solo lectura para la aplicación. Si comprometen la tienda, **no pueden borrar la evidencia.**»

**«¿Cuánto costaría de verdad?»**
> «El servidor que lo corre son unos veinticinco dólares al mes. Las herramientas, cero. Lo que se cobra es la operación: alguien mirando el panel.»

---

## Si algo falla en vivo

No disculparse ni depurar en pantalla. Decir:

> «El entorno está publicado, pueden abrirlo desde su teléfono en **marketgt.duckdns.org**. Sigo con lo siguiente.»

Y seguir. **El guion funciona sin las demostraciones**; las demostraciones solo lo hacen más fuerte.
