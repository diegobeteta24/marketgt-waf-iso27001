/**
 * Genera la presentación del Segundo Entregable (7 diapositivas).
 *
 * Fondo claro deliberado: un proyector de aula lava los fondos oscuros y el
 * contraste se pierde. Todo el texto queda sobre blanco o sobre bandas de
 * color sólido con texto blanco, nunca gris sobre gris.
 */
const P = require('pptxgenjs');

// ── Paleta ───────────────────────────────────────────────────────────────────
const TINTA  = '141C28';  // texto principal
const TENUE  = '4A5568';  // texto secundario
const MARINO = '0F2942';  // banda de título
const AZUL   = '0F4C81';  // acento
const ROJO   = 'B32424';  // antes / amenaza
const VERDE  = '1F6B42';  // después / control
const AMBAR  = '8A5A00';  // advertencia
const PAPEL  = 'F4F6F9';  // fondo de bloque
const BORDE  = 'C8D0DA';

const F = 'Calibri';
const ANCHO = 10, ALTO = 5.625;

const pres = new P();
pres.layout = 'LAYOUT_16x9';
pres.author = 'Beteta Garcia, Herrera Soto y Rompich Yoc';
pres.company = 'Universidad Mariano Galvez de Guatemala';
pres.title = 'MarketGT - Segundo Entregable';

let contador = 0;

/** Banda superior con número de sección, título y subtítulo. */
function banda(s, numero, titulo, subtitulo) {
  s.addShape(pres.ShapeType.rect, { x: 0, y: 0, w: ANCHO, h: 0.92, fill: { color: MARINO } });
  s.addShape(pres.ShapeType.rect, { x: 0, y: 0.92, w: ANCHO, h: 0.055, fill: { color: AZUL } });
  if (numero) {
    s.addText(numero, {
      x: 0.35, y: 0.14, w: 0.5, h: 0.62, fontFace: F, fontSize: 30, bold: true,
      color: '6FA8DC', align: 'center', valign: 'middle',
    });
  }
  s.addText(titulo, {
    x: numero ? 0.9 : 0.42, y: subtitulo ? 0.13 : 0.2, w: 8.8, h: subtitulo ? 0.4 : 0.55,
    fontFace: F, fontSize: 23, bold: true, color: 'FFFFFF', valign: 'middle',
  });
  if (subtitulo) {
    s.addText(subtitulo, {
      x: numero ? 0.92 : 0.44, y: 0.52, w: 8.8, h: 0.32,
      fontFace: F, fontSize: 12.5, color: 'A9C4DE', valign: 'middle',
    });
  }
}

/** Pie con el número de diapositiva. */
function pie(s) {
  contador += 1;
  s.addText(contador + ' / 7', {
    x: 9.0, y: 5.26, w: 0.75, h: 0.26,
    fontFace: F, fontSize: 9, color: '9AA5B1', align: 'right',
  });
  s.addText('MarketGT  ·  Seguridad y Auditoria de Sistemas  ·  UMG', {
    x: 0.42, y: 5.26, w: 6, h: 0.26, fontFace: F, fontSize: 9, color: '9AA5B1',
  });
}

/** Rótulo de bloque: barra de color con texto blanco. */
function rotulo(s, texto, x, y, w, color) {
  s.addShape(pres.ShapeType.rect, { x, y, w, h: 0.32, fill: { color } });
  s.addText(texto, {
    x: x + 0.12, y, w: w - 0.24, h: 0.32,
    fontFace: F, fontSize: 11, bold: true, color: 'FFFFFF', valign: 'middle', charSpacing: 0.6,
  });
}

/** Viñetas compactas. */
function vinetas(s, items, opc) {
  s.addText(items.map(function (t) {
    return { text: t, options: { bullet: { code: '25AA' }, breakLine: true } };
  }), {
    x: opc.x, y: opc.y, w: opc.w, h: opc.h,
    fontFace: F, fontSize: opc.tamano || 12, color: opc.color || TINTA,
    lineSpacingMultiple: opc.interlineado || 1.22, valign: 'top',
  });
}

