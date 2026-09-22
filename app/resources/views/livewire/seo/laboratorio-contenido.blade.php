@php
    $analisis = $this->analisis;
@endphp

<div class="space-y-6">
    {{-- ------------------------------------------------------------------ --}}
    {{-- 1. Sanitización de contenido de usuario                            --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 p-3 dark:border-zinc-700 sm:p-4">
            <flux:heading size="lg">1 · Sanitización de reseñas</flux:heading>
            <flux:subheading>
                El WAF corta lo que entra; la aplicación decide qué se publica. Escriba aquí la carga de
                ataque y compare: a la izquierda lo que envía el atacante, a la derecha lo único que
                sobrevive a la lista blanca.
            </flux:subheading>
        </div>

        <div class="grid gap-4 p-3 sm:p-4 lg:grid-cols-2">
            <div class="min-w-0 space-y-3">
                {{-- El campo donde se pega la carga de ataque: en móvil se deja el tamaño
                     que trae Flux (16 px), porque Safari de iOS amplía la página sola al
                     enfocar letra menor y la deja desplazada. Desde sm baja a text-xs. --}}
                <flux:textarea
                    wire:model.live.debounce.400ms="contenido"
                    label="Contenido enviado por el usuario"
                    rows="12"
                    class="font-mono sm:text-xs"
                />

                <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                    <flux:button size="sm" variant="primary" class="w-full min-h-11 sm:w-auto sm:min-h-0" wire:click="intentarPublicar" icon="paper-airplane">
                        Intentar publicar
                    </flux:button>
                    <span class="text-sm text-zinc-500 dark:text-zinc-400 sm:text-xs">
                        Publicar sí registra incidente; teclear, no.
                    </span>
                </div>
            </div>

            <div class="min-w-0 space-y-3">
                <div @class([
                    'flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3',
                    'border-emerald-500/40 bg-emerald-500/10' => $analisis['publicable'],
                    'border-red-500/40 bg-red-500/10' => ! $analisis['publicable'],
                ])>
                    <div class="min-w-0">
                        {{-- El veredicto se distingue por color Y por palabra: quien no
                             separa el rojo del verde lo lee igual en el texto. --}}
                        <p @class([
                            'text-sm font-semibold',
                            'text-emerald-700 dark:text-emerald-300' => $analisis['publicable'],
                            'text-red-700 dark:text-red-300' => ! $analisis['publicable'],
                        ])>
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
                    {{-- El contenedor redondeado recorta; el desplazamiento va en un envoltorio
                         interior, porque overflow-hidden a secas escondía la evidencia larga
                         en lugar de dejar desplazarla. --}}
                    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <div class="overflow-x-auto">
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
                                            <td class="break-all px-3 py-2 font-mono text-zinc-700 dark:text-zinc-200">{{ $motivo['regla'] }}</td>
                                            <td class="break-words px-3 py-2 text-zinc-600 dark:text-zinc-300">
                                                {{ $motivo['descripcion'] }}
                                                {{-- La evidencia es contenido del atacante: puede ser una única
                                                     cadena larguísima, así que se corta por donde sea. --}}
                                                <span class="block break-all font-mono text-[11px] text-zinc-600 dark:text-zinc-300">{{ $motivo['evidencia'] }}</span>
                                            </td>
                                            <td class="px-3 py-2 text-right tabular-nums text-zinc-900 dark:text-white">+{{ $motivo['puntos'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                <div>
                    <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        HTML reconstruido
                    </p>
                    <pre class="overflow-x-auto whitespace-pre-wrap break-all rounded-lg border border-zinc-700 bg-zinc-950 p-3 text-xs leading-relaxed text-emerald-200">{{ $analisis['html'] === '' ? '(vacío: no sobrevivió nada)' : $analisis['html'] }}</pre>
                </div>

                <div>
                    <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        Cómo se vería publicado
                    </p>
                    {{-- Se imprime sin escapar a propósito y solo aquí: es la salida del sanitizador,
                         reconstruida etiqueta por etiqueta desde la lista blanca. Ver que el <script>
                         desapareció y que los enlaces llevan rel="nofollow ugc" es justo lo que hay
                         que demostrar. El contenido crudo del atacante nunca se imprime así. --}}
                    {{-- break-words: el contenido saneado puede traer una URL larga que, sin
                         cortar, desbordaría la tarjeta en móvil. --}}
                    <div class="prose prose-sm max-w-none overflow-x-auto break-words rounded-lg border border-zinc-200 p-3 text-sm text-zinc-800 dark:border-zinc-700 dark:text-zinc-100">
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
        <div class="border-b border-zinc-200 p-3 dark:border-zinc-700 sm:p-4">
            <flux:heading size="lg">2 · Verificación inversa del rastreador (FCrDNS)</flux:heading>
            <flux:subheading>
                Lo único que ModSecurity no puede hacer: consultar DNS dentro de una regla. Dos pasos,
                PTR y resolución directa, y los dos tienen que coincidir.
            </flux:subheading>
        </div>

        <div class="grid gap-4 p-3 sm:p-4 lg:grid-cols-2">
            <div class="min-w-0 space-y-3">
                {{-- class:input y no class: en flux:input la clase suelta se queda en el div
                     envoltorio y nunca llega al campo. Los 44 px de alto y el tamaño de letra
                     tienen que ir sobre el propio <input> para que surtan efecto. --}}
                <flux:input wire:model="ipRastreador" label="Dirección IP" placeholder="66.249.66.1" class:input="min-h-11 sm:min-h-0" />
                <flux:input wire:model="agenteRastreador" label="Cabecera User-Agent" class:input="font-mono min-h-11 sm:min-h-0 sm:text-xs" />

                <flux:button size="sm" variant="primary" class="w-full min-h-11 sm:w-auto sm:min-h-0" wire:click="verificarRastreador" icon="magnifying-glass">
                    Verificar
                </flux:button>

                <p class="break-words text-sm text-zinc-500 dark:text-zinc-400 sm:text-xs">
                    Pruebe con <span class="font-mono">66.249.66.1</span> (Googlebot real) y con la dirección
                    desde la que navega ahora mismo. La segunda dice ser Googlebot y no lo es.
                </p>
            </div>

            <div class="min-w-0">
                @if ($this->veredictoRastreador === null)
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Sin consulta todavía.</p>
                @else
                    @php $v = $this->veredictoRastreador; @endphp

                    <div @class([
                        'rounded-lg border p-3 sm:p-4 space-y-2',
                        'border-emerald-500/40 bg-emerald-500/10' => $v['verificado'],
                        'border-red-500/40 bg-red-500/10' => ! $v['verificado'] && $v['declara_ser_bot'],
                        'border-zinc-300 dark:border-zinc-700' => ! $v['declara_ser_bot'],
                    ])>
                        {{-- Igual que arriba: el color refuerza, la frase decide. --}}
                        <p @class([
                            'break-words text-sm font-semibold',
                            'text-zinc-900 dark:text-white' => ! $v['declara_ser_bot'],
                            'text-emerald-700 dark:text-emerald-300' => $v['declara_ser_bot'] && $v['verificado'],
                            'text-red-700 dark:text-red-300' => $v['declara_ser_bot'] && ! $v['verificado'],
                        ])>
                            @if (! $v['declara_ser_bot'])
                                No dice ser un rastreador: tráfico normal, no se verifica nada.
                            @elseif ($v['verificado'])
                                Rastreador legítimo confirmado ({{ $v['familia'] }}).
                            @else
                                Rastreador FALSIFICADO ({{ $v['familia'] }}). Se respondería 403 y se registraría el incidente.
                            @endif
                        </p>

                        {{-- Una sola columna en móvil: la etiqueta encima del valor. Con la
                             columna fija de 9 rem no quedaba sitio para el nombre del PTR
                             (crawl-66-249-66-1.googlebot.com y parecidos) y se salía de la
                             tarjeta. Desde sm vuelve la rejilla de dos columnas, ya con
                             minmax(0,1fr) para que la segunda pueda encoger. --}}
                        <dl class="grid grid-cols-1 gap-y-1 text-xs text-zinc-700 dark:text-zinc-200 sm:grid-cols-[9rem_minmax(0,1fr)] sm:gap-x-3">
                            <dt class="text-zinc-500 dark:text-zinc-400">Dirección</dt>
                            <dd class="break-all font-mono">{{ $v['ip'] }}</dd>

                            <dt class="mt-1 text-zinc-500 dark:text-zinc-400 sm:mt-0">Paso 1 · PTR</dt>
                            <dd class="break-all font-mono">{{ $v['ptr'] ?? 'sin registro PTR' }}</dd>

                            <dt class="mt-1 text-zinc-500 dark:text-zinc-400 sm:mt-0">Paso 2 · directo</dt>
                            <dd class="break-words">
                                @if ($v['verificado'])
                                    el nombre resuelve de vuelta a la misma dirección
                                @elseif ($v['motivo'] === 'dns_directo_no_confirma')
                                    el nombre NO resuelve de vuelta a esta dirección
                                @else
                                    no se llegó a comprobar
                                @endif
                            </dd>

                            <dt class="mt-1 text-zinc-500 dark:text-zinc-400 sm:mt-0">Motivo</dt>
                            <dd class="break-all font-mono">{{ $v['motivo'] }}</dd>
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
        <div class="border-b border-zinc-200 p-3 dark:border-zinc-700 sm:p-4">
            <flux:heading size="lg">3 · Lista blanca de redirección</flux:heading>
            <flux:subheading>
                <code>/ir?destino=</code> no acepta direcciones: acepta claves de un mapa cerrado. Escriba
                una URL completa y compruebe que no hay forma de que salga por ahí.
            </flux:subheading>
        </div>

        <div class="grid gap-4 p-3 sm:p-4 lg:grid-cols-2">
            <div class="min-w-0 space-y-3">
                <flux:input
                    wire:model="claveRedireccion"
                    label="Valor del parámetro destino"
                    placeholder="sat   ·   https://sitio-del-atacante.tld"
                    class:input="min-h-11 sm:min-h-0"
                />

                <flux:button size="sm" variant="primary" class="w-full min-h-11 sm:w-auto sm:min-h-0" wire:click="probarRedireccion" icon="arrow-top-right-on-square">
                    Resolver destino
                </flux:button>

                @if ($this->destinoResuelto !== null)
                    {{-- El veredicto tiene que verse, no deducirse.
                         Antes se mostraba solo la dirección resuelta, y quien probaba una URL
                         maliciosa veía el sitio propio sin entender que eso ERA el rechazo:
                         parecía que el botón no hacía nada. Un control que funciona pero no lo
                         comunica se percibe como un control roto, y en una demostración eso
                         cuesta lo mismo que estar roto de verdad. --}}
                    @php
                        $clavePedida  = trim((string) $this->claveRedireccion);
                        $fueAceptada  = $clavePedida !== '' && array_key_exists($clavePedida, $this->destinosPermitidos);
                        $pareceUrl    = (bool) preg_match('~^(?:[a-z][a-z0-9+.-]*:)?//~i', $clavePedida);
                    @endphp

                    @if ($fueAceptada)
                        <div class="rounded-lg border border-emerald-300 bg-emerald-50 p-3 text-sm dark:border-emerald-500/40 dark:bg-emerald-950/40">
                            <div class="flex items-center gap-1.5">
                                <flux:icon.check-circle class="size-4 shrink-0 text-emerald-700 dark:text-emerald-400" />
                                <p class="font-semibold text-emerald-800 dark:text-emerald-300">
                                    Clave válida · redirección permitida
                                </p>
                            </div>
                            <p class="mt-2 break-all font-mono text-zinc-900 dark:text-white">{{ $this->destinoResuelto }}</p>
                        </div>
                    @else
                        <div class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm dark:border-red-500/40 dark:bg-red-950/40">
                            <div class="flex items-center gap-1.5">
                                <flux:icon.shield-exclamation class="size-4 shrink-0 text-red-700 dark:text-red-400" />
                                <p class="font-semibold text-red-800 dark:text-red-300">
                                    Redirección rechazada · incidente registrado
                                </p>
                            </div>

                            <p class="mt-2 text-zinc-700 dark:text-zinc-300">
                                @if ($pareceUrl)
                                    Lo recibido es una dirección completa, no una clave. El control
                                    nunca acepta direcciones, de modo que no hay nada que validar ni
                                    nada que se le pueda escapar.
                                @else
                                    La clave <span class="font-mono">{{ \Illuminate\Support\Str::limit($clavePedida ?: '(vacía)', 40) }}</span>
                                    no existe en el mapa cerrado. No se rechaza por parecer
                                    peligrosa: se rechaza porque no está.
                                @endif
                            </p>

                            <p class="mt-2 text-xs uppercase tracking-wide text-zinc-600 dark:text-zinc-400">
                                Se redirige al destino por omisión
                            </p>
                            <p class="mt-1 break-all font-mono text-zinc-900 dark:text-white">{{ $this->destinoResuelto }}</p>
                        </div>
                    @endif
                @endif
            </div>

            <div class="min-w-0">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                    Mapa cerrado de destinos
                </p>
                <ul class="divide-y divide-zinc-100 rounded-lg border border-zinc-200 text-sm dark:divide-zinc-800 dark:border-zinc-700">
                    @foreach ($this->destinosPermitidos as $clave => $destino)
                        <li class="flex flex-wrap items-baseline justify-between gap-2 px-3 py-2">
                            <span class="break-all font-mono text-zinc-900 dark:text-white">{{ $clave }}</span>
                            <span class="break-words text-xs text-zinc-500 dark:text-zinc-400">{{ $destino['descripcion'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</div>
