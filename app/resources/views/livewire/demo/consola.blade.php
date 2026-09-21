@php
    $resumen = $this->resumen;
    $diagnostico = $this->diagnostico;
@endphp

<div class="panel-demo space-y-6">
    {{-- ADVERTENCIA PERMANENTE. Esta consola lanza tráfico real contra la propia
         infraestructura. Tiene que verse desde el fondo del aula y quedar constancia de
         que es imposible apuntarla a un tercero. --}}
    <div class="rounded-xl border-2 p-4" style="border-color: var(--demo-peligro)">
        <div class="flex items-start gap-3">
            <flux:icon.exclamation-triangle class="mt-0.5 size-6 shrink-0" style="color: var(--demo-peligro)" />
            <div class="space-y-2 text-sm">
                <p class="text-base font-semibold" style="color: var(--demo-peligro)">
                    Esta consola dispara ataques HTTP reales contra la propia infraestructura.
                </p>
                <p class="text-zinc-700 dark:text-zinc-300">
                    Cada botón envía una petición desde el servidor hacia su propia dirección pública, de modo
                    que atraviesa el WAF de verdad. No es una simulación. El destino lo fija el servidor, nunca
                    esta pantalla, y antes de cada envío se comprueba que el anfitrión coincide con el sitio
                    propio: cualquier otro destino se rechaza. <span class="font-medium">Es imposible apuntarla a un dominio ajeno.</span>
                </p>
                <div class="flex flex-wrap gap-x-6 gap-y-1 text-xs text-zinc-600 dark:text-zinc-400">
                    <span>Destino: <code>{{ $diagnostico['base'] }}</code></span>
                    <span>Anfitriones permitidos: <code>{{ implode(', ', $diagnostico['hosts_permitidos']) }}</code></span>
                </div>
            </div>
        </div>
    </div>

    {{-- Diagnósticos que salvan la demostración si algo no está en su sitio. --}}
    @if (! $diagnostico['auditoria_legible'] || $diagnostico['clave_por_defecto'])
        <div class="space-y-2">
            @unless ($diagnostico['auditoria_legible'])
                <div class="flex items-start gap-2 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-xs text-amber-700 dark:text-amber-300">
                    <flux:icon.exclamation-circle class="mt-0.5 size-4 shrink-0" />
                    <span>
                        No se puede leer el registro de auditoría en <code>{{ $diagnostico['ruta_auditoria'] }}</code>.
                        Los disparos funcionarán y verá el código de respuesta, pero el evento correlacionado del WAF
                        no aparecerá hasta que el volumen esté montado en el contenedor de la aplicación.
                    </span>
                </div>
            @endunless
            @if ($diagnostico['clave_por_defecto'])
                <div class="flex items-start gap-2 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-xs text-amber-700 dark:text-amber-300">
                    <flux:icon.key class="mt-0.5 size-4 shrink-0" />
                    <span>
                        El secreto de consola sigue siendo el valor de fábrica. Cámbielo en <code>MARKETGT_DEMO_KEY</code>
                        y en la regla 1006 del fichero 900 antes de exponer el sitio.
                    </span>
                </div>
            @endif
        </div>
    @endif

    {{-- Contador de la sesión de demostración. --}}
    <div class="grid gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Ataques lanzados</p>
            <p class="demo-numero mt-2 text-3xl font-semibold text-zinc-900 dark:text-white">{{ $resumen['lanzados'] }}</p>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">en modo activo, esta sesión</p>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Bloqueados por el WAF</p>
            <p class="demo-numero mt-2 text-3xl font-semibold" style="color: var(--demo-bloqueado)">{{ $resumen['bloqueados'] }}</p>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">respuestas 403 en el borde</p>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Eficacia</p>
            <p class="demo-numero mt-2 text-3xl font-semibold text-zinc-900 dark:text-white">
                {{ is_null($resumen['eficacia']) ? '—' : $resumen['eficacia'].' %' }}
            </p>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">bloqueados sobre lanzados</p>
        </div>
    </div>

    {{-- Indicador de trabajo en curso mientras vuela la petición. --}}
    <div wire:loading class="flex items-center gap-2 text-sm text-zinc-500 dark:text-zinc-400">
        <flux:icon.arrow-path class="size-4 animate-spin" />
        Lanzando y leyendo el registro de auditoría…
    </div>

    {{-- Panel de detalle de un disparo individual. --}}
    @if ($ultimo)
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <flux:heading size="lg">{{ $ultimo['nombre'] }}</flux:heading>
                    <flux:subheading>{{ $ultimo['grupo'] }} · disparo con el WAF plenamente activo</flux:subheading>
                </div>
                <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="reintentarEvento">
                    Reintentar lectura
                </flux:button>
            </div>
            @include('livewire.demo.bloque-resultado', ['resultado' => $ultimo])
        </div>
    @endif

    {{-- MODO COMPARATIVO: el argumento más fuerte de la presentación. El mismo vector
         contra el mismo control de aplicación, con el WAF activo y en solo detección. --}}
    @if ($comparacion)
        @php $activo = $comparacion['activo']; $deteccion = $comparacion['deteccion']; @endphp
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <flux:heading size="lg">Modo comparativo — {{ $activo['nombre'] }}</flux:heading>
                    <flux:subheading>
                        Idéntico vector, mismo blanco de aplicación, dos estados del motor. Es defensa en
                        profundidad: dos controles independientes para la misma amenaza.
                    </flux:subheading>
                </div>
                <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="reintentarEvento">
                    Reintentar lectura
                </flux:button>
            </div>

            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-3 text-xs text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-400">
                El vector es el mismo. Cambia solo la ruta de destino, porque el modo solo-detección está
                autorizado por el WAF únicamente sobre <code>/demo-waf</code> mediante la cabecera secreta.
                En los dos casos la petición atraviesa ModSecurity.
            </div>

            <div class="mt-4 grid gap-6 lg:grid-cols-2">
                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    @include('livewire.demo.bloque-resultado', ['resultado' => $activo, 'etiquetaModo' => 'WAF activo'])
                </div>
                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    @include('livewire.demo.bloque-resultado', ['resultado' => $deteccion, 'etiquetaModo' => 'WAF en solo detección'])
                </div>
            </div>

            <p class="mt-4 text-sm text-zinc-600 dark:text-zinc-300">
                <span class="font-medium">Lectura:</span> con el motor activo, el WAF corta en el borde (403) y la
                petición nunca toca la aplicación. En solo detección, la petición llega hasta la aplicación —donde la
                consulta preparada la neutraliza igual—, y el WAF la deja registrada sin bloquearla. Si una capa
                fallara, la otra seguiría deteniendo la amenaza.
            </p>
        </div>
    @endif

    {{-- CATÁLOGO DE ATAQUES. --}}
    <div class="space-y-4">
        @foreach ($this->catalogo as $grupo)
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" wire:key="grupo-{{ $grupo['clave'] }}">
                <flux:heading size="lg">{{ $grupo['titulo'] }}</flux:heading>
                <flux:subheading>{{ $grupo['descripcion'] }}</flux:subheading>

                <div class="mt-4 divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($grupo['ataques'] as $ataque)
                        <div class="flex flex-col gap-3 py-4 lg:flex-row lg:items-start lg:justify-between" wire:key="ataque-{{ $ataque['id'] }}">
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-zinc-900 dark:text-white">{{ $ataque['nombre'] }}</p>
                                <p class="mt-0.5 text-sm text-zinc-600 dark:text-zinc-400">{{ $ataque['amenaza'] }}</p>
                                <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                                    <flux:badge size="sm" color="zinc">{{ $ataque['owasp'] }}</flux:badge>
                                    @php $primerId = (int) $ataque['regla']; $reglaPropia = $primerId >= 15000 && $primerId < 16000; @endphp
                                    <span class="demo-regla {{ $reglaPropia ? 'demo-regla-propia' : '' }}">
                                        Regla {{ $ataque['regla'] }}
                                    </span>
                                    <span class="text-zinc-500 dark:text-zinc-400">{{ $ataque['regla_nombre'] }}</span>
                                    @if (($ataque['esperado_activo'] ?? 'bloqueado') === 'detectado')
                                        <span style="color: var(--demo-detectado)">esperado: detectado (por debajo del umbral)</span>
                                    @else
                                        <span style="color: var(--demo-bloqueado)">esperado: 403 bloqueado</span>
                                    @endif
                                </div>
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                <flux:button
                                    size="sm"
                                    variant="primary"
                                    wire:click="lanzar('{{ $ataque['id'] }}')"
                                    wire:loading.attr="disabled"
                                >
                                    Lanzar
                                </flux:button>
                                <flux:button
                                    size="sm"
                                    variant="filled"
                                    wire:click="comparar('{{ $ataque['id'] }}')"
                                    wire:loading.attr="disabled"
                                >
                                    Comparar
                                </flux:button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    {{-- Bitácora de la sesión: el registro de lo que se ha lanzado en esta presentación. --}}
    @if ($historial !== [])
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mb-3 flex items-center justify-between">
                <flux:heading size="lg">Bitácora de la sesión</flux:heading>
                <flux:button size="sm" variant="ghost" icon="trash" wire:click="limpiarSesion">Reiniciar</flux:button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 text-left text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                            <th class="pb-2 font-medium">Hora</th>
                            <th class="pb-2 font-medium">Ataque</th>
                            <th class="pb-2 font-medium">Modo</th>
                            <th class="pb-2 text-right font-medium">Código</th>
                            <th class="pb-2 text-right font-medium">Resultado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach (array_reverse($historial) as $fila)
                            <tr>
                                <td class="demo-numero py-2 text-zinc-500 dark:text-zinc-400">{{ $fila['hora'] }}</td>
                                <td class="py-2 text-zinc-900 dark:text-white">{{ $fila['nombre'] }}</td>
                                <td class="py-2 text-zinc-500 dark:text-zinc-400">{{ $fila['modo'] === 'deteccion' ? 'solo detección' : 'activo' }}</td>
                                <td class="demo-numero py-2 text-right">{{ $fila['error'] ? 'sin envío' : ($fila['codigo'] ?? '—') }}</td>
                                <td class="py-2 text-right">
                                    @if ($fila['error'])
                                        <span style="color: var(--demo-neutro)">error de conexión</span>
                                    @elseif ($fila['bloqueado'])
                                        <span style="color: var(--demo-bloqueado)">bloqueado</span>
                                    @elseif ($fila['modo'] === 'deteccion')
                                        <span style="color: var(--demo-detectado)">llegó a la app</span>
                                    @else
                                        <span style="color: var(--demo-paso)">atravesó</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