/** Franja de cierre a lo ancho, con texto blanco. */
function franja(s, texto, y, color, tamano) {
  s.addShape(pres.ShapeType.rect, { x: 0.42, y, w: 9.16, h: 0.54, fill: { color } });
  s.addText(texto, {
    x: 0.62, y, w: 8.76, h: 0.54,
    fontFace: F, fontSize: tamano || 12.5, bold: true, color: 'FFFFFF', valign: 'middle',
  });
}

function celda(t, o) {
  o = o || {};
  return {
    text: t,
    options: {
      fontFace: F, fontSize: o.tamano || 10, bold: o.negrita || false,
      color: o.color || TINTA, fill: o.fondo ? { color: o.fondo } : undefined,
      align: o.align || 'left', valign: 'middle',
    },
  };
}

function encabezados(textos, color) {
  return textos.map(function (t) {
    return celda(t, { negrita: true, color: 'FFFFFF', fondo: color || MARINO, tamano: 10 });
  });
}

// ═════════════════════════════════════════════════════════════════════════════
//  1 · Portada
// ═════════════════════════════════════════════════════════════════════════════
{
  const s = pres.addSlide();
  s.background = { color: MARINO };
  s.addShape(pres.ShapeType.rect, { x: 0, y: 0, w: 0.22, h: ALTO, fill: { color: AZUL } });

  s.addText('Universidad Mariano Galvez de Guatemala', {
    x: 0.7, y: 0.5, w: 8.8, h: 0.3, fontFace: F, fontSize: 13, color: 'A9C4DE',
  });
  s.addText('Facultad de Ingenieria en Sistemas de Informacion   ·   Seguridad y Auditoria de Sistemas', {
    x: 0.7, y: 0.8, w: 8.8, h: 0.3, fontFace: F, fontSize: 11, color: '7F9DBC',
  });

  s.addText('MarketGT', {
    x: 0.7, y: 1.45, w: 8.8, h: 0.75, fontFace: F, fontSize: 46, bold: true, color: 'FFFFFF',
  });
  s.addText('Comercio electronico con seguridad gestionada para la pequena empresa', {
    x: 0.7, y: 2.2, w: 8.6, h: 0.42, fontFace: F, fontSize: 16, color: 'CFE0EF',
  });

  s.addShape(pres.ShapeType.rect, { x: 0.72, y: 2.82, w: 1.6, h: 0.045, fill: { color: AZUL } });

  s.addText('Segundo entregable del proyecto de curso', {
    x: 0.7, y: 3.0, w: 8.8, h: 0.3, fontFace: F, fontSize: 12.5, bold: true, color: '6FA8DC',
  });

  const equipo = [
    ['Diego Antonio Beteta Garcia', '9490-22-12878'],
    ['Jonathan Rogelio Herrera Soto', '9490-22-11551'],
    ['Mario Roberto Rompich Yoc', '9490-17-1752'],
  ];
  equipo.forEach(function (par, i) {
    s.addText([
      { text: par[0], options: { bold: true } },
      { text: '     ' + par[1], options: { color: '9FB8CE' } },
    ], { x: 0.7, y: 3.52 + i * 0.32, w: 5.6, h: 0.28, fontFace: F, fontSize: 12, color: 'FFFFFF' });
  });

  s.addShape(pres.ShapeType.rect, { x: 6.55, y: 3.45, w: 3.0, h: 1.05, fill: { color: '17395C' } });
  s.addText([
    { text: 'Entorno publicado\n', options: { fontSize: 10, color: '9FB8CE' } },
    { text: 'marketgt.duckdns.org', options: { fontSize: 14, bold: true, color: 'FFFFFF' } },
  ], { x: 6.7, y: 3.45, w: 2.8, h: 1.05, fontFace: F, valign: 'middle' });

  s.addText('Guatemala, 26 de septiembre de 2026', {
    x: 0.7, y: 4.85, w: 6, h: 0.3, fontFace: F, fontSize: 11, color: '7F9DBC',
  });
  contador += 1;
}

