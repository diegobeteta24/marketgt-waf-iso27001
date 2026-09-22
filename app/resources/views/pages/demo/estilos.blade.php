{{--
    Paleta de la consola de demostración.

    Vive aquí, y no repartida en clases de Tailwind, para poder justificar cada color ante
    el profesor. El criterio es una inversión deliberada de lo habitual: el 403 se pinta de
    VERDE, porque en esta pantalla un bloqueo es el éxito —el WAF hizo su trabajo—, y el
    tráfico que pasa se pinta de ámbar. Ningún estado se comunica solo con color: cada
    insignia y cada código llevan siempre su texto al lado.

    Los valores que se ven de verdad son los del tema oscuro, porque la plantilla fuerza la
    clase `dark`. Están elegidos para un proyector, que lava los colores y se come los tonos
    apagados: todos caen en la franja 300-400, ninguno en la 600-700.
--}}
<style>
    .panel-demo {
        --demo-bloqueado: #0ca30c;   /* 403: el WAF cortó. En esta consola, es el éxito. */
        --demo-paso: #c2521f;        /* 2xx/3xx: la petición atravesó el borde. */
        --demo-detectado: #9a6b00;   /* detectado pero por debajo del umbral. */
        --demo-neutro: #6b7280;
        --demo-peligro: #d03b3b;     /* avisos de tráfico real contra infraestructura. */
        --demo-borde-codigo: rgba(11, 11, 11, 0.08);
        --demo-fondo-codigo: #f5f5f4;
        --demo-texto-codigo: #27272a; /* el bloque de código fija su color, nunca lo hereda. */
        --demo-clave: #18181b;        /* lo resaltado dentro del bloque de código. */

        /* Fondos de las pastillas: tenues, solo para dar cuerpo al color del texto. */
        --demo-tinte-bloqueado: rgba(12, 163, 12, 0.10);
        --demo-tinte-paso: rgba(194, 82, 31, 0.10);
        --demo-tinte-detectado: rgba(154, 107, 0, 0.10);
        --demo-tinte-neutro: rgba(107, 114, 128, 0.10);
    }

    .dark .panel-demo {
        --demo-bloqueado: #34d399;
        --demo-paso: #ec835a;
        --demo-detectado: #fab219;
        --demo-neutro: #a1a1aa;
        --demo-peligro: #f87171;
        /* Al 10 % de blanco el borde no se veía y el bloque de código se fundía con la
           tarjeta que lo contiene. Al 18 % se lee como bloque sin robar atención. */
        --demo-borde-codigo: rgba(255, 255, 255, 0.18);
        --demo-fondo-codigo: #0a0a0a;
        --demo-texto-codigo: #e5e5e5;
        --demo-clave: #ffffff;

        --demo-tinte-bloqueado: rgba(52, 211, 153, 0.16);
        --demo-tinte-paso: rgba(236, 131, 90, 0.16);
        --demo-tinte-detectado: rgba(250, 178, 25, 0.16);
        --demo-tinte-neutro: rgba(161, 161, 170, 0.16);
    }

    /*
        Las cargas útiles son cadenas largas con caracteres codificados y sin espacios.
        `overflow-wrap: anywhere` permite cortarlas en cualquier punto, y el
        `overflow-x: auto` queda de red de seguridad para lo que aun así no quepa: el
        desplazamiento lateral se encierra en este bloque y nunca llega a la página.
        `max-width: 100%` y `min-width: 0` evitan que el bloque estire a su padre cuando
        está dentro de una rejilla o de un contenedor flexible.

        El `color` es explícito a propósito. Sin él, el bloque heredaba el color del
        documento —casi negro— sobre un fondo casi negro: la carga útil del ataque, que es
        justo lo que hay que enseñar, quedaba ilegible.
    */
    .panel-demo .demo-codigo {
        font-family: ui-monospace, "Cascadia Code", "Source Code Pro", Menlo, Consolas, monospace;
        font-size: 0.8rem;
        line-height: 1.5;
        white-space: pre-wrap;
        word-break: break-word;
        overflow-wrap: anywhere;
        color: var(--demo-texto-codigo);
        background: var(--demo-fondo-codigo);
        border: 1px solid var(--demo-borde-codigo);
        border-radius: 0.5rem;
        padding: 0.75rem 0.9rem;
        overflow-x: auto;
        max-width: 100%;
        min-width: 0;
    }

    /* Lo resaltado dentro del bloque —el método de la petición— tiene que ganarle al
       resto del bloque, no empatar con él. */
    .panel-demo .demo-codigo .demo-clave {
        color: var(--demo-clave);
        font-weight: 700;
    }

    /* Todo `code` suelto de la consola —rutas, dominios, nombres de variable— puede
       cortarse: son los candidatos habituales a desbordar un teléfono. */
    .panel-demo code {
        overflow-wrap: anywhere;
        word-break: break-word;
    }

    /* En pantallas estrechas se recorta el relleno del bloque de código y se baja un punto
       el tamaño: son datos tabulares, no texto corrido, y cada carácter ganado por línea
       evita una línea partida más. */
    @media (max-width: 639px) {
        .panel-demo .demo-codigo {
            font-size: 0.75rem;
            padding: 0.6rem 0.7rem;
        }
    }

    .panel-demo .demo-numero {
        font-variant-numeric: tabular-nums;
    }

    /* Semáforo del código de respuesta. El color es un refuerzo; el número manda.
       Es el remate de la demostración y se proyecta, así que no puede depender de un
       trazo fino: letra algo mayor, borde de 2 px y un fondo tenue del propio color. */
    .panel-demo .demo-http {
        display: inline-flex;
        align-items: center;
        max-width: 100%;
        gap: 0.4rem;
        border-radius: 0.5rem;
        padding: 0.2rem 0.7rem;
        font-size: 1.05rem;
        font-weight: 700;
        font-variant-numeric: tabular-nums;
        border: 2px solid currentColor;
        background: var(--demo-tinte-neutro);
    }

    .panel-demo .demo-http-bloqueado {
        color: var(--demo-bloqueado);
        background: var(--demo-tinte-bloqueado);
    }

    .panel-demo .demo-http-paso {
        color: var(--demo-paso);
        background: var(--demo-tinte-paso);
    }

    .panel-demo .demo-http-error {
        color: var(--demo-neutro);
        background: var(--demo-tinte-neutro);
    }

    /* El identificador de la regla que se activó es la prueba de que el WAF hizo algo,
       y se lee desde el fondo del aula: fondo tenue propio y un punto más de tamaño. */
    .panel-demo .demo-regla {
        display: inline-flex;
        align-items: center;
        max-width: 100%;
        gap: 0.35rem;
        border-radius: 9999px;
        padding: 0.1rem 0.6rem;
        font-size: 0.75rem;
        font-weight: 600;
        font-variant-numeric: tabular-nums;
        border: 1px solid currentColor;
        color: var(--demo-bloqueado);
        background: var(--demo-tinte-bloqueado);
    }

    .panel-demo .demo-regla-propia {
        color: var(--demo-detectado);
        background: var(--demo-tinte-detectado);
    }
</style>
