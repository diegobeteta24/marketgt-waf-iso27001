@php
    use App\Services\Siem\CalculadoraMetricas;

    $vertices = $this->vertices;
    $cobertura = $this->cobertura;

    // Estado de cada vertice: verde solo si todo lo medible cumple, gris si nada se puede medir.
    $estadoVertice = function (array $vertice): string {
        $medidas = array_filter(
            $vertice['metricas'],
            static fn (array $metrica): bool => $metrica['estado'] !== CalculadoraMetricas::SIN_DATOS,
        );

        if ($medidas === []) {
            return CalculadoraMetricas::SIN_DATOS;
        }

        foreach ($medidas as $metrica) {
            if ($metrica['estado'] === CalculadoraMetricas::INCUMPLE) {
                return CalculadoraMetricas::INCUMPLE;
            }
        }

        return CalculadoraMetricas::CUMPLE;
    };

    $colorEstado = [
        CalculadoraMetricas::CUMPLE => 'var(--siem-cumple)',
        CalculadoraMetricas::INCUMPLE => 'var(--siem-critica)',
        CalculadoraMetricas::SIN_DATOS => 'var(--siem-informativa)',
    ];

    // La misma palabra que ya rotula cada vertice dentro del triangulo. Se reutiliza aqui
    // para que el punto de color de la ficha no sea el unico canal: quien no distingue el
    // verde del rojo, o quien mira una diapositiva descolorida, la obtiene del texto.
    $textoEstado = [
        CalculadoraMetricas::CUMPLE => 'cumple',
        CalculadoraMetricas::INCUMPLE => 'incumple',
        CalculadoraMetricas::SIN_DATOS => 'sin instrumentar',
    ];
@endphp