// ═════════════════════════════════════════════════════════════════════════════
//  2 · Modelo de Negocio
// ═════════════════════════════════════════════════════════════════════════════
{
  const s = pres.addSlide();
  banda(s, '1', 'Modelo de Negocio',
    'Software como servicio: el comerciante contrata la tienda y la seguridad que la protege, indivisibles');

  rotulo(s, 'EL SEGMENTO', 0.42, 1.2, 4.5, AZUL);
  s.addShape(pres.ShapeType.rect, { x: 0.42, y: 1.52, w: 4.5, h: 2.62, fill: { color: PAPEL }, line: { color: BORDE, width: 1 } });
  vinetas(s, [
    'PyME guatemalteca formalmente constituida',
    'De 1 a 20 empleados',
    'Vende hoy por redes sociales, sin tienda propia',
    'Cero personal dedicado a TI o a seguridad',
    'Presupuesto tecnologico menor a Q1,000 al mes',
    'Obligada al regimen de factura electronica (FEL)',
    'Cobra por transferencia, deposito y contra entrega',
  ], { x: 0.62, y: 1.66, w: 4.15, h: 2.4, tamano: 11.5 });

  rotulo(s, 'POR QUE ESTE SEGMENTO', 5.08, 1.2, 4.5, VERDE);
  s.addShape(pres.ShapeType.rect, { x: 5.08, y: 1.52, w: 4.5, h: 2.62, fill: { color: PAPEL }, line: { color: BORDE, width: 1 } });
  s.addText([
    { text: 'Exposicion.  ', options: { bold: true, color: MARINO } },
    { text: 'Publica una aplicacion en Internet con la misma superficie de ataque que una empresa grande, sin ninguna de sus capacidades defensivas.\n\n', options: {} },
    { text: 'Incentivo.  ', options: { bold: true, color: MARINO } },
    { text: 'Las plataformas internacionales cobran en dolares mas comision por venta: exposicion cambiaria mensual sobre margenes estrechos.\n\n', options: {} },
    { text: 'Cumplimiento.  ', options: { bold: true, color: MARINO } },
    { text: 'La factura electronica y las obligaciones sobre datos personales aplican sin importar el tamano. Una plataforma extranjera no las contempla.', options: {} },
  ], { x: 5.28, y: 1.66, w: 4.15, h: 2.4, fontFace: F, fontSize: 11, color: TINTA, lineSpacingMultiple: 1.05, valign: 'top' });

  franja(s, 'La propuesta de valor no es la tienda: es que la seguridad deje de ser responsabilidad de quien no puede asumirla.', 4.38, MARINO);
  pie(s);
}

