@php
    $contadores = $this->contadores;
    $lineas = $this->lineasBase;
    $ultima = $this->ultimaComprobacion;
@endphp

<div wire:poll.30s class="space-y-6">
    @unless ($this->tablasListas)
        <div class="flex items-start gap-3 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 sm:p-4">
            <flux:icon.exclamation-triangle class="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400" />
            <div class="min-w-0 text-sm">
                <p class="font-semibold text-amber-700 dark:text-amber-300">Faltan las tablas del componente</p>
                <p class="mt-1 break-words text-zinc-600 dark:text-zinc-400">
                    Ejecute <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-800">php artisan migrate</code>
                    para crear <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-800">incidentes_seo</code> y
                    <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-800">lineas_base_seo</code>.
                </p>
            </div>
        </div>
    @else
        {{-- Cifras grandes y sueltas: esta franja se tiene que leer desde el fondo del aula. --}}
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @php
                $tarjetas = [
                    [
                        'titulo' => 'Incidentes (24 h)',
                        'valor' => $contadores['incidentes_24h'] ?? 0,
                        'pie' => ($contadores['abiertos'] ?? 0).' sin cerrar',
                        'icono' => 'shield-exclamation',
                    ],
                    [
                        'titulo' => 'Rastreadores falsos (24 h)',
                        'valor' => $contadores['rastreadores_falsos'] ?? 0,
                        'pie' => 'peticiones que dijeron ser un buscador',
                        'icono' => 'bug-ant',
                    ],
                    [
                        'titulo' => 'Contenido retenido',
                        'valor' => $contadores['contenido_retenido'] ?? 0,
                        'pie' => 'reseñas que no llegaron a publicarse',
                        'icono' => 'chat-bubble-left-ellipsis',
                    ],
                    [
                        'titulo' => 'Integridad rota',
                        'valor' => $contadores['integridad_rota'] ?? 0,
                        'pie' => ($contadores['cloaking'] ?? 0).' divergencias de contenido',
                        'icono' => 'document-magnifying-glass',
                    ],
                ];
            @endphp

            @foreach ($tarjetas as $tarjeta)
                <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900 sm:p-4">
                    <div class="flex items-center justify-between gap-2">
                        <p class="min-w-0 break-words text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            {{ $tarjeta['titulo'] }}
                        </p>
                        @switch ($tarjeta['icono'])
                            @case('shield-exclamation')
                                <flux:icon.shield-exclamation class="size-4 shrink-0 text-zinc-500 dark:text-zinc-400" />
                            @break

                            @case('bug-ant')
                                <flux:icon.bug-ant class="size-4 shrink-0 text-zinc-500 dark:text-zinc-400" />
                            @break

                            @case('chat-bubble-left-ellipsis')
                                <flux:icon.chat-bubble-left-ellipsis class="size-4 shrink-0 text-zinc-500 dark:text-zinc-400" />
                            @break

                            @default
                                <flux:icon.document-magnifying-glass class="size-4 shrink-0 text-zinc-500 dark:text-zinc-400" />
                        @endswitch
                    </div>
                    <p class="mt-2 text-3xl font-semibold tabular-nums text-zinc-900 dark:text-white">
                        {{ number_format($tarjeta['valor']) }}
                    </p>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $tarjeta['pie'] }}</p>
                </div>
            @endforeach
        </div>

        {{-- Línea base de los artefactos de indexación --}}
        <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex flex-wrap items-end justify-between gap-3 border-b border-zinc-200 p-3 dark:border-zinc-700 sm:p-4">
                <div class="min-w-0">
                    <flux:heading size="lg">Línea base de indexación</flux:heading>
                    <flux:subheading>
                        Estado autorizado de robots.txt, del sitemap y de la superficie indexable de las
                        páginas principales. El WAF impide reescribirlos por HTTP; esto detecta el cambio
                        que entra por cualquier otra puerta.
                    </flux:subheading>
                </div>

                <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:flex-wrap">
                    <flux:button size="sm" variant="primary" icon="arrow-path" class="w-full min-h-11 sm:w-auto sm:min-h-0" wire:click="ejecutarVigilancia" wire:loading.attr="disabled">
                        Verificar ahora
                    </flux:button>

                    @if (auth()->user()?->esAdministrador())
                        <flux:button size="sm" variant="ghost" icon="lock-closed" class="w-full min-h-11 sm:w-auto sm:min-h-0" wire:click="sellarLineaBase" wire:confirm="Sellar declara que el estado ACTUAL es el autorizado. Si hay un cambio no revisado, quedará legitimado. ¿Continuar?">
                            Sellar línea base
                        </flux:button>
                    @endif
                </div>
            </div>

            <div class="overflow-x-auto">
                {{-- El ancho mínimo va en la tabla, no en el contenedor que desplaza, y crece por
                     tramos. En móvil quedan artefacto, huella y fecha de sellado; el firmante y
                     la nota aparecen cuando hay anchura para leerlos. --}}
                <table class="w-full text-sm sm:min-w-[30rem] md:min-w-[38rem] lg:min-w-[44rem]">
                    <thead>
                        <tr class="border-b border-zinc-200 text-left text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                            <th class="px-3 py-3 font-medium sm:px-4">Artefacto</th>
                            <th class="px-3 py-3 font-medium sm:px-4">Huella (sha256)</th>
                            <th class="px-3 py-3 font-medium sm:px-4">Sellada</th>
                            <th class="hidden px-3 py-3 font-medium md:table-cell sm:px-4">Firmada por</th>
                            <th class="hidden px-3 py-3 font-medium lg:table-cell sm:px-4">Nota</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($lineas as $linea)
                            <tr class="align-top">
                                <td class="break-words px-3 py-3 font-medium text-zinc-900 dark:text-white sm:px-4">{{ $linea->etiqueta() }}</td>
                                <td class="break-all px-3 py-3 font-mono text-xs text-zinc-600 dark:text-zinc-300 sm:px-4">{{ $linea->huellaCorta() }}…</td>
                                <td class="px-3 py-3 text-zinc-600 dark:text-zinc-300 sm:whitespace-nowrap sm:px-4">
                                    {{ $linea->sellada_en->format('d/m/Y H:i') }}
                                </td>
                                <td class="hidden break-words px-3 py-3 text-zinc-600 md:table-cell dark:text-zinc-300 sm:px-4">
                                    {{ $linea->firmante?->name ?? 'sin firmar (consola)' }}
                                </td>
                                <td class="hidden break-words px-3 py-3 text-zinc-500 lg:table-cell dark:text-zinc-400 sm:px-4">{{ $linea->notas }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-3 py-8 text-center text-zinc-500 dark:text-zinc-400 sm:px-4">
                                    Todavía no hay línea base. Pulse «Verificar ahora»: la primera ejecución sella
                                    el estado actual y a partir de ahí cualquier cambio salta como incidente.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="border-t border-zinc-200 px-3 py-3 text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400 sm:px-4 sm:text-xs">
                @if ($ultima)
                    Última comprobación: {{ $ultima->format('d/m/Y H:i:s') }} ({{ $ultima->diffForHumans() }}).
                    @if ($ultima->lessThan(now()->subHours(6)))
                        <span class="font-semibold text-amber-600 dark:text-amber-400">
                            Hace demasiado: programe <code>seo:vigilar</code> cada cinco minutos.
                        </span>
                    @endif
                @else
                    Sin comprobaciones registradas.
                @endif
            </div>
        </div>

        @if ($this->salidaVigilancia !== '')
            <div @class([
                'rounded-xl border p-3 sm:p-4',
                'border-emerald-500/40 bg-emerald-500/10' => $this->vigilanciaCorrecta,
                'border-red-500/40 bg-red-500/10' => ! $this->vigilanciaCorrecta,
            ])>
                <p class="mb-2 break-words text-sm font-semibold text-zinc-800 dark:text-zinc-100">
                    Salida de <code>php artisan seo:vigilar</code>
                </p>
                <pre class="overflow-x-auto whitespace-pre-wrap break-words text-xs leading-relaxed text-zinc-700 dark:text-zinc-200">{{ $this->salidaVigilancia }}</pre>
            </div>
        @endif

        {{-- Últimos hallazgos, con enlace al triaje --}}
        <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            {{-- Sin flex-wrap el botón «Ver todos» se salía de la pantalla en móvil. --}}
            <div class="flex flex-wrap items-end justify-between gap-3 border-b border-zinc-200 p-3 dark:border-zinc-700 sm:p-4">
                <div class="min-w-0">
                    <flux:heading size="lg">Últimos hallazgos</flux:heading>
                    <flux:subheading>Los seis más recientes. El detalle completo y el triaje están en la pestaña de incidentes.</flux:subheading>
                </div>

                <flux:button size="sm" variant="ghost" class="w-full min-h-11 sm:w-auto sm:min-h-0" :href="route('seo.incidentes')" wire:navigate icon="arrow-right">
                    Ver todos
                </flux:button>
            </div>

            <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($this->ultimosIncidentes as $incidente)
                    <li class="flex flex-wrap items-start justify-between gap-2 px-3 py-3 sm:gap-3 sm:px-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <flux:badge size="sm" :color="match ($incidente->severidad) {
                                    'critica' => 'red',
                                    'alta' => 'orange',
                                    'media' => 'amber',
                                    'baja' => 'sky',
                                    default => 'zinc',
                                }">{{ ucfirst($incidente->severidad) }}</flux:badge>

                                <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $incidente->etiquetaTipo() }}</span>

                                @if ($incidente->regla)
                                    <span class="break-all rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                        {{ $incidente->regla }}
                                    </span>
                                @endif

                                @if ($incidente->esCampanaActiva())
                                    <flux:badge size="sm" color="red">campaña activa</flux:badge>
                                @endif
                            </div>
                            {{-- En móvil se leen dos líneas del resumen en vez de cortarlo a una:
                                 con 375 px una sola línea no dice casi nada. --}}
                            <p class="mt-1 line-clamp-2 text-sm text-zinc-600 dark:text-zinc-300 sm:line-clamp-1">{{ $incidente->resumen }}</p>
                        </div>

                        {{-- Debajo y alineado a la izquierda en móvil; a la derecha y en una línea desde sm. --}}
                        <div class="w-full text-left text-xs text-zinc-500 dark:text-zinc-400 sm:w-auto sm:whitespace-nowrap sm:text-right">
                            <p>{{ $incidente->ultima_vez_en->format('d/m H:i:s') }}</p>
                            <p><span class="break-all">{{ $incidente->direccion_ip ?? 'sin dirección' }}</span> · ×{{ number_format($incidente->repeticiones) }}</p>
                        </div>
                    </li>
                @empty
                    <li class="px-3 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400 sm:px-4">
                        Ningún incidente registrado. Lance un ataque desde el laboratorio para comprobar que
                        la detección funciona: un control que nunca ha disparado no está probado.
                    </li>
                @endforelse
            </ul>
        </div>
    @endunless
</div>
