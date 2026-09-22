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

            {{-- El triangulo no es adorno: muestra de un vistazo que vertice esta sin instrumentar.
                 En el telefono baja a su propia linea y ocupa el ancho disponible en vez de
                 quedarse en una miniatura: el lienzo es proporcional, asi que al ensancharse
                 tambien crecen sus etiquetas. Desde sm recupera su alto fijo. --}}
            <svg viewBox="0 0 260 180" class="mx-auto h-auto w-full max-w-[17rem] sm:mx-0 sm:h-40 sm:w-auto" role="img" aria-label="Estado de los tres vertices de la ciberresiliencia">
                <polygon
                    points="130,22 238,158 22,158"
                    fill="none"
                    stroke="var(--siem-contorno)"
                    stroke-width="2"
                />

                @php
                    $posiciones = [
                        'proteccion' => ['x' => 130, 'y' => 22, 'anclaje' => 'middle', 'dy' => -12],
                        'respuesta' => ['x' => 238, 'y' => 158, 'anclaje' => 'end', 'dy' => 22],
                        'deteccion' => ['x' => 22, 'y' => 158, 'anclaje' => 'start', 'dy' => 22],
                    ];
                @endphp

                @foreach ($posiciones as $clave => $posicion)
                    @php $estado = $estadoVertice($vertices[$clave]); @endphp
                    <circle cx="{{ $posicion['x'] }}" cy="{{ $posicion['y'] }}" r="8" fill="{{ $colorEstado[$estado] }}" />
                    <text
                        class="siem-eje"
                        x="{{ $posicion['x'] }}"
                        y="{{ $posicion['y'] + $posicion['dy'] }}"
                        text-anchor="{{ $posicion['anclaje'] }}"
                        style="font-weight: 600"
                    >{{ $vertices[$clave]['nombre'] }}</text>
                    <text
                        class="siem-eje"
                        x="{{ $posicion['x'] }}"
                        y="{{ $posicion['y'] + $posicion['dy'] + 13 }}"
                        text-anchor="{{ $posicion['anclaje'] }}"
                    >{{ $estado === CalculadoraMetricas::SIN_DATOS ? 'sin instrumentar' : ($estado === CalculadoraMetricas::CUMPLE ? 'cumple' : 'incumple') }}</text>
                @endforeach
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