// ═════════════════════════════════════════════════════════════════════════════
//  3 · Problema de Seguridad · ANTES
// ═════════════════════════════════════════════════════════════════════════════
{
  const s = pres.addSlide();
  banda(s, '2', 'Problema de Seguridad   ·   ANTES de la implementacion',
    'Sistemas (hardware y software) e Informacion');

  rotulo(s, 'SISTEMAS  ·  HARDWARE Y SOFTWARE', 0.42, 1.18, 4.5, ROJO);
  s.addText([
    { text: 'Hardware.  ', options: { bold: true, color: MARINO } },
    { text: 'Infraestructura virtualizada de un tercero: no hay control fisico del equipo. El perimetro deja de ser una puerta y pasa a ser la interfaz de red publicada.\n', options: {} },
    { text: 'Software.  ', options: { bold: true, color: MARINO } },
    { text: 'Aplicacion web accesible 24/7, sin autenticacion previa en el catalogo publico.', options: {} },
  ], { x: 0.52, y: 1.58, w: 4.3, h: 1.12, fontFace: F, fontSize: 10.5, color: TINTA, lineSpacingMultiple: 1.0, valign: 'top' });

  s.addTable([
    encabezados(['Vulnerabilidad web', 'Frecuencia'], ROJO),
    [celda('Cross-Site Scripting (XSS)'), celda('14.69 %', { align: 'right', negrita: true, color: ROJO })],
    [celda('Componentes desactualizados'), celda('12.36 %', { align: 'right', negrita: true, color: ROJO })],
    [celda('Autenticacion debil'), celda('9.25 %', { align: 'right', negrita: true, color: ROJO })],
    [celda('Inyeccion SQL'), celda('5.55 %', { align: 'right', negrita: true, color: ROJO })],
  ], {
    x: 0.42, y: 2.78, w: 4.5, colW: [3.25, 1.25], rowH: 0.29,
    border: { type: 'solid', color: BORDE, pt: 0.75 },
  });

  rotulo(s, 'INFORMACION EN CUSTODIA', 5.08, 1.18, 4.5, ROJO);
  s.addTable([
    encabezados(['Categoria', 'Impacto si se divulga'], ROJO),
    [celda('Credenciales y segundo factor'), celda('CRITICO', { negrita: true, color: ROJO, align: 'center' })],
    [celda('Medios de pago'), celda('CRITICO', { negrita: true, color: ROJO, align: 'center' })],
    [celda('Datos personales de clientes'), celda('ALTO', { negrita: true, color: AMBAR, align: 'center' })],
    [celda('Catalogo, precios e historial'), celda('MEDIO', { negrita: true, color: TENUE, align: 'center' })],
  ], {
    x: 5.08, y: 1.58, w: 4.5, colW: [2.95, 1.55], rowH: 0.3,
    border: { type: 'solid', color: BORDE, pt: 0.75 },
  });

  s.addShape(pres.ShapeType.rect, { x: 5.08, y: 3.25, w: 4.5, h: 0.82, fill: { color: PAPEL }, line: { color: BORDE, width: 1 } });
  s.addText([
    { text: 'La informacion comprometida no es del comerciante: ', options: { bold: true, color: MARINO } },
    { text: 'es de sus clientes, que no decidieron contratar el servicio ni pueden evaluar su seguridad.', options: {} },
  ], { x: 5.24, y: 3.25, w: 4.2, h: 0.82, fontFace: F, fontSize: 10.5, color: TINTA, valign: 'middle' });

  franja(s, 'Estado inicial:  sin inspeccion de trafico  ·  solo contrasena  ·  sin monitoreo  ·  sin plan de respuesta  ·  un ataque exitoso no deja rastro', 4.32, ROJO, 11.5);
  pie(s);
}

