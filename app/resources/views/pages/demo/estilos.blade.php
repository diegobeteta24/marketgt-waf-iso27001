{{--
    Paleta de la consola de demostración.

    Vive aquí, y no repartida en clases de Tailwind, para poder justificar cada color ante
    el profesor. El criterio es una inversión deliberada de lo habitual: el 403 se pinta de
    VERDE, porque en esta pantalla un bloqueo es el éxito —el WAF hizo su trabajo—, y el
    tráfico que pasa se pinta de ámbar. Ningún estado se comunica solo con color: cada
    insignia y cada código llevan siempre su texto al lado.
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
    }

    .dark .panel-demo {
        --demo-bloqueado: #34d399;
        --demo-paso: #ec835a;
        --demo-detectado: #fab219;
        --demo-neutro: #a1a1aa;
        --demo-peligro: #f87171;
        --demo-borde-codigo: rgba(255, 255, 255, 0.10);
        --demo-fondo-codigo: #0a0a0a;
    }

    .panel-demo .demo-codigo {
        font-family: ui-monospace, "Cascadia Code", "Source Code Pro", Menlo, Consolas, monospace;
        font-size: 0.8rem;
        line-height: 1.5;
        white-space: pre-wrap;
        word-break: break-word;
        background: var(--demo-fondo-codigo);
        border: 1px solid var(--demo-borde-codigo);
        border-radius: 0.5rem;
        padding: 0.75rem 0.9rem;
        overflow-x: auto;
    }

    .panel-demo .demo-numero {
        font-variant-numeric: tabular-nums;
    }

    /* Semáforo del código de respuesta. El color es un refuerzo; el número manda. */
    .panel-demo .demo-http {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        border-radius: 0.5rem;
        padding: 0.15rem 0.6rem;
        font-weight: 700;
        font-variant-numeric: tabular-nums;
        border: 1px solid currentColor;
    }

    .panel-demo .demo-http-bloqueado { color: var(--demo-bloqueado); }
    .panel-demo .demo-http-paso { color: var(--demo-paso); }
    .panel-demo .demo-http-error { color: var(--demo-neutro); }

    .panel-demo .demo-regla {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        border-radius: 9999px;
        padding: 0.05rem 0.55rem;
        font-size: 0.72rem;
        font-weight: 600;
        font-variant-numeric: tabular-nums;
        border: 1px solid currentColor;
        color: var(--demo-bloqueado);
    }

    .panel-demo .demo-regla-propia { color: var(--demo-detectado); }
</style>
