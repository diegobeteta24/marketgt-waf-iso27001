# Segundo entregable

| Archivo | Qué es |
|---|---|
| `Segundo-Entregable-MarketGT.docx` | Documento: modelo de negocio, problema de seguridad, solución tecnológica e implementación de estándares |
| `Presentacion-Segundo-Entregable-MarketGT.pptx` | Presentación de 7 diapositivas |

## Regenerar

Los dos archivos se generan por código, de modo que corregir un nombre, un
carné o una cifra es editar una línea y volver a ejecutar, no repasar el
documento a mano.

```bash
npm install docx pptxgenjs
node generar-documento.js    Segundo-Entregable-MarketGT.docx
node generar-presentacion.js Presentacion-Segundo-Entregable-MarketGT.pptx
```

## Qué se declara y qué se omite

El caso de envenenamiento de posicionamiento es real y procede de la consola
de búsqueda de una empresa guatemalteca. **El dominio se omite a propósito**
en ambos archivos, porque el sitio sigue comprometido; se describe la
actividad de la empresa y las consultas observadas, nada más.

La capa 1 (perímetro) se declara implementada de forma parcial, con su
justificación, en lugar de darla por completa. El criterio está en
[`../02-arquitectura/decision-capa1-perimetro.md`](../02-arquitectura/decision-capa1-perimetro.md).