// ═════════════════════════════════════════════════════════════════════════════
//  4 · Clasificación de Vulnerabilidad o Amenaza
// ═════════════════════════════════════════════════════════════════════════════
{
  const s = pres.addSlide();
  banda(s, '3', 'Clasificacion de Vulnerabilidad o Amenaza',
    'Por naturaleza tecnica, categoria OWASP Top 10:2025, vector, criticidad y principio afectado');

  s.addTable([
    encabezados(['Amenaza', 'Naturaleza', 'OWASP 2025', 'Vector', 'Criticidad', 'C-I-D']),
    [celda('Inyeccion SQL'), celda('Validacion de entrada'), celda('A05 Inyeccion'), celda('Remoto no autenticado'), celda('CRITICA', { negrita: true, color: ROJO, align: 'center' }), celda('C  I  D', { align: 'center' })],
    [celda('Autenticacion debil'), celda('Control de acceso'), celda('A07 Autenticacion'), celda('Remoto no autenticado'), celda('CRITICA', { negrita: true, color: ROJO, align: 'center' }), celda('C  I', { align: 'center' })],
    [celda('Cross-Site Scripting'), celda('Validacion de salida'), celda('A05 Inyeccion'), celda('Remoto, via usuario'), celda('ALTA', { negrita: true, color: AMBAR, align: 'center' }), celda('C  I', { align: 'center' })],
    [celda('Componentes vulnerables'), celda('Cadena de suministro'), celda('A03 Cadena de sum.'), celda('Remoto, dependencia'), celda('ALTA', { negrita: true, color: AMBAR, align: 'center' }), celda('C  I  D', { align: 'center' })],
    [celda('Configuracion insegura'), celda('Configuracion'), celda('A02 Config. incorrecta'), celda('Remoto'), celda('MEDIA', { negrita: true, color: TENUE, align: 'center' }), celda('C  I', { align: 'center' })],
    [
      celda('Envenenamiento SEO', { negrita: true, fondo: 'FDF0E6' }),
      celda('Integridad de contenido y abuso de reputacion', { fondo: 'FDF0E6' }),
      celda('Sin cobertura', { negrita: true, color: ROJO, fondo: 'FDF0E6' }),
      celda('Remoto, via inyeccion', { fondo: 'FDF0E6' }),
      celda('ALTA', { negrita: true, color: AMBAR, align: 'center', fondo: 'FDF0E6' }),
      celda('I', { align: 'center', fondo: 'FDF0E6' }),
    ],
  ], {
    x: 0.42, y: 1.2, w: 9.16, colW: [1.75, 1.72, 1.65, 1.82, 1.22, 1.0], rowH: 0.36,
    border: { type: 'solid', color: BORDE, pt: 0.75 }, autoPage: false,
  });

  s.addShape(pres.ShapeType.rect, { x: 0.42, y: 3.92, w: 9.16, h: 0.72, fill: { color: PAPEL }, line: { color: AMBAR, width: 1.5 } });
  s.addText([
    { text: 'La fila resaltada es el hallazgo del proyecto.  ', options: { bold: true, color: AMBAR } },
    { text: 'El envenenamiento de posicionamiento no aparece en el OWASP Top 10 ni existe conjunto de reglas publico que lo cubra: OWASP clasifica la ', options: {} },
    { text: 'via de entrada', options: { italic: true } },
    { text: ' del ataque, no el ', options: {} },
    { text: 'objetivo', options: { italic: true } },
    { text: ' de quien busca posicionar contenido ajeno en un dominio con reputacion.', options: {} },
  ], { x: 0.62, y: 3.92, w: 8.76, h: 0.72, fontFace: F, fontSize: 10.5, color: TINTA, valign: 'middle' });

  s.addText('C = Confidencialidad   ·   I = Integridad   ·   D = Disponibilidad          Criticidad = probabilidad observada x impacto sobre el segmento objetivo', {
    x: 0.42, y: 4.74, w: 9.16, h: 0.28, fontFace: F, fontSize: 9.5, color: TENUE, italic: true,
  });
  pie(s);
}

