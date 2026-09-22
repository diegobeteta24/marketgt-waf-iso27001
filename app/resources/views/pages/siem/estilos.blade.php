{{--
    Paleta del centro de monitoreo.

    Vive aqui y no en clases de Tailwind por dos razones: los colores de una grafica son
    datos codificados, no decoracion, y conviene tenerlos en un solo sitio para poder
    justificarlos ante el profesor; y el SVG se dibuja en el servidor, de modo que necesita
    valores que no dependan de que el compilador de Tailwind haya visto la clase.

    La plantilla del sitio fija la clase "dark" en el elemento raiz, de modo que el panel de
    monitoreo solo se muestra sobre fondo oscuro. Antes habia dos bloques, uno claro de base
    y uno oscuro como anadido, y varias variables solo estaban corregidas en el anadido: las
    que faltaban se quedaban con el valor pensado para fondo blanco y apenas se distinguian.
    Por eso ahora el bloque base YA es el del fondo oscuro: ninguna variable depende de que
    otro selector la rescate.

    Los colores se eligieron por separacion bajo daltonismo (deuteranopia y protanopia) y por
    relacion de contraste contra el fondo de la tarjeta, zinc-900 (#171717), no por gusto.
    Ningun estado se comunica solo con color: cada insignia lleva siempre su texto.

    Que el WAF bloquee es un EXITO, no un error: por eso la serie de bloqueados va en verde
    esmeralda y el rojo queda reservado para lo que exige atencion, que es la severidad
    critica y el incumplimiento de una meta.
--}}
<style>
    .panel-siem {
        /*
            Series de la grafica. Esmeralda (9.3:1 sobre la tarjeta) contra gris azulado
            (7.0:1). Se separan sobre todo por TONO —verde saturado contra azul grisaceo
            apagado—, que es una diferencia del eje azul-amarillo y por tanto la conserva
            quien no distingue el rojo del verde. Por claridad, en cambio, apenas se separan:
            1.33:1 entre si. Por eso las dos series NO se fian del color para delimitarse y
            llevan ademas un filete del color de la tarjeta (vease .siem-barra-*): el borde
            entre lo cortado y lo permitido se ve aunque el proyector lave el tono o aunque
            quien mira no perciba color alguno. La leyenda lleva ademas su texto.
        */
        --siem-serie-bloqueados: #34d399;
        --siem-serie-permitidos: #94a3b8;

        /*
            La reja se ve, pero no compite con las barras. A 0.14 la linea se quedaba en
            1.5:1 contra la tarjeta y en un proyector, que lava justamente los tonos
            oscuros, desaparecia. A 0.20 sube a 1.9:1: sigue muy por debajo de las barras
            (9.3:1 y 7.0:1), de modo que la jerarquia no cambia, pero la referencia
            sobrevive desde el fondo del aula.
        */
        --siem-rejilla: rgba(255, 255, 255, 0.20);

        /*
            Contorno de una figura que SI es el dibujo, como el triangulo de ciberresiliencia.
            Con el valor de la reja se perdia contra el fondo: una linea de referencia puede
            ser tenue, pero la forma que se esta mirando no. A 0.32 se quedaba en 2.9:1,
            justo por debajo del 3:1 que se exige a un elemento grafico con significado;
            a 0.38 alcanza 3.6:1 y aguanta el proyector.
        */
        --siem-contorno: rgba(255, 255, 255, 0.38);

        /* Eje: zinc-400, el minimo admisible para texto terciario sobre fondo oscuro. */
        --siem-eje: #a3a3a3;

        /* La cifra del pico es dato principal, no rotulo de eje: va en texto principal. */
        --siem-destacado: #f5f5f5;

        /* Fondo real de la tarjeta, para el contorno de la barra al pasar el raton. */
        --siem-superficie: #171717;

        /*
            Color de texto por defecto del panel. La plantilla no fija ninguno en <body>, de
            modo que todo lo que no llevaba clase de color —las columnas de cifras de las
            tablas, sobre todo— heredaba el negro del navegador y desaparecia sobre la
            tarjeta oscura. Esto es la red de seguridad; cada cifra lleva ademas su clase.
        */
        --siem-texto: #f5f5f5;

        /* Severidades y estados. */
        --siem-critica: #f87171;
        --siem-alta: #fb923c;
        --siem-media: #fbbf24;
        --siem-baja: #38bdf8;
        --siem-informativa: #a3a3a3;
        --siem-cumple: #34d399;

        color: var(--siem-texto);
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
        fill: var(--siem-destacado);
        font-size: 11px;
        font-weight: 600;
        font-variant-numeric: tabular-nums;
    }

    /*
        Filete del color de la tarjeta alrededor de cada tramo. Las dos series se distinguen
        muy bien por tono, pero entre si solo se separan 1.33:1 en claridad, y van APILADAS:
        lo bloqueado se dibuja justo encima de lo permitido, de modo que comparten un borde.
        Sin el filete ese borde depende por entero del color, que es lo que se pierde en un
        proyector y lo que no ve quien carece de vision cromatica. Con el, el limite entre
        los dos tramos y entre una barra y la siguiente es una linea de fondo, siempre
        visible. Un cuarto de unidad sobre un lienzo de 760 no adelgaza la barra de forma
        apreciable. Al pasar el raton el mismo filete engorda y hace de realce.
    */
    .panel-siem .siem-barra-permitidos,
    .panel-siem .siem-barra-bloqueados {
        stroke: var(--siem-superficie);
        stroke-width: 0.5;
    }

    .panel-siem .siem-barra-permitidos {
        fill: var(--siem-serie-permitidos);
    }

    .panel-siem .siem-barra-bloqueados {
        fill: var(--siem-serie-bloqueados);
    }

    .panel-siem .siem-grupo-barra:hover .siem-barra-permitidos,
    .panel-siem .siem-grupo-barra:hover .siem-barra-bloqueados {
        stroke-width: 2;
    }

    .panel-siem .siem-muestra {
        display: inline-block;
        width: 10px;
        height: 10px;
        border-radius: 2px;
        flex: none;
    }

    /*
        Pastilla de severidad. El relleno se deriva del propio color con color-mix, de modo
        que cada severidad trae su fondo tenue sin declarar cinco variables mas; el borde y
        el punto siguen saliendo de currentColor. Se declara antes un relleno plano como
        reserva, por si el navegador no soporta color-mix.
    */
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
        background: rgba(255, 255, 255, 0.06);
        background: color-mix(in srgb, currentColor 16%, transparent);
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

    /*
        La misma insignia sin pastilla, para el reparto por severidad: ahi el color ya lo
        lleva la muestra de al lado, y una pastilla por etiqueta convertiria la linea en un
        amontonamiento. La palabra conserva su color propio, que es lo que hace que la
        proporcion se capte de un vistazo.
    */
    .panel-siem .siem-sev-suelta {
        border: 0;
        padding: 0;
        background: none;
    }

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

    /*
        Paginacion de la tabla de eventos. La dibuja Livewire con su propia plantilla, de
        modo que no se puede corregir con clases desde la vista: sus botones miden 38
        pixeles de alto y en un telefono eso se falla con el dedo. Aqui suben a 44 solo por
        debajo de sm; desde ese ancho se recupera el tamano compacto original. El hermano
        deshabilitado no necesita regla propia: la fila es flex y se estira con el boton.
    */
    @media (max-width: 639px) {
        .panel-siem .siem-paginacion button,
        .panel-siem .siem-paginacion a {
            min-height: 2.75rem;
        }
    }
</style>
