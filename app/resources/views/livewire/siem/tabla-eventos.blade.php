@php $eventos = $this->eventos; @endphp

<div wire:poll.20s class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
            <flux:heading size="lg">Ultimos eventos</flux:heading>
            <flux:subheading>
                {{ number_format($this->totalFiltrado) }} eventos coinciden con el filtro actual.
            </flux:subheading>
        </div>

        {{-- A ancho completo y con alto de dedo en el telefono; compacto desde sm. --}}
        <flux:button size="sm" variant="ghost" wire:click="limpiarFiltros" icon="arrow-path" class="min-h-11 w-full sm:min-h-0 sm:w-auto">
            Limpiar filtros
        </flux:button>
    </div>

    {{-- Los filtros van en una sola fila sobre la tabla y no en una barra lateral: en un
         turno de guardia se ajustan constantemente y deben quedar bajo la mano. En el
         telefono esa fila se convierte en una columna: seis campos en linea no caben. --}}
    <div class="grid gap-3 rounded-xl border border-zinc-200 bg-white p-3 sm:grid-cols-2 sm:p-4 lg:grid-cols-3 xl:grid-cols-6 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:select wire:model.live="fuente" label="Fuente" size="sm" class="min-h-11 text-base sm:min-h-0 sm:text-sm">
            <flux:select.option value="">Todas</flux:select.option>
            @foreach ($this->fuentesDisponibles() as $clave => $etiqueta)
                <flux:select.option value="{{ $clave }}">{{ $etiqueta }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="severidad" label="Severidad" size="sm" class="min-h-11 text-base sm:min-h-0 sm:text-sm">
            <flux:select.option value="">Todas</flux:select.option>
            @foreach (\App\Models\EventoSeguridad::ESCALA_SEVERIDAD as $severidad)
                <flux:select.option value="{{ $severidad }}">{{ ucfirst($severidad) }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="bloqueo" label="Resultado" size="sm" class="min-h-11 text-base sm:min-h-0 sm:text-sm">
            <flux:select.option value="">Todos</flux:select.option>
            <flux:select.option value="bloqueados">Bloqueados</flux:select.option>
            <flux:select.option value="permitidos">Permitidos</flux:select.option>
        </flux:select>

        <flux:select wire:model.live="horas" label="Ventana" size="sm" class="min-h-11 text-base sm:min-h-0 sm:text-sm">
            @foreach (\App\Livewire\Siem\TablaEventos::VENTANAS as $ventana)
                <flux:select.option value="{{ $ventana }}">
                    {{ $ventana < 24 ? $ventana.' h' : ($ventana / 24).' d' }}
                </flux:select.option>
            @endforeach
        </flux:select>

        <flux:input wire:model.live.debounce.400ms="direccionIp" label="Direccion IP" placeholder="203.0.113." size="sm" class:input="min-h-11 text-base sm:min-h-0 sm:text-sm" />

        <flux:input wire:model.live.debounce.400ms="busqueda" label="Buscar" placeholder="ruta, regla o mensaje" size="sm" class:input="min-h-11 text-base sm:min-h-0 sm:text-sm" />
    </div>

    {{-- El ancho minimo vive en la TABLA y no en el contenedor: asi el que se desplaza de
         lado es el recuadro y nunca la pagina. Ocho columnas no caben en un telefono, de
         modo que fuente, regla y puntuacion se retiran hasta lg y el ancho minimo baja en
         consecuencia; la severidad ya resume lo que dice la puntuacion. --}}
    <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <table class="w-full min-w-[34rem] text-sm lg:min-w-[56rem]">
            <thead>
                <tr class="border-b border-zinc-200 text-left text-xs uppercase tracking-wide text-zinc-400 dark:border-zinc-700">
                    <th class="px-3 py-3 font-medium sm:px-4">Momento</th>
                    <th class="hidden px-3 py-3 font-medium sm:px-4 lg:table-cell">Fuente</th>
                    <th class="px-3 py-3 font-medium sm:px-4">Direccion</th>
                    <th class="px-3 py-3 font-medium sm:px-4">Peticion</th>
                    <th class="hidden px-3 py-3 font-medium sm:px-4 lg:table-cell">Regla</th>
                    <th class="hidden px-3 py-3 text-right font-medium sm:px-4 lg:table-cell">Puntuacion</th>
                    <th class="px-3 py-3 font-medium sm:px-4">Severidad</th>
                    <th class="px-3 py-3 font-medium sm:px-4">Resultado</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($eventos as $evento)
                    <tr class="align-top hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <td class="siem-numero whitespace-nowrap px-3 py-3 text-zinc-300 sm:px-4">
                            {{ $evento->marca_tiempo?->format('d/m H:i:s') }}
                            @if ($evento->es_demostracion)
                                <flux:badge size="sm" class="ms-1">demo</flux:badge>
                            @endif
                            {{-- La fuente tiene columna propia desde lg; en el telefono se
                                 acompana del momento para no perderla al ocultar columnas. --}}
                            <span class="block text-xs text-zinc-400 lg:hidden">
                                {{ ucfirst($evento->fuente) }}
                            </span>
                        </td>
                        <td class="hidden whitespace-nowrap px-3 py-3 sm:px-4 lg:table-cell">
                            <span class="text-white">{{ ucfirst($evento->fuente) }}</span>
                            <span class="block text-xs text-zinc-400">{{ $evento->subfuente }}</span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-3 sm:px-4">
                            {{-- La direccion viaja por Js::from y no entre comillas a mano: lo que
                                 hay en esa columna lo escribio un atacante, y wire:click se evalua
                                 como expresion. Concatenarla seria abrir un hueco de inyeccion en
                                 la unica pantalla del proyecto que muestra cargas utiles hostiles. --}}

                            {{-- El margen negativo compensa el relleno: el objetivo tactil mide
                                 44 px de alto sin que la fila de la tabla engorde por ello. --}}
                            <button
                                type="button"
                                wire:click="filtrarPorIp({{ \Illuminate\Support\Js::from($evento->direccion_ip) }})"
                                class="siem-numero -my-3 inline-flex min-h-11 items-center py-3 font-medium text-white underline-offset-2 hover:underline"
                                title="Filtrar por esta direccion"
                            >{{ $evento->direccion_ip }}</button>
                            <span class="block text-xs text-zinc-400">
                                {{ $evento->pais ?? 'sin pais' }}
                                @if ($evento->usuario)
                                    · {{ $evento->usuario->name }}
                                @endif
                            </span>
                        </td>
                        <td class="max-w-sm px-3 py-3 sm:px-4">
                            <span class="siem-numero text-xs font-semibold text-zinc-400">{{ $evento->metodo }}</span>
                            {{-- Ruta y mensaje son cadenas largas sin espacios escritas por
                                 quien ataca: sin break-all se salen de la pantalla. --}}
                            <span class="break-all text-zinc-200">{{ \Illuminate\Support\Str::limit($evento->ruta ?? '—', 70) }}</span>
                            <span class="mt-0.5 block break-words text-xs text-zinc-400">{{ \Illuminate\Support\Str::limit($evento->mensaje ?? '', 90) }}</span>
                        </td>
                        <td class="hidden whitespace-nowrap px-3 py-3 sm:px-4 lg:table-cell">
                            @php $reglaPrincipal = $evento->reglaPrincipal(); @endphp
                            @if ($reglaPrincipal)
                                <span class="siem-numero font-medium text-zinc-100">{{ $reglaPrincipal }}</span>
                                @if (count($evento->identificadores_regla ?? []) > 1)
                                    <span class="block text-xs text-zinc-400">
                                        +{{ count($evento->identificadores_regla) - 1 }} mas
                                    </span>
                                @endif
                            @else
                                <span class="text-zinc-400">—</span>
                            @endif
                        </td>
                        <td class="siem-numero hidden px-3 py-3 text-right font-medium text-zinc-100 sm:px-4 lg:table-cell">
                            {{ $evento->puntuacion_anomalia }}
                        </td>
                        <td class="px-3 py-3 sm:px-4">
                            <x-pages::siem.severidad :valor="$evento->severidad" />
                        </td>
                        <td class="whitespace-nowrap px-3 py-3 sm:px-4">
                            @if ($evento->fue_bloqueado)
                                <span class="inline-flex items-center gap-1 text-xs font-semibold" style="color: var(--siem-serie-bloqueados, #34d399)">
                                    <flux:icon.no-symbol class="size-3.5" />
                                    Bloqueado
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 text-xs text-zinc-400">
                                    <flux:icon.arrow-right class="size-3.5" />
                                    Permitido
                                </span>
                            @endif
                            @if ($evento->codigo_respuesta)
                                <span class="siem-numero block text-xs text-zinc-400">HTTP {{ $evento->codigo_respuesta }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-3 py-12 text-center text-zinc-400 sm:px-4">
                            Ningun evento coincide con el filtro. Amplie la ventana o limpie los filtros.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($eventos->hasPages())
        {{-- La paginacion la pinta Livewire con su propia plantilla, asi que no acepta
             clases desde aqui: sus botones miden 38 px de alto. El asidero siem-paginacion
             deja que la hoja de estilos del panel los suba a 44 px en el telefono. --}}
        <div class="siem-paginacion">{{ $eventos->links() }}</div>
    @endif
</div>