// ═════════════════════════════════════════════════════════════════════════════
//  5 · Solución Tecnológica · CASO PRÁCTICO
// ═════════════════════════════════════════════════════════════════════════════
{
  const s = pres.addSlide();
  banda(s, '4', 'Solucion Tecnologica   ·   CASO PRACTICO',
    'Seis capas de defensa en profundidad, y el caso real que definio las reglas propias');

  rotulo(s, 'LAS SEIS CAPAS', 0.42, 1.18, 4.32, AZUL);
  s.addTable([
    [celda('1   Perimetro', { negrita: true }), celda('Cloudflare  ·  mitigacion de denegacion de servicio', { tamano: 9.5 })],
    [celda('2   Red', { negrita: true }), celda('UFW y fail2ban  ·  solo 80 y 443 abiertos', { tamano: 9.5 })],
    [celda('3   Sistema operativo', { negrita: true }), celda('Ubuntu con endurecimiento CIS', { tamano: 9.5 })],
    [celda('4   Cortafuegos web', { negrita: true, color: AZUL }), celda('ModSecurity 3 + OWASP CRS 4.29', { tamano: 9.5, negrita: true, color: AZUL })],
    [celda('5   Aplicacion', { negrita: true }), celda('Laravel  ·  TOTP + WebAuthn  ·  roles', { tamano: 9.5 })],
    [celda('6   Datos', { negrita: true }), celda('MariaDB cifrada  ·  respaldo externo', { tamano: 9.5 })],
  ], {
    x: 0.42, y: 1.58, w: 4.32, colW: [1.42, 2.9], rowH: 0.295,
    border: { type: 'solid', color: BORDE, pt: 0.75 }, fill: { color: 'FFFFFF' },
  });

  s.addText([
    { text: 'La red entre el cortafuegos y la aplicacion se declara interna: ', options: { bold: true, color: MARINO } },
    { text: 'no existe ninguna ruta que alcance la aplicacion sin atravesar el WAF, y eso se verifica desde fuera.', options: {} },
  ], { x: 0.42, y: 3.42, w: 4.32, h: 0.62, fontFace: F, fontSize: 10, color: TINTA, valign: 'top' });

  rotulo(s, 'CASO PRACTICO  ·  EMPRESA REAL, DOMINIO ANONIMO', 4.98, 1.18, 4.6, ROJO);
  s.addText('Consola de busqueda de una empresa guatemalteca de videovigilancia:', {
    x: 4.98, y: 1.56, w: 4.6, h: 0.24, fontFace: F, fontSize: 10, color: TENUE, italic: true,
  });
  s.addTable([
    encabezados(['Consulta que posiciona el dominio', 'Impr.', 'Clics'], MARINO),
    [celda('camaras de seguridad guatemala', { color: VERDE }), celda('8', { align: 'center' }), celda('1', { align: 'center' })],
    [celda('p9bet login', { negrita: true, color: ROJO, fondo: 'FBEAEA' }), celda('45', { align: 'center', negrita: true, fondo: 'FBEAEA' }), celda('0', { align: 'center', negrita: true, fondo: 'FBEAEA' })],
    [celda('0016bet', { negrita: true, color: ROJO, fondo: 'FBEAEA' }), celda('13', { align: 'center', fondo: 'FBEAEA' }), celda('0', { align: 'center', fondo: 'FBEAEA' })],
    [celda('96n.com', { negrita: true, color: ROJO, fondo: 'FBEAEA' }), celda('11', { align: 'center', fondo: 'FBEAEA' }), celda('1', { align: 'center', fondo: 'FBEAEA' })],
    [celda('kmj888', { negrita: true, color: ROJO, fondo: 'FBEAEA' }), celda('10', { align: 'center', fondo: 'FBEAEA' }), celda('0', { align: 'center', fondo: 'FBEAEA' })],
  ], {
    x: 4.98, y: 1.84, w: 4.6, colW: [3.0, 0.8, 0.8], rowH: 0.28,
    border: { type: 'solid', color: BORDE, pt: 0.75 },
  });

  s.addText([
    { text: 'Diagnostico.  ', options: { bold: true, color: MARINO } },
    { text: 'Muchas impresiones y cero clics: el buscador muestra el dominio por contenido que no le pertenece. Invisible para el dueno, porque el sitio se ve normal.\n', options: {} },
    { text: 'Respuesta.  ', options: { bold: true, color: MARINO } },
    { text: '24 reglas propias en el rango 15000-15099: rastreador falsificado, contenido diferenciado, inyeccion de enlaces, redireccion abierta y extraccion masiva.', options: {} },
  ], { x: 4.98, y: 3.58, w: 4.6, h: 1.0, fontFace: F, fontSize: 10, color: TINTA, lineSpacingMultiple: 1.0, valign: 'top' });

  franja(s, 'Verificable en vivo:  un ataque devuelve 403 con el ID de la regla activada y su puntuacion de anomalia  ·  marketgt.duckdns.org', 4.72, AZUL, 11.5);
  pie(s);
}

