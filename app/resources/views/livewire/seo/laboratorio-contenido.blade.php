@php
    $analisis = $this->analisis;
@endphp

<div class="space-y-6">
    {{-- ------------------------------------------------------------------ --}}
    {{-- 1. Sanitización de contenido de usuario                            --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="lg">1 · Sanitización de reseñas</flux:heading>
            <flux:subheading>
                El WAF corta lo que entra; la aplicación decide qué se publica. Escriba aquí la carga de
                ataque y compare: a la izquierda lo que envía el atacante, a la derecha lo único que
                sobrevive a la lista blanca.
            </flux:subheading>
        </div>

        <div class="grid gap-4 p-4 lg:grid-cols-2">
            <div class="space-y-3">
                <flux:textarea
                    wire:model.live.debounce.400ms="contenido"
                    label="Contenido enviado por el usuario"
                    rows="12"
                    class="font-mono text-xs"
                />

                <div class="flex flex-wrap items-center gap-3">
                    <flux:button size="sm" variant="primary" wire:click="intentarPublicar" icon="paper-airplane">
                        Intentar publicar
                    </flux:button>
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">
                        Publicar sí registra incidente; teclear, no.
                    </span>
                </div>
            </div>

            <div class="space-y-3">
                <div @class([
                    'flex items-center justify-between rounded-lg border p-3',
                    'border-emerald-500/40 bg-emerald-500/10' => $analisis['publicable'],
                    'border-red-500/40 bg-red-500/10' => ! $analisis['publicable'],
                ])>
                    <div>
                        <p class="text-sm font-semibold text-zinc-900 dark:text-white">
                            {{ $analisis['publicable'] ? 'Se publicaría' : 'Retenido para revisión' }}
                        </p>
                        <p class="text-xs text-zinc-600 dark:text-zinc-300">
                            Umbral de anomalía {{ \App\Services\Seo\DetectorSpamSeo::UMBRAL }}, el mismo del WAF.
                        </p>
                    </div>
                    <p class="text-3xl font-semibold tabular-nums text-zinc-900 dark:text-white">
                        {{ $analisis['puntuacion'] }}
                    </p>
                </div>

                @if ($analisis['motivos'] !== [])
                    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <table class="w-full text-xs">
                            <thead class="bg-zinc-50 text-left uppercase tracking-wide text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                                <tr>
                                    <th class="px-3 py-2 font-medium">Regla</th>
                                    <th class="px-3 py-2 font-medium">Qué detecta</th>
                                    <th class="px-3 py-2 text-right font-medium">Puntos</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach ($analisis['motivos'] as $motivo)
                                    <tr>
                                        <td class="px-3 py-2 font-mono text-zinc-700 dark:text-zinc-200">{{ $motivo['regla'] }}</td>
                                        <td class="px-3 py-2 text-zinc-600 dark:text-zinc-300">
                                            {{ $motivo['descripcion'] }}
                                            <span class="block font-mono text-[11px] text-zinc-400">{{ $motivo['evidencia'] }}</span>
                                        </td>
                                        <td class="px-3 py-2 text-right tabular-nums text-zinc-900 dark:text-white">+{{ $motivo['puntos'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <div>
                    <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        HTML reconstruido
                    </p>
                    <pre class="overflow-x-auto rounded-lg bg-zinc-900 p-3 text-[11px] leading-relaxed text-emerald-200">{{ $analisis['html'] === '' ? '(vacío: no sobrevivió nada)' : $analisis['html'] }}</pre>
                </div>

                <div>
                    <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        Cómo se vería publicado
                    </p>
                    {{-- Se imprime sin escapar a propósito y solo aquí: es la salida del sanitizador,
                         reconstruida etiqueta por etiqueta desde la lista blanca. Ver que el <script>
                         desapareció y que los enlaces llevan rel="nofollow ugc" es justo lo que hay
                         que demostrar. El contenido crudo del atacante nunca se imprime así. --}}
                    <div class="prose prose-sm max-w-none rounded-lg border border-zinc-200 p-3 text-sm text-zinc-800 dark:border-zinc-700 dark:text-zinc-100">
                        {!! $analisis['html'] !!}
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- 2. Verificación inversa del rastreador                             --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="lg">2 · Verificación inversa del rastreador (FCrDNS)</flux:heading>
            <flux:subheading>
                Lo único que ModSecurity no puede hacer: consultar DNS dentro de una regla. Dos pasos,
                PTR y resolución directa, y los dos tienen que coincidir.
            </flux:subheading>
        </div>

        <div class="grid gap-4 p-4 lg:grid-cols-2">
            <div class="space-y-3">
                <flux:input wire:model="ipRastreador" label="Dirección IP" placeholder="66.249.66.1" />
                <flux:input wire:model="agenteRastreador" label="Cabecera User-Agent" class="font-mono text-xs" />

                <flux:button size="sm" variant="primary" wire:click="verificarRastreador" icon="magnifying-glass">
                    Verificar
                </flux:button>

                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                    Pruebe con <span class="font-mono">66.249.66.1</span> (Googlebot real) y con la dirección
                    desde la que navega ahora mismo. La segunda dice ser Googlebot y no lo es.
                </p>
            </div>

            <div>
                @if ($this->veredictoRastreador === null)
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Sin consulta todavía.</p>
                @else
                    @php $v = $this->veredictoRastreador; @endphp

                    <div @class([
                        'rounded-lg border p-4 space-y-2',
                        'border-emerald-500/40 bg-emerald-500/10' => $v['verificado'],
                        'border-red-500/40 bg-red-500/10' => ! $v['verificado'] && $v['declara_ser_bot'],
                        'border-zinc-300 dark:border-zinc-700' => ! $v['declara_ser_bot'],
                    ])>
                        <p class="text-sm font-semibold text-zinc-900 dark:text-white">
                            @if (! $v['declara_ser_bot'])
                                No dice ser un rastreador: tráfico normal, no se verifica nada.
                            @elseif ($v['verificado'])
                                Rastreador legítimo confirmado ({{ $v['familia'] }}).
                            @else
                                Rastreador FALSIFICADO ({{ $v['familia'] }}). Se respondería 403 y se registraría el incidente.
                            @endif
                        </p>

                        <dl class="grid grid-cols-[9rem_1fr] gap-x-3 gap-y-1 text-xs text-zinc-700 dark:text-zinc-200">
                            <dt class="text-zinc-500 dark:text-zinc-400">Dirección</dt>
                            <dd class="font-mono">{{ $v['ip'] }}</dd>

                            <dt class="text-zinc-500 dark:text-zinc-400">Paso 1 · PTR</dt>
                            <dd class="font-mono">{{ $v['ptr'] ?? 'sin registro PTR' }}</dd>

                            <dt class="text-zinc-500 dark:text-zinc-400">Paso 2 · directo</dt>
                            <dd>
                                @if ($v['verificado'])
                                    el nombre resuelve de vuelta a la misma dirección
                                @elseif ($v['motivo'] === 'dns_directo_no_confirma')
                                    el nombre NO resuelve de vuelta a esta dirección
                                @else
                                    no se llegó a comprobar
                                @endif
                            </dd>

                            <dt class="text-zinc-500 dark:text-zinc-400">Motivo</dt>
                            <dd class="font-mono">{{ $v['motivo'] }}</dd>
                        </dl>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- 3. Lista blanca de redirección                                     --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="lg">3 · Lista blanca de redirección</flux:heading>
            <flux:subheading>
                <code>/ir?destino=</code> no acepta direcciones: acepta claves de un mapa cerrado. Escriba
                una URL completa y compruebe que no hay forma de que salga por ahí.
            </flux:subheading>
        </div>

        <div class="grid gap-4 p-4 lg:grid-cols-2">
            <div class="space-y-3">
                <flux:input
                    wire:model="claveRedireccion"
                    label="Valor del parámetro destino"
                    placeholder="sat   ·   https://sitio-del-atacante.tld"
                />

                <flux:button size="sm" variant="primary" wire:click="probarRedireccion" icon="arrow-top-right-on-square">
                    Resolver destino
                </flux:button>

                @if ($this->destinoResuelto !== null)
                    <div class="rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700">
                        <p class="text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Destino real</p>
                        <p class="mt-1 break-all font-mono text-zinc-900 dark:text-white">{{ $this->destinoResuelto }}</p>
                    </div>
                @endif
            </div>

            <div>
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                    Mapa cerrado de destinos
                </p>
                <ul class="divide-y divide-zinc-100 rounded-lg border border-zinc-200 text-sm dark:divide-zinc-800 dark:border-zinc-700">
                    @foreach ($this->destinosPermitidos as $clave => $destino)
                        <li class="flex flex-wrap items-baseline justify-between gap-2 px-3 py-2">
                            <span class="font-mono text-zinc-900 dark:text-white">{{ $clave }}</span>
                            <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $destino['descripcion'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</div>
