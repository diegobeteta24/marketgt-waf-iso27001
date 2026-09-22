@php
    $resultado = $this->resultado;
    $resumen = $resultado['resumen'] ?? null;
    $servicio = \App\Services\Seo\AnalizadorConsultas::class;
@endphp

<div class="space-y-6">
    {{-- ------------------------------------------------------------------ --}}
    {{-- Por qué existe esta pantalla. Es el guion de la demostración.       --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 p-3 dark:border-zinc-700 sm:p-4">
            <flux:heading size="lg">Análisis de consultas de búsqueda</flux:heading>
            <flux:subheading>
                Las dos capas anteriores vigilan lo que entra por el formulario. Cuando el atacante ya
                tiene acceso al servidor no usa el formulario, y entonces el único que ve el contenido
                inyectado es Google. Esta pantalla lee lo que Google informa y señala las consultas que
                no pertenecen al negocio.
            </flux:subheading>
        </div>

        <div class="p-3 text-sm text-zinc-600 dark:text-zinc-300 sm:p-4">
            <p class="break-words">
                Caso real de una empresa guatemalteca que vende cámaras de seguridad: junto a sus consultas
                legítimas, Search Console mostraba <span class="font-mono">p9bet login</span>,
                <span class="font-mono">0016bet</span>, <span class="font-mono">96n.com</span>,
                <span class="font-mono">kmj888</span> y <span class="font-mono">porh300</span>, marcas de
                casas de apuestas asiáticas. Cuarenta y cinco impresiones y ningún clic: nadie que busca
                una casa de apuestas quiere una empresa de cámaras, así que lo que Google enseñaba de ese
                dominio no era lo que el dominio es.
            </p>
        </div>
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Entrada: el pegado y el criterio con el que se juzga               --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 p-3 dark:border-zinc-700 sm:p-4">
            <flux:heading size="lg">1 · Pegue las consultas de Search Console</flux:heading>
            <flux:subheading>
                Exporte el informe de rendimiento a CSV o copie la tabla de la pantalla. Se aceptan
                columnas separadas por tabulador o por coma en el orden de Search Console
                (consulta, clics, impresiones) y también una consulta suelta por línea.
            </flux:subheading>
        </div>

        <div class="grid gap-4 p-3 sm:p-4 lg:grid-cols-3">
            <div class="min-w-0 space-y-3 lg:col-span-2">
                {{-- En móvil se deja el tamaño de letra que trae Flux: Safari de iOS amplía
                     la página sola al enfocar un campo con letra menor de 16 px. --}}
                <flux:textarea
                    wire:model="pegado"
                    label="Consultas"
                    rows="14"
                    class="font-mono sm:text-xs"
                    placeholder="camaras de seguridad guatemala&#9;28&#9;1320"
                />

                <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                    <flux:button size="sm" variant="primary" class="w-full min-h-11 sm:w-auto sm:min-h-0" wire:click="analizar" icon="magnifying-glass">
                        Analizar
                    </flux:button>
                    <flux:button size="sm" variant="ghost" class="w-full min-h-11 sm:w-auto sm:min-h-0" wire:click="cargarEjemplo" icon="arrow-path">
                        Cargar el caso real
                    </flux:button>
                    <flux:button size="sm" variant="ghost" class="w-full min-h-11 sm:w-auto sm:min-h-0" wire:click="limpiar" icon="x-mark">
                        Vaciar
                    </flux:button>
                    <span class="text-sm text-zinc-500 dark:text-zinc-400 sm:text-xs">
                        Analizar no guarda nada.
                    </span>
                </div>
            </div>

            <div class="min-w-0 space-y-3">
                <flux:textarea
                    wire:model="vocabulario"
                    label="Vocabulario del negocio"
                    rows="4"
                    class="sm:text-xs"
                    description="Separado por comas. Una consulta que no comparte ningún término con esta lista no describe lo que el sitio vende."
                />

                <flux:input wire:model="marca" label="Marca" class:input="min-h-11 sm:min-h-0" />

                <flux:input
                    wire:model="dominioPropio"
                    label="Dominio propio"
                    class:input="font-mono min-h-11 sm:min-h-0"
                    description="Sirve para distinguir cuándo la consulta es OTRO dominio."
                />

                <flux:input
                    wire:model="minimoImpresiones"
                    type="number"
                    label="Mínimo de impresiones"
                    class:input="min-h-11 sm:min-h-0"
                    description="Por debajo de esto la tasa de clics no significa nada: una impresión y cero clics es ruido, no una anomalía."
                />

                @error('pegado')
                    <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </div>

    @if ($resumen !== null)
        {{-- -------------------------------------------------------------- --}}
        {{-- Resumen. Cuatro cifras, una por columna en escritorio y una    --}}
        {{-- debajo de otra en el teléfono.                                 --}}
        {{-- -------------------------------------------------------------- --}}
        <div class="grid gap-3 grid-cols-1 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900 sm:p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Consultas analizadas</p>
                <p class="mt-1 text-3xl font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $resumen['analizadas'] }}</p>
                @if ($resumen['lineas_descartadas'] > 0)
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        {{ $resumen['lineas_descartadas'] }} líneas descartadas (encabezados o totales)
                    </p>
                @endif
            </div>

            <div class="rounded-xl border border-amber-500/40 bg-amber-500/10 p-3 sm:p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">Sospechosas</p>
                <p class="mt-1 text-3xl font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $resumen['sospechosas'] }}</p>
                <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">{{ $resumen['porcentaje'] }} % del total</p>
            </div>

            <div class="rounded-xl border border-red-500/40 bg-red-500/10 p-3 sm:p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-red-700 dark:text-red-300">Envenenadas</p>
                <p class="mt-1 text-3xl font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $resumen['envenenadas'] }}</p>
                <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">Desde {{ $servicio::UMBRAL_ENVENENADA }} puntos</p>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900 sm:p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Impresiones en riesgo</p>
                <p class="mt-1 text-3xl font-semibold tabular-nums text-zinc-900 dark:text-white">{{ number_format($resumen['impresiones_sospechosas']) }}</p>
                <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">Veces que el dominio se mostró por algo ajeno</p>
            </div>
        </div>

        {{-- -------------------------------------------------------------- --}}
        {{-- Resultado por consulta, de mayor a menor puntuación            --}}
        {{-- -------------------------------------------------------------- --}}
        <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex flex-col gap-3 border-b border-zinc-200 p-3 dark:border-zinc-700 sm:flex-row sm:items-center sm:justify-between sm:p-4">
                <div class="min-w-0">
                    <flux:heading size="lg">2 · Veredicto por consulta</flux:heading>
                    <flux:subheading>
                        Ninguna señal decide sola. Se suman, y la suma es la que separa una consulta rara
                        de un dominio con contenido ajeno indexado.
                    </flux:subheading>
                </div>

                <flux:button
                    size="sm"
                    variant="danger"
                    class="w-full min-h-11 sm:w-auto sm:min-h-0"
                    wire:click="registrarHallazgos"
                    icon="shield-exclamation"
                >
                    Registrar hallazgos
                </flux:button>
            </div>

            <div class="px-3 pt-3 text-xs text-zinc-500 dark:text-zinc-400 sm:px-4">
                Registrar guarda las consultas sospechosas y abre incidente por las envenenadas, con la
                misma línea en la bitácora que lee el SIEM (regla {{ $servicio::REGLA }}).
                @if ($this->registrado)
                    <span class="font-semibold text-emerald-600 dark:text-emerald-400">Ya registrado.</span>
                @endif
            </div>

            {{-- La tabla es ancha por naturaleza: cinco columnas y una lista de señales. En
                 el teléfono se desplaza de lado en vez de apretarse hasta ser ilegible. --}}
            <div class="overflow-hidden p-3 sm:p-4">
                <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <table class="w-full min-w-[46rem] text-xs">
                        <thead class="bg-zinc-50 text-left uppercase tracking-wide text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                            <tr>
                                <th class="px-3 py-2 font-medium">Consulta</th>
                                <th class="px-3 py-2 text-right font-medium">Clics</th>
                                <th class="px-3 py-2 text-right font-medium">Impresiones</th>
                                <th class="px-3 py-2 text-right font-medium">Tasa</th>
                                <th class="px-3 py-2 text-right font-medium">Puntos</th>
                                <th class="px-3 py-2 font-medium">Veredicto</th>
                                <th class="px-3 py-2 font-medium">Señales</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse ($resultado['filas'] as $fila)
                                <tr @class([
                                    'bg-red-500/5' => $fila['veredicto'] === $servicio::VEREDICTO_ENVENENADA,
                                    'bg-amber-500/5' => $fila['veredicto'] === $servicio::VEREDICTO_REVISAR,
                                ])>
                                    {{-- La consulta es texto de un tercero: puede ser una cadena
                                         larguísima sin espacios, así que se corta por donde sea. --}}
                                    <td class="break-all px-3 py-2 font-mono text-zinc-900 dark:text-white">{{ $fila['consulta'] }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-zinc-600 dark:text-zinc-300">{{ $fila['clics'] ?? '—' }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-zinc-600 dark:text-zinc-300">{{ $fila['impresiones'] ?? '—' }}</td>
                                    <td @class([
                                        'px-3 py-2 text-right tabular-nums',
                                        'font-semibold text-red-600 dark:text-red-400' => $fila['tasa_clics'] !== null && $fila['tasa_clics'] <= 0.0,
                                        'text-zinc-600 dark:text-zinc-300' => ! ($fila['tasa_clics'] !== null && $fila['tasa_clics'] <= 0.0),
                                    ])>
                                        {{ $fila['tasa_clics'] === null ? '—' : round($fila['tasa_clics'] * 100, 2).' %' }}
                                    </td>
                                    <td class="px-3 py-2 text-right text-sm font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $fila['puntuacion'] }}</td>
                                    <td class="px-3 py-2">
                                        <flux:badge size="sm" :color="match ($fila['veredicto']) {
                                            $servicio::VEREDICTO_ENVENENADA => 'red',
                                            $servicio::VEREDICTO_REVISAR => 'amber',
                                            default => 'green',
                                        }">
                                            {{ ucfirst($fila['veredicto']) }}
                                        </flux:badge>
                                    </td>
                                    <td class="px-3 py-2">
                                        @if ($fila['senales'] === [])
                                            <span class="text-zinc-400">Ninguna</span>
                                        @else
                                            <ul class="space-y-1">
                                                @foreach ($fila['senales'] as $senal)
                                                    <li class="break-words text-zinc-600 dark:text-zinc-300">
                                                        <span class="font-mono text-[11px] text-zinc-500 dark:text-zinc-400">+{{ $senal['puntos'] }}</span>
                                                        {{ $senal['descripcion'] }}
                                                        <span class="block break-all font-mono text-[11px] text-zinc-400">{{ $senal['evidencia'] }}</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-3 py-6 text-center text-zinc-500 dark:text-zinc-400">
                                        No se reconoció ninguna consulta en el pegado.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="border-t border-zinc-200 p-3 text-xs text-zinc-500 dark:border-zinc-700 dark:text-zinc-400 sm:p-4">
                Umbrales: desde {{ $servicio::UMBRAL_REVISION }} puntos se pide revisión humana y desde
                {{ $servicio::UMBRAL_ENVENENADA }} se da por contenido ajeno indexado. El patrón de marca
                de apuestas es una heurística sobre la FORMA de la cadena, no una prueba del sector: por eso
                suma puntos en lugar de decidir, y por eso nunca llega sola al veredicto de envenenada.
            </div>
        </div>
    @else
        <div class="rounded-xl border border-dashed border-zinc-300 p-6 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
            Pulse <span class="font-semibold">Analizar</span> para ver el veredicto de cada consulta.
        </div>
    @endif
</div>