// ═════════════════════════════════════════════════════════════════════════════
//  6 · Mejoras en base a Estándar · DESPUÉS
// ═════════════════════════════════════════════════════════════════════════════
{
  const s = pres.addSlide();
  banda(s, '5', 'Mejoras en base a Estandar   ·   DESPUES de la implementacion',
    'ISO/IEC 27001:2022 como marco rector, con OWASP, CIS y NIST aportando el detalle tecnico');

  s.addTable([
    encabezados(['Dimension', 'ANTES', 'DESPUES', 'Anexo A']),
    [celda('Inspeccion de trafico', { negrita: true }), celda('Ninguna', { color: ROJO }), celda('ModSecurity 3 + OWASP CRS 4.29, paranoia 1 / deteccion 2', { color: VERDE }), celda('8.26', { align: 'center' })],
    [celda('Autenticacion', { negrita: true }), celda('Solo contrasena', { color: ROJO }), celda('TOTP (RFC 6238) + WebAuthn resistente a suplantacion de sitio', { color: VERDE }), celda('8.5', { align: 'center' })],
    [celda('Endurecimiento', { negrita: true }), celda('Instalacion por defecto', { color: ROJO }), celda('CIS Benchmark de Ubuntu y parcheo automatico', { color: VERDE }), celda('8.9', { align: 'center' })],
    [celda('Deteccion', { negrita: true }), celda('Ninguna', { color: ROJO }), celda('Panel propio: ingesta, correlacion y alertas con estado', { color: VERDE }), celda('8.15, 8.16', { align: 'center' })],
    [celda('Respuesta', { negrita: true }), celda('Ausente', { color: ROJO }), celda('Plan NIST SP 800-61, respaldo cifrado y restauracion probada', { color: VERDE }), celda('5.24-5.28', { align: 'center' })],
    [celda('Integridad SEO', { negrita: true }), celda('Sin cobertura', { color: ROJO }), celda('24 reglas propias y verificacion de rastreadores', { color: VERDE }), celda('8.16', { align: 'center' })],
  ], {
    x: 0.42, y: 1.18, w: 9.16, colW: [1.72, 1.78, 4.42, 1.24], rowH: 0.34,
    border: { type: 'solid', color: BORDE, pt: 0.75 }, autoPage: false,
  });

  rotulo(s, 'TRIANGULO DE CIBERRESILIENCIA', 0.42, 3.72, 4.32, VERDE);
  s.addShape(pres.ShapeType.rect, { x: 0.42, y: 4.04, w: 4.32, h: 0.62, fill: { color: PAPEL }, line: { color: BORDE, width: 1 } });
  s.addText([
    { text: 'Proteccion ', options: { bold: true } }, { text: 'desarrollada   ·   ', options: { color: TENUE } },
    { text: 'Deteccion ', options: { bold: true } }, { text: 'antes parcial   ·   ', options: { color: TENUE } },
    { text: 'Respuesta ', options: { bold: true } }, { text: 'antes ausente, hoy operativa', options: { color: TENUE } },
  ], { x: 0.55, y: 4.04, w: 4.1, h: 0.62, fontFace: F, fontSize: 10, color: VERDE, valign: 'middle' });

  rotulo(s, 'ESTANDARES APLICADOS', 4.98, 3.72, 4.6, AZUL);
  s.addShape(pres.ShapeType.rect, { x: 4.98, y: 4.04, w: 4.6, h: 0.62, fill: { color: PAPEL }, line: { color: BORDE, width: 1 } });
  s.addText('ISO/IEC 27001:2022  ·  ISO/IEC 27002:2022  ·  OWASP Top 10:2025  ·  OWASP CRS 4.29  ·  PCI DSS v4.0 req. 6.4.2  ·  CIS Benchmark  ·  NIST SP 800-61 y 800-63B  ·  RFC 6238  ·  W3C WebAuthn', {
    x: 5.11, y: 4.04, w: 4.34, h: 0.62, fontFace: F, fontSize: 9, color: TINTA, valign: 'middle',
  });

  s.addText('Cuatro de las ocho metricas del panel se declaran SIN DATOS: una cifra estimada no permite decidir, y su presencia invalidaria las demas.', {
    x: 0.42, y: 4.78, w: 9.16, h: 0.3, fontFace: F, fontSize: 10, color: TENUE, italic: true, align: 'center',
  });
  pie(s);
}

