{{--
    Paleta del centro de monitoreo.

    Vive aqui y no en clases de Tailwind por dos razones: los colores de una grafica son
    datos codificados, no decoracion, y conviene tenerlos en un solo sitio para poder
    justificarlos ante el profesor; y el SVG se dibuja en el servidor, de modo que necesita
    valores que no dependan de que el compilador de Tailwind haya visto la clase.

    Los pares azul/rojo se eligieron por separacion bajo daltonismo (deuteranopia y
    protanopia) contra ambos fondos, no por gusto. Ningun estado se comunica solo con color:
    cada insignia lleva siempre su texto.
--}}
<style>
    .panel-siem {
        --siem-serie-permitidos: #2a78d6;
        --siem-serie-bloqueados: #d03b3b;
        --siem-rejilla: rgba(11, 11, 11, 0.10);
        --siem-eje: #898781;
        --siem-superficie: #ffffff;

        --siem-critica: #d03b3b;
        --siem-alta: #c2521f;
        --siem-media: #9a6b00;
        --siem-baja: #2a78d6;
        --siem-informativa: #6b7280;
        --siem-cumple: #0ca30c;
    }

    .dark .panel-siem {
        --siem-serie-permitidos: #3987e5;
        --siem-serie-bloqueados: #d03b3b;
        --siem-rejilla: rgba(255, 255, 255, 0.10);
        --siem-eje: #a1a1aa;
        --siem-superficie: #171717;

        --siem-alta: #ec835a;
        --siem-media: #fab219;
        --siem-baja: #3987e5;
        --siem-informativa: #a1a1aa;
    }

    .panel-siem .siem-rejilla {
        stroke: var(--siem-rejilla);
        stroke-width: 1;
    }

    .panel-siem .siem-eje {
        fill: var(--siem-eje);
        font-size: 11px;
        font-variant-numeric: tabular-nums;
    }

    .panel-siem .siem-etiqueta-directa {
        fill: var(--siem-eje);
        font-size: 11px;
        font-weight: 600;
        font-variant-numeric: tabular-nums;
    }

    .panel-siem .siem-barra-permitidos {
        fill: var(--siem-serie-permitidos);
    }

    .panel-siem .siem-barra-bloqueados {
        fill: var(--siem-serie-bloqueados);
    }

    .panel-siem .siem-grupo-barra:hover .siem-barra-permitidos,
    .panel-siem .siem-grupo-barra:hover .siem-barra-bloqueados {
        stroke: var(--siem-superficie);
        stroke-width: 2;
    }

    .panel-siem .siem-muestra {
        display: inline-block;
        width: 10px;
        height: 10px;
        border-radius: 2px;
        flex: none;
    }

    .panel-siem .siem-sev {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        border-radius: 9999px;
        padding: 0.05rem 0.5rem;
        font-size: 0.7rem;
        font-weight: 600;
        line-height: 1.4;
        white-space: nowrap;
        border: 1px solid currentColor;
    }

    .panel-siem .siem-sev::before {
        content: '';
        width: 6px;
        height: 6px;
        border-radius: 9999px;
        background: currentColor;
    }

    .panel-siem .siem-sev-critica { color: var(--siem-critica); }
    .panel-siem .siem-sev-alta { color: var(--siem-alta); }
    .panel-siem .siem-sev-media { color: var(--siem-media); }
    .panel-siem .siem-sev-baja { color: var(--siem-baja); }
    .panel-siem .siem-sev-informativa { color: var(--siem-informativa); }

    .panel-siem .siem-numero {
        font-variant-numeric: tabular-nums;
    }

    /*
        Adaptacion a pantallas pequenas del SVG del tablero.

        La grafica se dibuja sobre un lienzo de 760 unidades. En un telefono ese lienzo se
        escala a poco mas de 300 pixeles reales, de modo que una tipografia fijada en 11
        unidades acaba midiendo cuatro: ilegible. Como el tamano va en unidades del propio
        lienzo, aqui se agranda para que al encogerse el SVG recupere un cuerpo legible. Se
        permite ademas que la etiqueta del eje vertical sobresalga del recuadro en vez de
        recortarse: el relleno de la tarjeta le deja sitio.

        La regla se limita a .siem-grafica a proposito: el triangulo de ciberresiliencia usa
        el mismo .siem-eje pero sobre un lienzo mucho mas pequeno, y ahi agrandar la letra
        la haria chocar consigo misma.
    */
    @media (max-width: 639px) {
        .panel-siem .siem-grafica {
            overflow: visible;
        }

        .panel-siem .siem-grafica .siem-eje {
            font-size: 21px;
        }

        .panel-siem .siem-grafica .siem-etiqueta-directa {
            font-size: 22px;
        }
    }

    /*
        Etiquetas del eje horizontal que solo caben en pantalla ancha. El componente ya
        reparte un maximo de doce; en un telefono esas doce se amontonan, asi que la vista
        marca la mitad con esta clase y aqui se ocultan. Es una decision de presentacion:
        el dato sigue estando en el titulo accesible de cada barra.
    */
    .panel-siem .siem-solo-ancho {
        display: none;
    }

    @media (min-width: 640px) {
        .panel-siem .siem-solo-ancho {
            display: inline;
        }
    }
</style>
