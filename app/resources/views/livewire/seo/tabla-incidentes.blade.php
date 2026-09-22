@php
    $incidentes = $this->incidentes;
    $conteos = $this->conteos;
@endphp

<div wire:poll.20s class="space-y-4">
    <div class="grid gap-3 rounded-xl border border-zinc-200 bg-white p-3 sm:grid-cols-2 sm:p-4 lg:grid-cols-4 dark:border-zinc-700 dark:bg-zinc-900">
        {{-- Sin size="sm": ese tamaño deja el control en 32 px de alto y con letra de
             14 px, y Safari de iOS amplía la página sola al enfocar un campo con letra
             menor de 16 px, dejándola además desplazada de lado. Con el tamaño normal
             de Flux son 40 px y 16 px de letra en móvil; min-h-11 completa los 44 px que
             necesita un dedo y desde sm se recupera la altura compacta de siempre. --}}
        <flux:select wire:model.live="tipoFiltro" label="Tipo de ataque" class="min-h-11 sm:min-h-0">
            <flux:select.option value="">Todos</flux:select.option>
            @foreach (\App\Models\IncidenteSeo::ETIQUETAS_TIPO as $clave => $etiqueta)
                <flux:select.option value="{{ $clave }}">
                    {{ $etiqueta }} ({{ $conteos[$clave] ?? 0 }})
                </flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="estadoFiltro" label="Estado" class="min-h-11 sm:min-h-0">
            <flux:select.option value="abiertos">Abiertos</flux:select.option>
            <flux:select.option value="todos">Todos</flux:select.option>
            @foreach (\App\Models\IncidenteSeo::ETIQUETAS_ESTADO as $clave => $etiqueta)
                <flux:select.option value="{{ $clave }}">{{ $etiqueta }}</flux:select.option>
            @endforeach
        </flux:select>

        <div class="sm:col-span-2 lg:col-span-2">
            <p class="break-words text-sm text-zinc-500 dark:text-zinc-400 sm:text-xs">
                Cada fila se agrupa por origen y día: la columna «veces» dice cuántas peticiones idénticas
                llegaron, para que una campaña de mil intentos no tape los demás hallazgos.
                Los mismos eventos viajan a <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">storage/logs/seguridad.log</code>
                en formato JSON por línea, que es lo que ingiere el SIEM.
            </p>
        </div>
    </div>

    {{-- El ancho mínimo vive en la tabla y crece por tramos, nunca en el contenedor que
         desplaza. En móvil la tabla no fuerza ancho alguno porque solo quedan visibles las
         tres columnas esenciales (momento, tipo y origen); las demás van apareciendo
         conforme hay sitio, para no obligar a arrastrar la pantalla de lado. --}}
    <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <table class="w-full text-sm sm:min-w-[36rem] md:min-w-[44rem] lg:min-w-[52rem] xl:min-w-[60rem]">
            <thead>
                <tr class="border-b border-zinc-200 text-left text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                    <th class="px-3 py-3 font-medium sm:px-4">Momento</th>
                    <th class="px-3 py-3 font-medium sm:px-4">Tipo</th>
                    <th class="hidden px-3 py-3 font-medium sm:table-cell sm:px-4">Severidad</th>
                    <th class="px-3 py-3 font-medium sm:px-4">Origen</th>
                    <th class="hidden px-3 py-3 font-medium lg:table-cell sm:px-4">Ruta</th>
                    <th class="hidden px-3 py-3 font-medium xl:table-cell sm:px-4">Regla</th>
                    <th class="hidden px-3 py-3 text-right font-medium md:table-cell sm:px-4">Veces</th>
                    <th class="hidden px-3 py-3 font-medium sm:table-cell sm:px-4">Estado</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($incidentes as $incidente)
                    <tr
                        class="cursor-pointer align-top hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                        wire:click="seleccionar({{ $incidente->id }})"
                        wire:key="incidente-{{ $incidente->id }}"
                    >
                        {{-- text-xs en móvil (dato tabular, no texto de lectura): la marca de
                             tiempo no puede envolverse y así deja sitio a las otras dos columnas. --}}
                        <td class="whitespace-nowrap px-3 py-3 text-xs tabular-nums text-zinc-600 dark:text-zinc-300 sm:px-4 sm:text-sm">
                            {{ $incidente->ultima_vez_en->format('d/m H:i:s') }}
                        </td>
                        <td class="px-3 py-3 font-medium text-zinc-900 dark:text-white sm:px-4">{{ $incidente->etiquetaTipo() }}</td>
                        <td class="hidden px-3 py-3 sm:table-cell sm:px-4">
                            <flux:badge size="sm" :color="match ($incidente->severidad) {
                                'critica' => 'red',
                                'alta' => 'orange',
                                'media' => 'amber',
                                'baja' => 'sky',
                                default => 'zinc',
                            }">{{ ucfirst($incidente->severidad) }}</flux:badge>
                        </td>
                        {{-- break-all: una dirección IPv6 son 39 caracteres y desbordaría la fila en móvil. --}}
                        <td class="break-all px-3 py-3 font-mono text-xs text-zinc-600 dark:text-zinc-300 sm:px-4">
                            {{ $incidente->direccion_ip ?? '—' }}
                        </td>
                        <td class="hidden max-w-[16rem] truncate px-3 py-3 text-zinc-600 lg:table-cell dark:text-zinc-300 sm:px-4">
                            /{{ ltrim((string) $incidente->ruta, '/') }}
                        </td>
                        <td class="hidden px-3 py-3 font-mono text-xs text-zinc-600 xl:table-cell dark:text-zinc-300 sm:px-4">{{ $incidente->regla }}</td>
                        <td class="hidden px-3 py-3 text-right tabular-nums text-zinc-900 md:table-cell dark:text-white sm:px-4">
                            {{ number_format($incidente->repeticiones) }}
                        </td>
                        <td class="hidden px-3 py-3 sm:table-cell sm:px-4">
                            <flux:badge size="sm" :color="match ($incidente->estado) {
                                'nuevo' => 'amber',
                                'confirmado' => 'red',
                                'falso_positivo' => 'zinc',
                                default => 'green',
                            }">{{ $incidente->etiquetaEstado() }}</flux:badge>
                        </td>
                    </tr>

                    @if ($this->seleccionado === $incidente->id)
                        <tr wire:key="detalle-{{ $incidente->id }}" class="bg-zinc-50 dark:bg-zinc-800/40">
                            <td colspan="8" class="px-3 py-4 sm:px-4">
                                <div class="grid gap-4 lg:grid-cols-3">
                                    <div class="min-w-0 lg:col-span-2 space-y-3">
                                        <div>
                                            <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Resumen</p>
                                            <p class="mt-1 break-words text-sm text-zinc-800 dark:text-zinc-100">{{ $incidente->resumen }}</p>
                                        </div>

                                        <div>
                                            <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Evidencia</p>
                                            {{-- La evidencia se imprime como texto escapado: es contenido del atacante
                                                 y nunca se interpreta como HTML dentro del propio panel.
                                                 Se envuelve con break-all en vez de desplazarse de lado: una carga
                                                 útil de ataque es una sola línea larguísima y en un teléfono
                                                 desplazarla horizontalmente dentro de una celda no hay quien lo use. --}}
                                            <pre class="mt-1 max-h-80 overflow-auto whitespace-pre-wrap break-all rounded-lg border border-zinc-700 bg-zinc-950 p-3 text-xs leading-relaxed text-zinc-100">{{ json_encode($incidente->detalle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                        </div>

                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                            <p>Primera vez: {{ $incidente->primera_vez_en->format('d/m/Y H:i:s') }}</p>
                                            <p class="break-words">Agente: <span class="break-all font-mono">{{ \Illuminate\Support\Str::limit((string) $incidente->agente_usuario, 120) }}</span></p>
                                            @if ($incidente->revisor)
                                                <p>Revisado por {{ $incidente->revisor->name }} el {{ $incidente->revisado_en?->format('d/m/Y H:i') }}</p>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="space-y-3">
                                        <flux:textarea
                                            wire:model="notas"
                                            label="Nota de triaje"
                                            rows="4"
                                            placeholder="Qué se comprobó y qué se decidió."
                                        />

                                        {{-- Botones apilados y a ancho completo en móvil, en línea desde sm.
                                             min-h-11 asegura los 44 px que necesita un dedo. --}}
                                        <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                                            <flux:button size="sm" variant="ghost" class="w-full min-h-11 sm:w-auto sm:min-h-0" wire:click.stop="guardarNotas({{ $incidente->id }})">
                                                Guardar nota
                                            </flux:button>

                                            @foreach ($this->transicionesDe($incidente) as $destino)
                                                <flux:button
                                                    size="sm"
                                                    class="w-full min-h-11 sm:w-auto sm:min-h-0"
                                                    :variant="$destino === 'confirmado' ? 'danger' : 'filled'"
                                                    wire:click.stop="cambiarEstado({{ $incidente->id }}, '{{ $destino }}')"
                                                >
                                                    {{ \App\Models\IncidenteSeo::ETIQUETAS_ESTADO[$destino] ?? $destino }}
                                                </flux:button>
                                            @endforeach
                                        </div>

                                        @if ($this->transicionesDe($incidente) === [])
                                            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                                Este incidente ya llegó al final de su ciclo. Si el ataque se repite,
                                                se reabre solo: cerrar algo que sigue pasando es como se pierde de vista.
                                            </p>
                                        @endif
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="8" class="px-3 py-10 text-center text-zinc-500 dark:text-zinc-400 sm:px-4">
                            Sin incidentes con el filtro actual.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