// ═════════════════════════════════════════════════════════════════════════════
//  7 · Cierre
// ═════════════════════════════════════════════════════════════════════════════
{
  const s = pres.addSlide();
  s.background = { color: MARINO };
  s.addShape(pres.ShapeType.rect, { x: 0, y: 0, w: 0.22, h: ALTO, fill: { color: AZUL } });

  s.addText('Lo que se entrega', {
    x: 0.7, y: 0.52, w: 8.8, h: 0.5, fontFace: F, fontSize: 28, bold: true, color: 'FFFFFF',
  });

  const bloques = [
    ['ENTORNO PUBLICADO', 'marketgt.duckdns.org', 'Certificado valido, HTTP/2 y el cortafuegos en linea'],
    ['CODIGO Y DOCUMENTACION', 'github.com/diegobeteta24/\nmarketgt-waf-iso27001', 'Politica, matriz de riesgos y plan de respuesta a incidentes'],
    ['COSTO DE LICENCIAMIENTO', 'Q 0.00', 'Cortafuegos, sistema operativo, base de datos, certificados y monitoreo'],
  ];
  bloques.forEach(function (b, i) {
    const x = 0.7 + i * 3.02;
    s.addShape(pres.ShapeType.rect, { x: x, y: 1.35, w: 2.82, h: 1.85, fill: { color: '17395C' } });
    s.addText(b[0], { x: x + 0.18, y: 1.5, w: 2.46, h: 0.26, fontFace: F, fontSize: 9, color: '9FB8CE', charSpacing: 0.5 });
    s.addText(b[1], { x: x + 0.18, y: 1.78, w: 2.46, h: 0.62, fontFace: F, fontSize: 13, bold: true, color: 'FFFFFF', valign: 'top' });
    s.addText(b[2], { x: x + 0.18, y: 2.46, w: 2.46, h: 0.66, fontFace: F, fontSize: 9.5, color: 'B8CCDE', valign: 'top' });
  });

  s.addShape(pres.ShapeType.rect, { x: 0.7, y: 3.45, w: 8.84, h: 0.92, fill: { color: 'FFFFFF' } });
  s.addText([
    { text: 'Lo que no se puede medir, se declara.\n', options: { fontSize: 16, bold: true, color: MARINO } },
    { text: 'Eso es lo que separa un sistema de gestion de la seguridad de un tablero bonito.', options: { fontSize: 11.5, color: TENUE } },
  ], { x: 0.95, y: 3.45, w: 8.34, h: 0.92, fontFace: F, valign: 'middle' });

  s.addText('Diego Beteta   ·   Jonathan Herrera   ·   Mario Rompich          Seguridad y Auditoria de Sistemas  ·  UMG  ·  septiembre 2026', {
    x: 0.7, y: 4.7, w: 8.84, h: 0.3, fontFace: F, fontSize: 10, color: '7F9DBC',
  });
  contador += 1;
}

const salida = process.argv[2] || 'Presentacion-Segundo-Entregable-MarketGT.pptx';
pres.writeFile({ fileName: salida }).then(function (nombre) {
  console.log('generado: ' + nombre);
  console.log('diapositivas: ' + contador);
});
