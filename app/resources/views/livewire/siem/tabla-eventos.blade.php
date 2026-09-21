@php $eventos = $this->eventos; @endphp

<div wire:poll.20s class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <flux:heading size="lg">Ultimos eventos</flux:heading>
            <flux:subheading>
                {{ number_format($this->totalFiltrado) }} eventos coinciden con el filtro actual.
            </flux:subheading>
        </div>

        <flux:button size="sm" variant="ghost" wire:click="limpiarFiltros" icon="arrow-path">
            Limpiar filtros
        </flux:button>
    </div>

    {{-- Los filtros van en una sola fila sobre la tabla y no en una barra lateral: en un
         turno de guardia se ajustan constantemente y deben quedar bajo la mano. --}}
    <div class="grid gap-3 rounded-xl border border-zinc-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-6 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:select wire:model.live="fuente" label="Fuente" size="sm">
            <flux:select.option value="">Todas</flux:select.option>
            @foreach ($this->fuentesDisponibles() as $clave => $etiqueta)
                <flux:select.option value="{{ $clave }}">{{ $etiqueta }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="severidad" label="Severidad" size="sm">
            <flux:select.option value="">Todas</flux:select.option>
            @foreach (\App\Models\EventoSeguridad::ESCALA_SEVERIDAD as $severidad)
                <flux:select.option value="{{ $severidad }}">{{ ucfirst($severidad) }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="bloqueo" label="Resultado" size="sm">
            <flux:select.option value="">Todos</flux:select.option>
            <flux:select.option value="bloqueados">Bloqueados</flux:select.option>
            <flux:select.option value="permitidos">Permitidos</flux:select.option>
        </flux:select>

        <flux:select wire:model.live="horas" label="Ventana" size="sm">
            @foreach (\App\Livewire\Siem\TablaEventos::VENTANAS as $ventana)
                <flux:select.option value="{{ $ventana }}">
                    {{ $ventana < 24 ? $ventana.' h' : ($ventana / 24).' d' }}
                </flux:select.option>
            @endforeach
        </flux:select>

        <flux:input wire:model.live.debounce.400ms="direccionIp" label="Direccion IP" placeholder="203.0.113." size="sm" />

        <flux:input wire:model.live.debounce.400ms="busqueda" label="Buscar" placeholder="ruta, regla o mensaje" size="sm" />
    </div>

    <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <table class="w-full min-w-[56rem] text-sm">
            <thead>
                <tr class="border-b border-zinc-200 text-left text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                    <th class="px-4 py-3 font-medium">Momento</th>
                    <th class="px-4 py-3 font-medium">Fuente</th>
                    <th class="px-4 py-3 font-medium">Direccion</th>
                    <th class="px-4 py-3 font-medium">Peticion</th>
                    <th class="px-4 py-3 font-medium">Regla</th>
                    <th class="px-4 py-3 text-right font-medium">Puntuacion</th>
                    <th class="px-4 py-3 font-medium">Severidad</th>
                    <th class="px-4 py-3 font-medium">Resultado</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($eventos as $evento)
                    <tr class="align-top hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <td class="siem-numero whitespace-nowrap px-4 py-3 text-zinc-600 dark:text-zinc-300">
                            {{ $evento->marca_tiempo?->format('d/m H:i:s') }}
                            @if ($evento->es_demostracion)
                                <flux:badge size="sm" class="ms-1">demo</flux:badge>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <span class="text-zinc-900 dark:text-white">{{ ucfirst($evento->fuente) }}</span>
                            <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $evento->subfuente }}</span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            {{-- La direccion viaja por Js::from y no entre comillas a mano: lo que
                                 hay en esa columna lo escribio un atacante, y wire:click se evalua
                                 como expresion. Concatenarla seria abrir un hueco de inyeccion en
                                 la unica pantalla del proyecto que muestra cargas utiles hostiles. --}}
                            <button
                                type="button"
                                wire:click="filtrarPorIp({{ \Illuminate\Support\Js::from($evento->direccion_ip) }})"
                                class="siem-numero font-medium text-zinc-900 underline-offset-2 hover:underline dark:text-white"
                                title="Filtrar por esta direccion"
                            >{{ $evento->direccion_ip }}</button>
                            <span class="block text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $evento->pais ?? 'sin pais' }}
                                @if ($evento->usuario)
                                    · {{ $evento->usuario->name }}
                                @endif
                            </span>
                        </td>
                        <td class="max-w-sm px-4 py-3">
                            <span class="siem-numero text-xs font-semibold text-zinc-500 dark:text-zinc-400">{{ $evento->metodo }}</span>
                            <span class="break-all text-zinc-800 dark:text-zinc-200">{{ \Illuminate\Support\Str::limit($evento->ruta ?? '—', 70) }}</span>
                            <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">{{ \Illuminate\Support\Str::limit($evento->mensaje ?? '', 90) }}</span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            @php $reglaPrincipal = $evento->reglaPrincipal(); @endphp
                            @if ($reglaPrincipal)
                                <span class="siem-numero font-medium">{{ $reglaPrincipal }}</span>
                                @if (count($evento->identificadores_regla ?? []) > 1)
                                    <span class="block text-xs text-zinc-500 dark:text-zinc-400">
                                        +{{ count($evento->identificadores_regla) - 1 }} mas
                                    </span>
                                @endif
                            @else
                                <span class="text-zinc-400 dark:text-zinc-500">—</span>
                            @endif
                        </td>
                        <td class="siem-numero px-4 py-3 text-right font-medium">
                            {{ $evento->puntuacion_anomalia }}
                        </td>
                        <td class="px-4 py-3">
                            <x-pages::siem.severidad :valor="$evento->severidad" />
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            @if ($evento->fue_bloqueado)
                                <span class="inline-flex items-center gap-1 text-xs font-semibold" style="color: var(--siem-serie-bloqueados)">
                                    <flux:icon.no-symbol class="size-3.5" />
                                    Bloqueado
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    <flux:icon.arrow-right class="size-3.5" />
                                    Permitido
                                </span>
                            @endif
                            @if ($evento->codigo_respuesta)
                                <span class="siem-numero block text-xs text-zinc-500 dark:text-zinc-400">HTTP {{ $evento->codigo_respuesta }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-12 text-center text-zinc-500 dark:text-zinc-400">
                            Ningun evento coincide con el filtro. Amplie la ventana o limpie los filtros.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($eventos->hasPages())
        <div>{{ $eventos->links() }}</div>
    @endif
</div>
