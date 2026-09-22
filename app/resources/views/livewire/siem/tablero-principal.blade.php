@php
    $contadores = $this->contadores;
    $grafica = $this->grafica;
    $reglas = $this->reglas;

    // Decision de presentacion, no de datos: el componente reparte hasta doce etiquetas en el
    // eje horizontal, que es lo que cabe en pantalla ancha. En un telefono el lienzo se escala
    // a menos de la mitad y esas doce se amontonan, asi que aqui se eligen las que si caben y
    // el resto se marca con siem-solo-ancho para que la hoja de estilos las oculte en movil.
    $indicesConEtiqueta = array_keys(array_filter(
        $grafica['barras'],
        static fn (array $barra): bool => $barra['mostrar_etiqueta'],
    ));

    $etiquetasEnMovil = array_values(array_filter(
        $indicesConEtiqueta,
        static fn (int $posicion): bool => $posicion % 2 === 0,
        ARRAY_FILTER_USE_KEY,
    ));
@endphp

{{-- El panel se refresca solo: un centro de monitoreo que hay que recargar a mano no monitorea. --}}
<div wire:poll.15s class="space-y-6">
    @if ($this->ingestaDetenida)
        <div class="flex items-start gap-3 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 sm:p-4">
            <flux:icon.exclamation-triangle class="mt-0.5 size-5 shrink-0 text-amber-400" />
            <div class="text-sm">
                <p class="font-semibold text-amber-300">La ingesta parece detenida</p>
                <p class="mt-1 text-zinc-400">
                    @if ($this->ultimoEvento)
                        El evento mas reciente es de {{ $this->ultimoEvento->format('d/m/Y H:i') }}
                        ({{ $this->ultimoEvento->diffForHumans() }}).
                    @else
                        No hay ningun evento registrado todavia.
                    @endif
                    Un tablero en cero puede ser calma o puede ser que nadie este alimentandolo.
                    Verifique el comando <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-800">siem:ingerir-waf</code>.
                </p>
            </div>
        </div>
    @endif

    {{-- Contadores. Cifras sueltas y grandes, sin grafica: el trabajo aqui es leerse de lejos.
         Dos columnas ya en el telefono: cuatro tarjetas apiladas obligarian a desplazarse
         antes de haber visto el primer dato. Cuatro en linea desde lg. --}}
    <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        @php
            $tarjetas = [
                [
                    'titulo' => 'Eventos (24 h)',
                    'valor' => $contadores['eventos_24h'],
                    'pie' => $contadores['permitidos_24h'].' permitidos por el WAF',
                    'icono' => 'signal',
                ],
                [
                    'titulo' => 'Bloqueados (24 h)',
                    'valor' => $contadores['bloqueados_24h'],
                    'pie' => $contadores['eventos_24h'] > 0
                        ? number_format($contadores['bloqueados_24h'] * 100 / $contadores['eventos_24h'], 1).' % del trafico observado'
                        : 'sin trafico observado',
                    'icono' => 'shield-exclamation',
                ],
                [
                    'titulo' => 'Alertas abiertas',
                    'valor' => $contadores['alertas_abiertas'],
                    'pie' => $contadores['alertas_nuevas'].' sin triar',
                    'icono' => 'bell-alert',
                ],
                [
                    'titulo' => 'Direcciones unicas (24 h)',
                    'valor' => $contadores['direcciones_unicas_24h'],
                    'pie' => $contadores['criticos_24h'].' eventos criticos',
                    'icono' => 'globe-americas',
                ],
            ];
        @endphp

        @foreach ($tarjetas as $tarjeta)
            <div class="rounded-xl border border-zinc-200 bg-white p-3 sm:p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-start justify-between gap-2">
                    <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">
                        {{ $tarjeta['titulo'] }}
                    </p>
                    @switch ($tarjeta['icono'])
                        @case('signal')
                            <flux:icon.signal class="size-4 shrink-0 text-zinc-400" />
                        @break

                        @case('shield-exclamation')
                            <flux:icon.shield-exclamation class="size-4 shrink-0 text-zinc-400" />
                        @break

                        @case('bell-alert')
                            <flux:icon.bell-alert class="size-4 shrink-0 text-zinc-400" />
                        @break

                        @default
                            <flux:icon.globe-americas class="size-4 shrink-0 text-zinc-400" />
                    @endswitch
                </div>
                <p class="siem-numero mt-2 text-2xl font-semibold text-white sm:text-3xl">
                    {{ number_format($tarjeta['valor']) }}
                </p>
                <p class="mt-1 text-xs text-zinc-400">{{ $tarjeta['pie'] }}</p>
            </div>
        @endforeach
    </div>

    {{-- Grafica temporal. SVG dibujado en el servidor, sin ninguna libreria externa. --}}
    <figure class="rounded-xl border border-zinc-200 bg-white p-3 sm:p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <figcaption class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <flux:heading size="lg">Eventos por hora</flux:heading>
                <flux:subheading>Ultimas {{ $this->horas }} horas, separando lo que el WAF corto de lo que dejo pasar.</flux:subheading>
            </div>

            <div class="flex w-full flex-wrap items-center justify-between gap-3 sm:w-auto sm:justify-start sm:gap-4">
                <div class="flex items-center gap-4 text-xs text-zinc-300">
                    <span class="flex items-center gap-1.5">
                        <span class="siem-muestra" style="background: var(--siem-serie-bloqueados, #34d399)"></span>
                        Bloqueados
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="siem-muestra" style="background: var(--siem-serie-permitidos, #94a3b8)"></span>
                        Permitidos
                    </span>
                </div>

                <div class="flex flex-wrap items-center gap-1">
                    @foreach (\App\Livewire\Siem\TableroPrincipal::VENTANAS as $ventana)
                        <button
                            type="button"
                            wire:click="cambiarVentana({{ $ventana }})"
                            @class([
                                'inline-flex min-h-11 min-w-11 items-center justify-center rounded-md px-2 text-xs font-medium transition sm:min-h-0 sm:min-w-0 sm:py-1',
                                'bg-white text-zinc-900' => $this->horas === $ventana,
                                'text-zinc-300 hover:bg-zinc-800 hover:text-white' => $this->horas !== $ventana,
                            ])
                        >{{ $ventana }} h</button>
                    @endforeach
                </div>
            </div>
        </figcaption>

        @if ($grafica['sin_datos'])
            <p class="py-12 text-center text-sm text-zinc-400">
                No hay eventos en esta ventana. Ejecute el semillero de demostracion o la ingesta del WAF.
            </p>
        @else
            {{-- El lienzo es proporcional: viewBox mas ancho al cien por cien, nunca un ancho
                 en pixeles. La clase siem-grafica es la que le da a la hoja de estilos el
                 asidero para agrandar la tipografia del eje cuando el SVG se encoge. --}}
            <svg
                viewBox="0 0 {{ $grafica['ancho'] }} {{ $grafica['alto'] }}"
                preserveAspectRatio="xMidYMid meet"
                class="siem-grafica h-auto w-full"
                role="img"
                aria-label="Eventos por hora, bloqueados y permitidos, en las ultimas {{ $this->horas }} horas"
            >
                @foreach ($grafica['rejilla'] as $linea)
                    <line
                        class="siem-rejilla"
                        x1="{{ $grafica['margen_izquierdo'] }}"
                        x2="{{ $grafica['ancho'] - 12 }}"
                        y1="{{ $linea['y'] }}"
                        y2="{{ $linea['y'] }}"
                    />
                    <text class="siem-eje" x="{{ $grafica['margen_izquierdo'] - 8 }}" y="{{ $linea['y'] + 4 }}" text-anchor="end">
                        {{ number_format($linea['valor']) }}
                    </text>
                @endforeach

                @foreach ($grafica['barras'] as $barra)
                    <g class="siem-grupo-barra">
                        <title>{{ $barra['momento'] }} — {{ $barra['bloqueados']['valor'] }} bloqueados, {{ $barra['permitidos']['valor'] }} permitidos</title>

                        @if ($barra['permitidos']['alto'] > 0)
                            <rect
                                class="siem-barra-permitidos"
                                x="{{ $barra['x'] }}"
                                y="{{ $barra['permitidos']['y'] }}"
                                width="{{ $barra['ancho'] }}"
                                height="{{ $barra['permitidos']['alto'] }}"
                                rx="2"
                            />
                        @endif

                        @if ($barra['bloqueados']['alto'] > 0)
                            <rect
                                class="siem-barra-bloqueados"
                                x="{{ $barra['x'] }}"
                                y="{{ $barra['bloqueados']['y'] }}"
                                width="{{ $barra['ancho'] }}"
                                height="{{ $barra['bloqueados']['alto'] }}"
                                rx="2"
                            />
                        @endif

                        @if ($barra['indice'] === $grafica['indice_maximo'] && $barra['total'] > 0)
                            {{-- Solo se etiqueta el pico: un numero sobre cada barra convierte la grafica en una tabla mala. --}}
                            <text
                                class="siem-etiqueta-directa"
                                x="{{ $barra['centro'] }}"
                                y="{{ max($barra['permitidos']['alto'] > 0 ? $barra['permitidos']['y'] : $barra['bloqueados']['y'], 14) - 6 }}"
                                text-anchor="middle"
                            >{{ number_format($barra['total']) }}</text>
                        @endif

                        @if ($barra['mostrar_etiqueta'])
                            <text
                                @class([
                                    'siem-eje',
                                    'siem-solo-ancho' => ! in_array($barra['indice'], $etiquetasEnMovil, true),
                                ])
                                x="{{ $barra['centro'] }}"
                                y="{{ $grafica['linea_base'] + 18 }}"
                                text-anchor="middle"
                            >{{ $barra['etiqueta'] }}</text>
                        @endif
                    </g>
                @endforeach

                <line
                    class="siem-rejilla"
                    x1="{{ $grafica['margen_izquierdo'] }}"
                    x2="{{ $grafica['ancho'] - 12 }}"
                    y1="{{ $grafica['linea_base'] }}"
                    y2="{{ $grafica['linea_base'] }}"
                />
            </svg>
        @endif
    </figure>

    <div class="grid gap-4 lg:grid-cols-2">
        {{-- Reglas mas activadas: donde esta apuntando el atacante y donde hay que afinar el WAF. --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-3 sm:p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg">Reglas mas activadas</flux:heading>
            <flux:subheading>Core Rule Set 4.29 y reglas propias del proyecto (15000-15099).</flux:subheading>

            @if ($reglas['truncado'])
                <p class="mt-2 text-sm text-amber-400 sm:text-xs">
                    El conteo se calculo sobre las filas mas recientes: el volumen de la ventana supera el techo de lectura.
                </p>
            @endif

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 text-left text-xs uppercase tracking-wide text-zinc-400 dark:border-zinc-700">
                            <th class="pb-2 font-medium">Regla</th>
                            <th class="pb-2 text-right font-medium">Activaciones</th>
                            <th class="pb-2 text-right font-medium">Bloqueos</th>
                            {{-- La barra de peso repite en forma lo que ya dice la columna de
                                 activaciones: es lo primero que sobra en un telefono. --}}
                            <th class="hidden pb-2 pl-3 font-medium sm:table-cell">Peso</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($reglas['reglas'] as $regla)
                            @php $maximoRegla = $reglas['reglas'][0]['total'] ?: 1; @endphp
                            <tr>
                                <td class="py-2">
                                    <span class="siem-numero font-medium text-white">{{ $regla['identificador'] }}</span>
                                    @if ($regla['propia'])
                                        <flux:badge size="sm" class="ms-2">propia</flux:badge>
                                    @endif
                                </td>
                                {{-- Las cifras son el contenido de la tabla, no su adorno: van en
                                     texto principal. Los bloqueos ademas en el verde de la serie,
                                     porque que el WAF corte es el resultado que se busca. --}}
                                <td class="siem-numero py-2 text-right font-medium text-zinc-100">{{ number_format($regla['total']) }}</td>
                                <td class="siem-numero py-2 text-right font-medium" style="color: var(--siem-serie-bloqueados, #34d399)">{{ number_format($regla['bloqueados']) }}</td>
                                <td class="hidden w-28 py-2 pl-3 sm:table-cell">
                                    <div class="h-2 w-full rounded-full bg-zinc-100 dark:bg-zinc-800">
                                        <div
                                            class="h-2 rounded-full"
                                            style="width: {{ max(3, round($regla['total'] * 100 / $maximoRegla)) }}%; background: var(--siem-serie-bloqueados, #34d399)"
                                        ></div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-6 text-center text-zinc-400">
                                    Ninguna regla se activo en esta ventana.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Direcciones mas agresivas, ordenadas por bloqueos y no por volumen. --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-3 sm:p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg">Direcciones mas agresivas</flux:heading>
            <flux:subheading>Ordenadas por eventos bloqueados: el volumen solo suele delatar rastreadores legitimos.</flux:subheading>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 text-left text-xs uppercase tracking-wide text-zinc-400 dark:border-zinc-700">
                            <th class="pb-2 font-medium">Direccion</th>
                            {{-- Cinco columnas no caben en un telefono. El pais no desaparece:
                                 se muestra bajo la direccion, y la puntuacion maxima cede el
                                 sitio porque la severidad ya se lee en el reparto de abajo. --}}
                            <th class="hidden pb-2 font-medium sm:table-cell">Pais</th>
                            <th class="pb-2 text-right font-medium">Eventos</th>
                            <th class="pb-2 text-right font-medium">Bloqueos</th>
                            <th class="hidden pb-2 text-right font-medium sm:table-cell">Puntuacion</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($this->direcciones as $direccion)
                            <tr>
                                <td class="py-2 pr-2">
                                    <span class="siem-numero block break-all font-medium text-white">{{ $direccion->direccion_ip }}</span>
                                    <span class="block text-xs text-zinc-400 sm:hidden">{{ $direccion->pais ?? 'sin dato' }}</span>
                                </td>
                                <td class="hidden py-2 text-zinc-400 sm:table-cell">{{ $direccion->pais ?? 'sin dato' }}</td>
                                <td class="siem-numero py-2 text-right font-medium text-zinc-100">{{ number_format((int) $direccion->total) }}</td>
                                <td class="siem-numero py-2 text-right font-medium" style="color: var(--siem-serie-bloqueados, #34d399)">
                                    {{ number_format((int) $direccion->bloqueados) }}
                                </td>
                                <td class="siem-numero hidden py-2 text-right font-medium text-zinc-100 sm:table-cell">{{ (int) $direccion->puntuacion_maxima }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-6 text-center text-zinc-400">
                                    Sin actividad registrada en esta ventana.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <p class="mt-3 text-sm text-zinc-400 sm:text-xs">
                El pais proviene del encabezado CF-IPCountry que anade Cloudflare en la capa 1. Cuando la
                peticion no pasa por el perimetro se muestra "sin dato" en vez de suponerlo.
            </p>
        </div>
    </div>

    {{-- Reparto de severidad: una franja, no una grafica de pastel. --}}
    @php $totalReparto = array_sum($this->reparto); @endphp
    @if ($totalReparto > 0)
        <div class="rounded-xl border border-zinc-200 bg-white p-3 sm:p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg">Reparto por severidad</flux:heading>
            <flux:subheading>Sobre {{ number_format($totalReparto) }} eventos de las ultimas {{ $this->horas }} horas.</flux:subheading>

            <div class="mt-4 flex h-3 w-full gap-0.5 overflow-hidden rounded-full">
                @foreach ($this->reparto as $severidad => $cantidad)
                    @if ($cantidad > 0)
                        <div
                            class="h-3"
                            style="width: {{ $cantidad * 100 / $totalReparto }}%; background: var(--siem-{{ $severidad }})"
                            title="{{ $severidad }}: {{ number_format($cantidad) }}"
                        ></div>
                    @endif
                @endforeach
            </div>

            <div class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-xs">
                @foreach ($this->reparto as $severidad => $cantidad)
                    <span class="flex items-center gap-1.5 text-zinc-300">
                        <span class="siem-muestra" style="background: var(--siem-{{ $severidad }})"></span>
                        {{-- Sin pastilla, pero con el color propio de cada severidad: asi la
                             proporcion se capta de un vistazo y la palabra sigue estando. --}}
                        <x-pages::siem.severidad :valor="$severidad" class="siem-sev-suelta" />
                        <span class="siem-numero font-medium text-zinc-100">{{ number_format($cantidad) }}</span>
                    </span>
                @endforeach
            </div>
        </div>
    @endif
</div>
