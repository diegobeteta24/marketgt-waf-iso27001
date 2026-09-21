@php
    $incidentes = $this->incidentes;
    $conteos = $this->conteos;
@endphp

<div wire:poll.20s class="space-y-4">
    <div class="grid gap-3 rounded-xl border border-zinc-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:select wire:model.live="tipoFiltro" label="Tipo de ataque" size="sm">
            <flux:select.option value="">Todos</flux:select.option>
            @foreach (\App\Models\IncidenteSeo::ETIQUETAS_TIPO as $clave => $etiqueta)
                <flux:select.option value="{{ $clave }}">
                    {{ $etiqueta }} ({{ $conteos[$clave] ?? 0 }})
                </flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="estadoFiltro" label="Estado" size="sm">
            <flux:select.option value="abiertos">Abiertos</flux:select.option>
            <flux:select.option value="todos">Todos</flux:select.option>
            @foreach (\App\Models\IncidenteSeo::ETIQUETAS_ESTADO as $clave => $etiqueta)
                <flux:select.option value="{{ $clave }}">{{ $etiqueta }}</flux:select.option>
            @endforeach
        </flux:select>

        <div class="sm:col-span-2 lg:col-span-2">
            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                Cada fila se agrupa por origen y día: la columna «veces» dice cuántas peticiones idénticas
                llegaron, para que una campaña de mil intentos no tape los demás hallazgos.
                Los mismos eventos viajan a <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">storage/logs/seguridad.log</code>
                en formato JSON por línea, que es lo que ingiere el SIEM.
            </p>
        </div>
    </div>

    <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <table class="w-full min-w-[60rem] text-sm">
            <thead>
                <tr class="border-b border-zinc-200 text-left text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                    <th class="px-4 py-3 font-medium">Momento</th>
                    <th class="px-4 py-3 font-medium">Tipo</th>
                    <th class="px-4 py-3 font-medium">Severidad</th>
                    <th class="px-4 py-3 font-medium">Origen</th>
                    <th class="px-4 py-3 font-medium">Ruta</th>
                    <th class="px-4 py-3 font-medium">Regla</th>
                    <th class="px-4 py-3 text-right font-medium">Veces</th>
                    <th class="px-4 py-3 font-medium">Estado</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($incidentes as $incidente)
                    <tr
                        class="cursor-pointer align-top hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                        wire:click="seleccionar({{ $incidente->id }})"
                        wire:key="incidente-{{ $incidente->id }}"
                    >
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums text-zinc-600 dark:text-zinc-300">
                            {{ $incidente->ultima_vez_en->format('d/m H:i:s') }}
                        </td>
                        <td class="px-4 py-3 font-medium text-zinc-900 dark:text-white">{{ $incidente->etiquetaTipo() }}</td>
                        <td class="px-4 py-3">
                            <flux:badge size="sm" :color="match ($incidente->severidad) {
                                'critica' => 'red',
                                'alta' => 'orange',
                                'media' => 'amber',
                                default => 'zinc',
                            }">{{ ucfirst($incidente->severidad) }}</flux:badge>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-zinc-600 dark:text-zinc-300">
                            {{ $incidente->direccion_ip ?? '—' }}
                        </td>
                        <td class="max-w-[16rem] truncate px-4 py-3 text-zinc-600 dark:text-zinc-300">
                            /{{ ltrim((string) $incidente->ruta, '/') }}
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-zinc-600 dark:text-zinc-300">{{ $incidente->regla }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-zinc-900 dark:text-white">
                            {{ number_format($incidente->repeticiones) }}
                        </td>
                        <td class="px-4 py-3">
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
                            <td colspan="8" class="px-4 py-4">
                                <div class="grid gap-4 lg:grid-cols-3">
                                    <div class="lg:col-span-2 space-y-3">
                                        <div>
                                            <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Resumen</p>
                                            <p class="mt-1 text-sm text-zinc-800 dark:text-zinc-100">{{ $incidente->resumen }}</p>
                                        </div>

                                        <div>
                                            <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Evidencia</p>
                                            {{-- La evidencia se imprime como texto escapado: es contenido del atacante
                                                 y nunca se interpreta como HTML dentro del propio panel. --}}
                                            <pre class="mt-1 max-h-80 overflow-auto rounded-lg bg-zinc-900 p-3 text-xs leading-relaxed text-zinc-100">{{ json_encode($incidente->detalle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                        </div>

                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                            <p>Primera vez: {{ $incidente->primera_vez_en->format('d/m/Y H:i:s') }}</p>
                                            <p>Agente: <span class="font-mono">{{ \Illuminate\Support\Str::limit((string) $incidente->agente_usuario, 120) }}</span></p>
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

                                        <div class="flex flex-wrap gap-2">
                                            <flux:button size="sm" variant="ghost" wire:click.stop="guardarNotas({{ $incidente->id }})">
                                                Guardar nota
                                            </flux:button>

                                            @foreach ($this->transicionesDe($incidente) as $destino)
                                                <flux:button
                                                    size="sm"
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
                        <td colspan="8" class="px-4 py-10 text-center text-zinc-500 dark:text-zinc-400">
                            Sin incidentes con el filtro actual.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
