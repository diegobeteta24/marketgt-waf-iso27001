@php
    use App\Models\AlertaSeguridad;

    $conteos = $this->conteosPorEstado;
    $etiquetasEstado = AlertaSeguridad::ETIQUETAS_ESTADO;
@endphp

<div wire:poll.20s class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <flux:heading size="lg">Alertas y triaje</flux:heading>
            <flux:subheading>
                Cada alerta tiene estado, dueno y marca de tiempo. Eso es lo que separa una
                herramienta encendida de un servicio operado.
            </flux:subheading>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <flux:select wire:model.live="estadoFiltro" size="sm">
                <flux:select.option value="abiertas">Abiertas ({{ $conteos[AlertaSeguridad::ESTADO_NUEVA] + $conteos[AlertaSeguridad::ESTADO_EN_TRIAJE] }})</flux:select.option>
                <flux:select.option value="todas">Todas</flux:select.option>
                @foreach ($etiquetasEstado as $estado => $etiqueta)
                    <flux:select.option value="{{ $estado }}">{{ $etiqueta }} ({{ $conteos[$estado] }})</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="severidadFiltro" size="sm">
                <flux:select.option value="">Toda severidad</flux:select.option>
                @foreach (\App\Models\EventoSeguridad::ESCALA_SEVERIDAD as $severidad)
                    <flux:select.option value="{{ $severidad }}">{{ ucfirst($severidad) }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    <div class="space-y-3">
        @forelse ($this->alertas as $alerta)
            @php $abierta = $this->alertaSeleccionada === $alerta->id; @endphp

            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <button
                    type="button"
                    wire:click="seleccionar({{ $alerta->id }})"
                    class="flex w-full items-start justify-between gap-4 p-4 text-left"
                >
                    <div class="min-w-0 space-y-1.5">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-pages::siem.severidad :valor="$alerta->severidad" />

                            <span @class([
                                'rounded-full px-2 py-0.5 text-[0.7rem] font-semibold',
                                'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $alerta->estado === AlertaSeguridad::ESTADO_NUEVA,
                                'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300' => $alerta->estado !== AlertaSeguridad::ESTADO_NUEVA,
                            ])>{{ $alerta->etiquetaEstado() }}</span>

                            @if ($alerta->es_demostracion)
                                <flux:badge size="sm">demo</flux:badge>
                            @endif

                            <span class="siem-numero text-xs text-zinc-500 dark:text-zinc-400">
                                #{{ $alerta->id }} · {{ $alerta->clave_regla }}
                            </span>
                        </div>

                        <p class="truncate font-semibold text-zinc-900 dark:text-white">{{ $alerta->titulo }}</p>

                        <p class="siem-numero text-xs text-zinc-500 dark:text-zinc-400">
                            Detectada {{ $alerta->detectada_en?->format('d/m/Y H:i') }}
                            @if ($alerta->minutosHastaDeteccion() !== null)
                                · {{ $alerta->minutosHastaDeteccion() }} min desde el primer evento
                            @endif
                            · {{ number_format($alerta->conteo_eventos) }} eventos
                            @if ($alerta->analista)
                                · atiende {{ $alerta->analista->name }}
                            @endif
                        </p>
                    </div>

                    <flux:icon.chevron-down @class([
                        'mt-1 size-5 shrink-0 text-zinc-400 transition',
                        'rotate-180' => $abierta,
                    ]) />
                </button>

                @if ($abierta)
                    <div class="space-y-4 border-t border-zinc-200 p-4 dark:border-zinc-700">
                        <p class="text-sm text-zinc-700 dark:text-zinc-300">{{ $alerta->descripcion }}</p>

                        {{-- La accion recomendada es lo que convierte una alerta en trabajo ejecutable. --}}
                        <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-800/50">
                            <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Accion recomendada</p>
                            <p class="mt-1 text-sm text-zinc-800 dark:text-zinc-200">{{ $alerta->accion_recomendada }}</p>
                        </div>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Evidencia</p>
                                <dl class="mt-2 space-y-1 text-sm">
                                    <div class="flex justify-between gap-4">
                                        <dt class="text-zinc-500 dark:text-zinc-400">Direccion de origen</dt>
                                        <dd class="siem-numero font-medium">{{ $alerta->direccion_ip ?? 'no aplica' }}</dd>
                                    </div>
                                    <div class="flex justify-between gap-4">
                                        <dt class="text-zinc-500 dark:text-zinc-400">Ventana / umbral</dt>
                                        <dd class="siem-numero font-medium">
                                            {{ data_get($alerta->evidencia, 'ventana_minutos', '—') }} min /
                                            {{ data_get($alerta->evidencia, 'umbral', '—') }}
                                        </dd>
                                    </div>
                                    <div class="flex justify-between gap-4">
                                        <dt class="text-zinc-500 dark:text-zinc-400">Primer evento</dt>
                                        <dd class="siem-numero font-medium">{{ $alerta->primer_evento_en?->format('d/m/Y H:i:s') ?? 'sin dato' }}</dd>
                                    </div>
                                    @if ($alerta->usuarioObjetivo)
                                        <div class="flex justify-between gap-4">
                                            <dt class="text-zinc-500 dark:text-zinc-400">Cuenta afectada</dt>
                                            <dd class="font-medium">{{ $alerta->usuarioObjetivo->name }}</dd>
                                        </div>
                                    @endif
                                    @if ($alerta->confirmada_en)
                                        <div class="flex justify-between gap-4">
                                            <dt class="text-zinc-500 dark:text-zinc-400">Confirmada</dt>
                                            <dd class="siem-numero font-medium">{{ $alerta->confirmada_en->format('d/m/Y H:i') }}</dd>
                                        </div>
                                    @endif
                                    @if ($alerta->contenida_en)
                                        <div class="flex justify-between gap-4">
                                            <dt class="text-zinc-500 dark:text-zinc-400">Contenida</dt>
                                            <dd class="siem-numero font-medium">
                                                {{ $alerta->contenida_en->format('d/m/Y H:i') }}
                                                @if ($alerta->minutosHastaContencion() !== null)
                                                    ({{ $alerta->minutosHastaContencion() }} min)
                                                @endif
                                            </dd>
                                        </div>
                                    @endif
                                </dl>
                            </div>

                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                    Muestra de eventos
                                </p>
                                <div class="mt-2 max-h-48 overflow-y-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                                    <table class="w-full text-xs">
                                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                            @forelse (data_get($alerta->evidencia, 'muestra', []) as $muestra)
                                                <tr>
                                                    <td class="siem-numero whitespace-nowrap px-2 py-1.5 text-zinc-500 dark:text-zinc-400">
                                                        {{ \Illuminate\Support\Str::after($muestra['marca_tiempo'] ?? '', ' ') }}
                                                    </td>
                                                    <td class="px-2 py-1.5">
                                                        <span class="siem-numero font-semibold">{{ $muestra['metodo'] ?? '' }}</span>
                                                        <span class="break-all">{{ $muestra['ruta'] ?? ($muestra['mensaje'] ?? '') }}</span>
                                                    </td>
                                                    <td class="siem-numero whitespace-nowrap px-2 py-1.5 text-right text-zinc-500 dark:text-zinc-400">
                                                        {{ $muestra['codigo_respuesta'] ?? '' }}
                                                        @if (($muestra['puntuacion_anomalia'] ?? 0) > 0)
                                                            · {{ $muestra['puntuacion_anomalia'] }}
                                                        @endif
                                                    </td>
                                                </tr>
                                            @empty
                                                <tr>
                                                    <td class="px-2 py-3 text-center text-zinc-500 dark:text-zinc-400">
                                                        Sin muestra guardada.
                                                    </td>
                                                </tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <flux:textarea
                            wire:model="notas"
                            label="Notas de triaje"
                            rows="2"
                            placeholder="Que se comprobo, que se decidio y por que."
                        />

                        <div class="flex flex-wrap items-center gap-2">
                            @forelse ($this->transicionesDe($alerta) as $destino)
                                <flux:button
                                    size="sm"
                                    :variant="$destino === AlertaSeguridad::ESTADO_CONTENIDA ? 'primary' : ($destino === AlertaSeguridad::ESTADO_FALSO_POSITIVO ? 'danger' : 'filled')"
                                    wire:click="cambiarEstado({{ $alerta->id }}, '{{ $destino }}')"
                                >
                                    Marcar {{ \Illuminate\Support\Str::lower($etiquetasEstado[$destino]) }}
                                </flux:button>
                            @empty
                                <flux:text class="text-sm">
                                    Esta alerta ya llego al final de su ciclo. El historico no se reabre desde el panel.
                                </flux:text>
                            @endforelse

                            <flux:button size="sm" variant="ghost" wire:click="guardarNotas({{ $alerta->id }})">
                                Guardar nota
                            </flux:button>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700">
                <flux:icon.shield-check class="mx-auto size-8 text-zinc-400 dark:text-zinc-500" />
                <p class="mt-3 font-medium text-zinc-900 dark:text-white">No hay alertas con este filtro</p>
                <flux:text class="mt-1">
                    Si esperaba alertas, compruebe que el comando de correlacion se este ejecutando.
                </flux:text>
            </div>
        @endforelse
    </div>
</div>