<div wire:poll.60s class="space-y-6">
    <div class="rounded-xl border border-zinc-200 bg-white p-3 sm:p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-wrap items-start justify-between gap-4 sm:gap-6">
            <div class="min-w-0 max-w-xl">
                <flux:heading size="lg">Triangulo de la ciberresiliencia</flux:heading>
                <flux:subheading>
                    Ventana de observacion: ultimos {{ $this->diasObservacion }} dias.
                    Cada cifra sale de una consulta a la base. Lo que no se puede calcular se
                    declara <span class="font-semibold">sin datos</span>: inventar un numero aqui
                    seria el propio hallazgo de la auditoria.
                </flux:subheading>

                <p class="mt-3 text-sm text-zinc-300">
                    <span class="siem-numero font-semibold text-zinc-100">{{ $cobertura['medidas'] }}</span>
                    de {{ $cobertura['total'] }} metricas son calculables hoy;
                    <span class="siem-numero font-semibold text-zinc-100">{{ $cobertura['cumplidas'] }}</span>
                    de esas cumplen su meta.
                </p>
            </div>

            {{--
                EL TRIÁNGULO NO ES UN ADORNO, Y ESTE DIBUJO TAMPOCO.

                El modelo del anexo no sostiene que haya que tener los tres vértices, sino que
                haya que tenerlos EQUILIBRADOS: concentrar toda la inversión en uno no da más
                seguridad, desplaza el riesgo hacia los desatendidos. Por eso la evaluación
                pertinente no es si cada vértice existe, sino cuánto se aleja la figura de un
                triángulo equilátero.

                El dibujo anterior sólo pintaba tres puntos de color sobre un contorno fijo: no
                transmitía nada de eso. Éste superpone dos figuras. La discontinua es el
                equilibrio esperado —los tres vértices al máximo— y la rellena es el estado
                medido. Cuanto más escaleno sale el relleno, más desequilibrado está el sistema,
                y eso se lee de un vistazo desde el fondo del aula.

                La madurez de cada vértice es la proporción de métricas que cumplen sobre el
                total declarado, NO sobre las calculables. La distinción importa: un vértice con
                una sola métrica medible que cumple no está al cien por cien, está sin
                instrumentar. Contar sólo lo medible premiaría precisamente a quien no mide.
            --}}
            @php
                $madurez = function (array $vertice): float {
                    $total = count($vertice['metricas']);

                    if ($total === 0) {
                        return 0.0;
                    }

                    $cumplen = count(array_filter(
                        $vertice['metricas'],
                        static fn (array $m): bool => $m['estado'] === CalculadoraMetricas::CUMPLE,
                    ));

                    return $cumplen / $total;
                };

                // Geometría del lienzo. El triángulo apunta hacia arriba, con protección en el
                // vértice superior, que es como lo representa el anexo del proyecto.
                $cx = 150;   // centro horizontal
                $cy = 132;   // centro vertical
                $r  = 86;    // distancia del centro a cada vértice en el estado ideal

                // Ángulos en grados, medidos desde arriba y en sentido horario.
                $angulos = ['proteccion' => -90, 'respuesta' => 30, 'deteccion' => 150];

                // Un vértice sin nada medido se dibuja igualmente separado del centro: un punto
                // exactamente en el origen desaparecería y parecería un fallo del dibujo en vez
                // de un vértice sin instrumentar.
                $minimo = 0.16;

                $punto = function (float $grados, float $distancia) use ($cx, $cy): array {
                    $rad = deg2rad($grados);

                    return [
                        round($cx + $distancia * cos($rad), 1),
                        round($cy + $distancia * sin($rad), 1),
                    ];
                };

                $ideal = [];
                $real  = [];
                $datos = [];

                foreach ($angulos as $clave => $grados) {
                    $m = $madurez($vertices[$clave]);
                    $d = $r * max($minimo, $m);

                    [$ix, $iy] = $punto((float) $grados, (float) $r);
                    [$rx, $ry] = $punto((float) $grados, (float) $d);
                    [$ex, $ey] = $punto((float) $grados, (float) ($r + 20));

                    $ideal[] = $ix . ',' . $iy;
                    $real[]  = $rx . ',' . $ry;

                    $datos[$clave] = [
                        'x' => $rx, 'y' => $ry,
                        'ex' => $ex, 'ey' => $ey,
                        'estado' => $estadoVertice($vertices[$clave]),
                        'porcentaje' => (int) round($m * 100),
                        // El anclaje sigue al vértice: centrado arriba, a la izquierda del de la
                        // derecha y a la derecha del de la izquierda, para que ninguna etiqueta
                        // invada la figura.
                        'anclaje' => $grados === -90 ? 'middle' : ($grados === 30 ? 'end' : 'start'),
                        'dy' => $grados === -90 ? -6 : 14,
                    ];
                }
            @endphp

            <svg viewBox="0 0 300 250"
                 class="mx-auto h-auto w-full max-w-[20rem] shrink-0 sm:mx-0 sm:w-72"
                 role="img"
                 aria-label="Equilibrio entre los tres vertices de la ciberresiliencia">

                {{-- Equilibrio esperado --}}
                <polygon points="{{ implode(' ', $ideal) }}"
                         fill="none"
                         stroke="var(--siem-contorno)"
                         stroke-width="1.5"
                         stroke-dasharray="5 4" />

                {{-- Radios: dejan ver cuánto se quedó corto cada vértice --}}
                @foreach ($datos as $d)
                    <line x1="{{ $cx }}" y1="{{ $cy }}" x2="{{ $d['x'] }}" y2="{{ $d['y'] }}"
                          stroke="var(--siem-rejilla)" stroke-width="1" />
                @endforeach

                {{-- Estado medido --}}
                <polygon points="{{ implode(' ', $real) }}"
                         fill="var(--siem-critica)"
                         fill-opacity="0.22"
                         stroke="var(--siem-critica)"
                         stroke-width="2"
                         stroke-linejoin="round" />

                {{-- Vértices y etiquetas --}}
                @foreach ($datos as $clave => $d)
                    <circle cx="{{ $d['x'] }}" cy="{{ $d['y'] }}" r="6"
                            fill="{{ $colorEstado[$d['estado']] }}"
                            stroke="var(--siem-superficie)" stroke-width="2" />

                    <text class="siem-eje"
                          x="{{ $d['ex'] }}" y="{{ $d['ey'] + $d['dy'] }}"
                          text-anchor="{{ $d['anclaje'] }}"
                          style="font-weight: 700; font-size: 13px"
                          fill="var(--siem-destacado)">{{ $vertices[$clave]['nombre'] }}</text>

                    <text class="siem-eje"
                          x="{{ $d['ex'] }}" y="{{ $d['ey'] + $d['dy'] + 14 }}"
                          text-anchor="{{ $d['anclaje'] }}"
                          style="font-size: 11px"
                          fill="{{ $colorEstado[$d['estado']] }}">{{ $textoEstado[$d['estado']] }} · {{ $d['porcentaje'] }} %</text>
                @endforeach

                {{-- Leyenda. Sin ella la figura discontinua se lee como un adorno. --}}
                <g transform="translate(14, 238)">
                    <line x1="0" y1="-4" x2="16" y2="-4"
                          stroke="var(--siem-contorno)" stroke-width="1.5" stroke-dasharray="5 4" />
                    <text class="siem-eje" x="21" y="0" style="font-size: 10px">equilibrio esperado</text>

                    <rect x="132" y="-9" width="14" height="10"
                          fill="var(--siem-critica)" fill-opacity="0.22"
                          stroke="var(--siem-critica)" stroke-width="1.5" />
                    <text class="siem-eje" x="151" y="0" style="font-size: 10px">estado medido</text>
                </g>
            </svg>
        </div>
    </div>

    {{-- Las tres fichas van apiladas en el telefono: cada una lleva varias metricas con su
         cifra grande, y en columnas estrechas la cifra dejaria de leerse de un vistazo. --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($vertices as $clave => $vertice)
            <div class="flex flex-col rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="border-b border-zinc-200 p-3 sm:p-4 dark:border-zinc-700">
                    @php $estadoFicha = $estadoVertice($vertice); @endphp
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        {{-- El punto resume el vertice entero. Como resumen no tenia texto en
                             ninguna parte: el color era su unico canal. Ahora lleva la palabra
                             al lado, en el color del propio estado y con el punto delante. --}}
                        <span
                            class="siem-muestra"
                            style="background: {{ $colorEstado[$estadoFicha] }}"
                            aria-hidden="true"
                        ></span>
                        <flux:heading size="lg">{{ $vertice['nombre'] }}</flux:heading>
                        <span
                            class="text-xs font-semibold"
                            style="color: {{ $colorEstado[$estadoFicha] }}"
                        >{{ $textoEstado[$estadoFicha] }}</span>
                    </div>
                    <flux:subheading>{{ $vertice['descripcion'] }}</flux:subheading>
                </div>

                <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($vertice['metricas'] as $metrica)
                        <div class="space-y-2 p-3 sm:p-4">
                            <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                                <p class="min-w-0 text-sm font-medium text-white">{{ $metrica['nombre'] }}</p>

                                {{-- Icono mas texto: el estado nunca se comunica solo con color. --}}
                                @if ($metrica['estado'] === CalculadoraMetricas::CUMPLE)
                                    <span class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold" style="color: var(--siem-cumple)">
                                        <flux:icon.check-circle class="size-4" /> Cumple
                                    </span>
                                @elseif ($metrica['estado'] === CalculadoraMetricas::INCUMPLE)
                                    <span class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold" style="color: var(--siem-critica)">
                                        <flux:icon.x-circle class="size-4" /> No cumple
                                    </span>
                                @else
                                    <span class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-zinc-400">
                                        <flux:icon.minus-circle class="size-4" /> Sin datos
                                    </span>
                                @endif
                            </div>

                            {{-- El tamano se decide una sola vez por estado: text-2xl y text-base
                                 sueltos en la misma lista se anulaban entre si y "sin datos" se
                                 pintaba tan grande como una cifra real, gastando alto de pantalla
                                 justo donde menos sobra. --}}
                            <p @class([
                                'siem-numero break-words',
                                'text-2xl font-semibold text-white' => $metrica['estado'] !== CalculadoraMetricas::SIN_DATOS,
                                'text-base font-normal italic text-zinc-400' => $metrica['estado'] === CalculadoraMetricas::SIN_DATOS,
                            ])>{{ $metrica['valor_texto'] }}</p>

                            <p class="text-xs text-zinc-400">
                                <span class="font-medium">Meta:</span> {{ $metrica['meta'] }}
                                @if ($metrica['muestra'] > 0)
                                    · muestra de {{ number_format($metrica['muestra']) }}
                                @endif
                            </p>

                            <p class="break-words text-sm leading-relaxed text-zinc-400 sm:text-xs">{{ $metrica['origen'] }}</p>

                            @if ($metrica['advertencia'])
                                <p class="text-sm font-medium text-amber-400 sm:text-xs">{{ $metrica['advertencia'] }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white p-3 sm:p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading size="lg">Estado de las alertas en la ventana</flux:heading>
        <flux:subheading>El denominador de la tasa de falsos positivos y de la cobertura de triaje.</flux:subheading>

        {{-- Seis contadores: dos columnas en el telefono, seis en linea desde lg. --}}
        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            @php $conteos = $this->conteos; @endphp
            <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                <p class="text-xs uppercase tracking-wide text-zinc-400">Total</p>
                <p class="siem-numero mt-1 text-xl font-semibold text-zinc-100">{{ number_format($conteos['total']) }}</p>
            </div>
            @foreach (\App\Models\AlertaSeguridad::ETIQUETAS_ESTADO as $estado => $etiqueta)
                <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <p class="text-xs uppercase tracking-wide text-zinc-400">{{ $etiqueta }}</p>
                    <p class="siem-numero mt-1 text-xl font-semibold text-zinc-100">{{ number_format($conteos[$estado]) }}</p>
                </div>
            @endforeach
        </div>
    </div>
</div>
