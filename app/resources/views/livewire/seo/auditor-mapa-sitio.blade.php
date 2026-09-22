@php
    use App\Services\Seo\AuditorMapaSitio as Auditor;

    $banco = $this->banco;
    $r = $this->resultado;

    // Color por veredicto en un solo sitio: la tabla, las tarjetas y el banco de pruebas
    // tienen que decir lo mismo con el mismo color, o el ojo deja de confiar en ellos.
    $tono = [
        'limpia' => ['borde' => 'border-emerald-500/40', 'fondo' => 'bg-emerald-500/10', 'texto' => 'text-emerald-700 dark:text-emerald-300'],
        'sospechosa' => ['borde' => 'border-amber-500/40', 'fondo' => 'bg-amber-500/10', 'texto' => 'text-amber-700 dark:text-amber-300'],
        'anomala' => ['borde' => 'border-red-500/40', 'fondo' => 'bg-red-500/10', 'texto' => 'text-red-700 dark:text-red-300'],
        'inyectada' => ['borde' => 'border-red-600/60', 'fondo' => 'bg-red-600/15', 'texto' => 'text-red-700 dark:text-red-300'],
        'fantasma' => ['borde' => 'border-orange-500/40', 'fondo' => 'bg-orange-500/10', 'texto' => 'text-orange-700 dark:text-orange-300'],
    ];

    $etiqueta = [
        'limpia' => 'Limpia',
        'sospechosa' => 'Sospechosa',
        'anomala' => 'Anómala',
        'inyectada' => 'Inyectada y viva',
        'fantasma' => 'Declarada e inexistente',
    ];
@endphp

<div class="space-y-6">
    {{-- ------------------------------------------------------------------ --}}
    {{-- 1 · Banco de pruebas: el caso real                                 --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 p-3 dark:border-zinc-700 sm:p-4">
            <flux:heading size="lg">1 · Banco de pruebas: qué habría detectado esto</flux:heading>
            <flux:subheading>
                Viene cargado con las cinco marcas reales que aparecían en el panel de Search Console
                de una empresa guatemalteca de cámaras de seguridad que está comprometida ahora mismo
                —<span class="font-mono">p9bet login</span> con 45 impresiones y cero clics,
                <span class="font-mono">96n.com</span>, <span class="font-mono">kmj888</span>—,
                escritas como aparecerían dentro de su mapa del sitio. Si Google mostraba ese dominio
                para esas búsquedas, es porque bajo ese dominio había direcciones que hablaban de eso,
                y al rastreador se le llega por el mapa. Las dos últimas líneas son direcciones
                legítimas del catálogo: están a propósito, porque un control también hay que verlo
                callar. Escriba en los campos y el veredicto cambia al teclear; no se descarga nada.
            </flux:subheading>
        </div>

        <div class="grid gap-4 p-3 sm:p-4 lg:grid-cols-2">
            <div class="min-w-0 space-y-3">
                {{-- En móvil se deja el tamaño de letra que trae Flux: Safari de iOS amplía
                     la página sola al enfocar letra menor y la deja desplazada. Desde sm
                     baja a text-xs, que es donde caben las direcciones largas. --}}
                <flux:textarea
                    wire:model.live.debounce.400ms="consultas"
                    label="Direcciones o consultas a evaluar (una por línea)"
                    rows="8"
                    class="font-mono sm:text-xs"
                />

                <flux:textarea
                    wire:model.live.debounce.400ms="vocabulario"
                    label="Vocabulario legítimo del sitio"
                    description="Es lo que permite decidir que «porh300» es ajeno: no está en ninguna lista negra, pero no comparte una sola palabra con lo que este negocio vende."
                    rows="4"
                    class="font-mono sm:text-xs"
                />
            </div>

            <div class="min-w-0 space-y-2">
                @forelse ($banco as $caso)
                    @php $t = $tono[$caso['veredicto']] ?? $tono['limpia']; @endphp

                    <div class="rounded-lg border {{ $t['borde'] }} {{ $t['fondo'] }} p-3">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <p class="min-w-0 break-all font-mono text-sm text-zinc-900 dark:text-white">
                                {{ $caso['texto'] }}
                            </p>
                            <div class="flex shrink-0 items-center gap-2">
                                <span class="text-xs font-semibold uppercase tracking-wide {{ $t['texto'] }}">
                                    {{ $etiqueta[$caso['veredicto']] ?? $caso['veredicto'] }}
                                </span>
                                <span class="text-xl font-semibold tabular-nums text-zinc-900 dark:text-white">
                                    {{ $caso['puntuacion'] }}
                                </span>
                            </div>
                        </div>

                        @if ($caso['motivos'] !== [])
                            <ul class="mt-2 space-y-1">
                                @foreach ($caso['motivos'] as $motivo)
                                    <li class="text-xs text-zinc-600 dark:text-zinc-300">
                                        <span class="font-mono text-zinc-500 dark:text-zinc-400">{{ $motivo['regla'] }}</span>
                                        <span class="tabular-nums">+{{ $motivo['puntos'] }}</span>
                                        · {{ $motivo['descripcion'] }}
                                        <span class="block break-all font-mono text-[11px] text-zinc-400">{{ $motivo['evidencia'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                Ninguna señal: se comporta como una consulta propia del negocio.
                            </p>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Escriba al menos una línea.</p>
                @endforelse

                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                    Umbral de anomalía {{ Auditor::UMBRAL_ANOMALA }}, el mismo de
                    <span class="font-mono">DetectorSpamSeo</span> y el mismo del WAF: ninguna señal
                    aislada prueba nada, lo que delata la inyección es la acumulación.
                </p>
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- 2 · Auditoría del sitio publicado                                  --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 p-3 dark:border-zinc-700 sm:p-4">
            <flux:heading size="lg">2 · Auditoría del mapa del sitio y del archivo de exclusión</flux:heading>
            <flux:subheading>
                Descarga el mapa del sitio —siguiendo los índices de mapas si los hay— y el archivo de
                exclusión de rastreadores, analiza cada dirección declarada, pide una muestra de las
                sospechosas para ver si existen y compara todo contra la línea base sellada. Esto sí
                registra incidente: se está mirando un sitio real.
            </flux:subheading>
        </div>

        <div class="space-y-3 p-3 sm:p-4">
            <div class="grid gap-3 sm:grid-cols-2">
                <flux:input
                    wire:model="sitio"
                    label="Dirección del sitio"
                    placeholder="https://marketgt.duckdns.org"
                    class:input="font-mono min-h-11 sm:min-h-0"
                />

                <flux:input
                    wire:model="mapa"
                    label="Mapa concreto (opcional)"
                    description="Vacío: se usa el que declare robots.txt, y si no, /sitemap.xml"
                    placeholder="https://.../sitemap_index.xml"
                    class:input="font-mono min-h-11 sm:min-h-0"
                />
            </div>

            <div>
                <flux:checkbox
                    wire:model="comprobarRespuestas"
                    label="Comprobar si las direcciones sospechosas responden"
                />
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    Pide hasta {{ Auditor::MUESTRA_COMPROBACION }} páginas. Distingue la basura
                    (404) de la página inyectada que está viva (200). La muestra se limita para no
                    tardar eternamente ni parecer un escáner.
                </p>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                <flux:button
                    variant="primary"
                    icon="magnifying-glass"
                    class="w-full min-h-11 sm:w-auto sm:min-h-0"
                    wire:click="auditar"
                    wire:loading.attr="disabled"
                >
                    Auditar
                </flux:button>

                @if (auth()->user()?->esAdministrador())
                    <flux:button
                        variant="ghost"
                        icon="lock-closed"
                        class="w-full min-h-11 sm:w-auto sm:min-h-0"
                        wire:click="sellarLineaBase"
                        wire:loading.attr="disabled"
                        wire:confirm="Sellar declara que el mapa ACTUAL es el autorizado. Si ya hay direcciones inyectadas, quedarán legitimadas y dejarán de contar como nuevas para siempre. ¿Continuar?"
                    >
                        Sellar línea base
                    </flux:button>
                @endif

                <span wire:loading wire:target="auditar,sellarLineaBase" class="inline-flex items-center gap-2 text-sm text-zinc-500 dark:text-zinc-400">
                    <flux:icon.loading class="size-4" />
                    Descargando y analizando…
                </span>
            </div>

            @if ($error !== '')
                <div class="flex items-start gap-3 rounded-lg border border-red-500/40 bg-red-500/10 p-3">
                    <flux:icon.exclamation-triangle class="mt-0.5 size-5 shrink-0 text-red-600 dark:text-red-400" />
                    <p class="min-w-0 break-words text-sm text-red-700 dark:text-red-300">{{ $error }}</p>
                </div>
            @endif

            @unless ($this->tablaLista)
                <div class="flex items-start gap-3 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3">
                    <flux:icon.exclamation-triangle class="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400" />
                    <div class="min-w-0 text-sm">
                        <p class="font-semibold text-amber-700 dark:text-amber-300">Falta la tabla del componente</p>
                        <p class="mt-1 break-words text-zinc-600 dark:text-zinc-400">
                            Ejecute <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-800">php artisan migrate</code>
                            para crear <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-800">hallazgos_mapa_sitio</code>.
                            El análisis funciona igual; lo que no se guarda es la evidencia.
                        </p>
                    </div>
                </div>
            @endunless
        </div>
    </div>

    @if ($r !== null)
        {{-- Cifras de la corrida --}}
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @php
                $tarjetas = [
                    ['titulo' => 'Direcciones declaradas', 'valor' => $r['resumen']['total'], 'pie' => count($r['mapas']).' mapa(s) leído(s) · profundidad mediana '.$r['resumen']['profundidad_mediana']],
                    ['titulo' => 'Anómalas', 'valor' => $r['resumen']['anomalas'] + $r['resumen']['inyectadas'] + $r['resumen']['fantasmas'], 'pie' => $r['resumen']['sospechosas'].' sospechosas por debajo del umbral'],
                    ['titulo' => 'Inyectadas y vivas', 'valor' => $r['resumen']['inyectadas'], 'pie' => $r['resumen']['fantasmas'].' declaradas pero inexistentes'],
                    ['titulo' => 'Exclusión de rastreadores', 'valor' => $r['resumen']['hallazgos_exclusion'], 'pie' => 'directivas peligrosas en robots.txt'],
                ];
            @endphp

            @foreach ($tarjetas as $tarjeta)
                <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900 sm:p-4">
                    <p class="break-words text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        {{ $tarjeta['titulo'] }}
                    </p>
                    <p class="mt-2 text-3xl font-semibold tabular-nums text-zinc-900 dark:text-white">
                        {{ number_format((int) $tarjeta['valor']) }}
                    </p>
                    <p class="mt-1 break-words text-xs text-zinc-500 dark:text-zinc-400">{{ $tarjeta['pie'] }}</p>
                </div>
            @endforeach
        </div>

        {{-- Línea base: el cambio es lo que delata la inyección --}}
        <div @class([
            'rounded-xl border p-3 sm:p-4',
            'border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900' => ! $r['linea_base']['cambio'] && ! $r['linea_base']['exclusion_cambiada'],
            'border-red-500/40 bg-red-500/10' => $r['linea_base']['cambio'] || $r['linea_base']['exclusion_cambiada'],
        ])>
            <flux:heading size="lg">Comparación con la línea base</flux:heading>

            @if (! $r['linea_base']['existe'])
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                    No hay línea base sellada para <span class="font-mono">{{ $r['sitio'] }}</span>.
                    Esta corrida solo puede juzgar el contenido, no el cambio. Lo valioso de este
                    control es detectar que un mapa gana ciento veinte direcciones de un día para
                    otro, y para eso hace falta sellar el estado autorizado primero.
                </p>
                {{-- Se dice en voz alta en vez de dar una confianza que no se tiene: sin línea
                     base, el vocabulario propio se aprende del mapa que se está auditando, y si
                     ese mapa ya está envenenado el control aprende del atacante. --}}
                <p class="mt-2 text-xs text-amber-700 dark:text-amber-300">
                    Sin sellar, el vocabulario propio del sitio se deduce del mapa actual. Si el
                    mapa ya trae la inyección, las palabras del atacante cuentan como propias y la
                    regla de vocabulario pierde fuerza. Las demás reglas no dependen de esto.
                </p>
            @else
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                    Sellada el {{ $r['linea_base']['sellada_en'] ?? 'sin fecha' }} con
                    <span class="tabular-nums">{{ number_format((int) $r['linea_base']['cantidad_anterior']) }}</span>
                    direcciones; ahora declara
                    <span class="tabular-nums">{{ number_format($r['linea_base']['cantidad_actual']) }}</span>.
                    @if ($r['linea_base']['crecimiento_subito'])
                        <strong class="text-red-700 dark:text-red-300">
                            El crecimiento no lo explica el ritmo de publicación del sitio.
                        </strong>
                    @elseif (! $r['linea_base']['cambio'])
                        El conjunto de direcciones sigue siendo el autorizado.
                    @endif
                    @if ($r['linea_base']['exclusion_cambiada'])
                        <strong class="text-red-700 dark:text-red-300">El archivo de exclusión también cambió.</strong>
                    @endif
                </p>

                @if ($r['linea_base']['nuevas'] !== [])
                    <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        Direcciones nuevas desde el sellado
                    </p>
                    <ul class="mt-1 space-y-0.5">
                        @foreach ($r['linea_base']['nuevas'] as $nueva)
                            <li class="break-all font-mono text-xs text-zinc-700 dark:text-zinc-200">{{ $nueva }}</li>
                        @endforeach
                    </ul>
                @endif

                @if ($r['linea_base']['desaparecidas'] !== [])
                    <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        Ya no se declaran
                    </p>
                    <ul class="mt-1 space-y-0.5">
                        @foreach ($r['linea_base']['desaparecidas'] as $ida)
                            <li class="break-all font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $ida }}</li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </div>

        {{-- Direcciones con señal --}}
        <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="border-b border-zinc-200 p-3 dark:border-zinc-700 sm:p-4">
                <flux:heading size="lg">Direcciones con señal</flux:heading>
                <flux:subheading>
                    {{ $r['sitio'] }} · corrida <span class="font-mono">{{ $r['ejecucion'] }}</span> ·
                    {{ $r['momento'] }} · {{ $r['duracion_ms'] }} ms ·
                    {{ $r['escrito']['hallazgos'] }} hallazgo(s) y {{ $r['escrito']['incidentes'] }} incidente(s) escritos.
                    Las direcciones limpias no se listan: son casi todo el mapa y su única información
                    útil es el recuento de arriba.
                </flux:subheading>
            </div>

            @if ($r['direcciones'] === [])
                <p class="p-3 text-sm text-zinc-500 dark:text-zinc-400 sm:p-4">
                    Ninguna dirección declarada levantó una sola señal.
                </p>
            @else
                {{-- El ancho mínimo va en la tabla, no en el contenedor que desplaza. En móvil
                     quedan dirección, puntuación y veredicto, que es lo que hay que leer; el
                     código de respuesta y los motivos aparecen cuando hay anchura. --}}
                <div class="overflow-x-auto">
                    <table class="w-full text-sm sm:min-w-[34rem] lg:min-w-[48rem]">
                        <thead>
                            <tr class="border-b border-zinc-200 text-left text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                                <th class="px-3 py-3 font-medium sm:px-4">Dirección declarada</th>
                                <th class="px-3 py-3 text-right font-medium sm:px-4">Puntos</th>
                                <th class="px-3 py-3 font-medium sm:px-4">Veredicto</th>
                                <th class="hidden px-3 py-3 font-medium md:table-cell sm:px-4">Respuesta</th>
                                <th class="hidden px-3 py-3 font-medium lg:table-cell sm:px-4">Motivo</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($r['direcciones'] as $d)
                                @php $t = $tono[$d['veredicto']] ?? $tono['limpia']; @endphp
                                <tr>
                                    <td class="px-3 py-2 sm:px-4">
                                        <span class="block break-all font-mono text-xs text-zinc-800 dark:text-zinc-100">{{ $d['url'] }}</span>
                                        @if ($d['titulo_remoto'])
                                            <span class="mt-0.5 block break-words text-[11px] text-zinc-500 dark:text-zinc-400">
                                                Título servido: {{ $d['titulo_remoto'] }}
                                            </span>
                                        @endif
                                        @if ($d['destino_final'])
                                            <span class="mt-0.5 block break-all text-[11px] text-red-600 dark:text-red-400">
                                                Acabó en: {{ $d['destino_final'] }}
                                            </span>
                                        @endif
                                        {{-- Los motivos se repiten aquí para las pantallas estrechas, donde la
                                             última columna no existe: sin esto, en un teléfono la tabla diría
                                             «anómala» sin decir nunca por qué. --}}
                                        <span class="mt-1 block break-words text-[11px] text-zinc-500 lg:hidden dark:text-zinc-400">
                                            {{ collect($d['motivos'])->pluck('regla')->join(', ') }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums text-zinc-900 dark:text-white sm:px-4">{{ $d['puntuacion'] }}</td>
                                    <td class="px-3 py-2 sm:px-4">
                                        <span class="inline-block rounded px-2 py-0.5 text-xs font-medium {{ $t['fondo'] }} {{ $t['texto'] }}">
                                            {{ $etiqueta[$d['veredicto']] ?? $d['veredicto'] }}
                                        </span>
                                    </td>
                                    <td class="hidden px-3 py-2 tabular-nums text-zinc-600 md:table-cell dark:text-zinc-300 sm:px-4">
                                        @if ($d['codigo_http'] === null)
                                            <span class="text-zinc-400">no pedida</span>
                                        @elseif ($d['codigo_http'] === 0)
                                            <span class="text-zinc-400">sin respuesta</span>
                                        @else
                                            {{ $d['codigo_http'] }}
                                        @endif
                                    </td>
                                    <td class="hidden px-3 py-2 lg:table-cell sm:px-4">
                                        <ul class="space-y-0.5">
                                            @foreach ($d['motivos'] as $motivo)
                                                <li class="break-words text-xs text-zinc-600 dark:text-zinc-300">
                                                    <span class="font-mono text-zinc-500 dark:text-zinc-400">{{ $motivo['regla'] }}</span>
                                                    <span class="tabular-nums">+{{ $motivo['puntos'] }}</span>
                                                    · {{ $motivo['descripcion'] }}
                                                    <span class="block break-all font-mono text-[11px] text-zinc-400">{{ $motivo['evidencia'] }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Archivo de exclusión y mapas leídos --}}
        <div class="grid gap-4 lg:grid-cols-2">
            <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900 sm:p-4">
                <flux:heading size="lg">Archivo de exclusión de rastreadores</flux:heading>
                <p class="mt-1 break-all font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $r['exclusion']['url'] }}</p>

                @if (! $r['exclusion']['disponible'])
                    <p class="mt-3 text-sm text-amber-700 dark:text-amber-300">
                        No se pudo leer. Sin él no hay forma de saber qué se pidió excluir.
                    </p>
                @else
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                        {{ number_format($r['exclusion']['bytes']) }} bytes ·
                        {{ count($r['exclusion']['sitemaps_declarados']) }} mapa(s) declarado(s) del propio dominio.
                    </p>

                    @if ($r['exclusion']['hallazgos'] === [])
                        <p class="mt-3 text-sm text-emerald-700 dark:text-emerald-300">
                            Sin directivas peligrosas.
                        </p>
                    @else
                        <ul class="mt-3 space-y-2">
                            @foreach ($r['exclusion']['hallazgos'] as $hallazgo)
                                <li class="rounded-lg border border-red-500/40 bg-red-500/10 p-2">
                                    <p class="break-words text-sm text-zinc-800 dark:text-zinc-100">
                                        {{ $hallazgo['descripcion'] }}
                                    </p>
                                    <p class="mt-0.5 break-all font-mono text-[11px] text-zinc-500 dark:text-zinc-400">
                                        {{ $hallazgo['regla'] }} +{{ $hallazgo['puntos'] }} · {{ $hallazgo['evidencia'] }}
                                    </p>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endif
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900 sm:p-4">
                <flux:heading size="lg">Mapas leídos</flux:heading>

                <div class="mt-2 overflow-x-auto">
                    <table class="w-full text-sm sm:min-w-[26rem]">
                        <thead>
                            <tr class="border-b border-zinc-200 text-left text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                                <th class="py-2 pr-3 font-medium">Mapa</th>
                                <th class="py-2 pr-3 font-medium">Tipo</th>
                                <th class="py-2 text-right font-medium">Entradas</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($r['mapas'] as $m)
                                <tr>
                                    <td class="break-all py-2 pr-3 font-mono text-xs text-zinc-700 dark:text-zinc-200">{{ $m['url'] }}</td>
                                    <td class="py-2 pr-3 text-xs text-zinc-600 dark:text-zinc-300">
                                        {{ $m['tipo'] }}
                                        <span class="block text-[11px] text-zinc-400">{{ $m['detalle'] }}</span>
                                    </td>
                                    <td class="py-2 text-right tabular-nums text-zinc-900 dark:text-white">{{ $m['direcciones'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($r['errores'] !== [])
                    <ul class="mt-3 space-y-1">
                        @foreach ($r['errores'] as $fallo)
                            <li class="break-words text-xs text-amber-700 dark:text-amber-300">{{ $fallo }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    @endif

    {{-- Memoria del control: sin ella cada pasada empieza de cero --}}
    @if ($this->historial !== [])
        <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="border-b border-zinc-200 p-3 dark:border-zinc-700 sm:p-4">
                <flux:heading size="lg">Hallazgos graves de auditorías anteriores</flux:heading>
                <flux:subheading>
                    Lo que encontraron las pasadas previas, incluida la que corre sola cada hora con
                    <span class="font-mono">seo:auditar-mapa</span>. Nadie revisa a mano su mapa del
                    sitio todos los días, y por eso esta lista es la que de verdad se mira.
                </flux:subheading>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm sm:min-w-[30rem] lg:min-w-[40rem]">
                    <thead>
                        <tr class="border-b border-zinc-200 text-left text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                            <th class="px-3 py-3 font-medium sm:px-4">Cuándo</th>
                            <th class="px-3 py-3 font-medium sm:px-4">Dirección</th>
                            <th class="px-3 py-3 font-medium sm:px-4">Veredicto</th>
                            <th class="hidden px-3 py-3 font-medium md:table-cell sm:px-4">Reglas</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($this->historial as $hallazgo)
                            @php $t = $tono[$hallazgo->veredicto] ?? $tono['sospechosa']; @endphp
                            <tr>
                                <td class="whitespace-nowrap px-3 py-2 text-xs text-zinc-600 dark:text-zinc-300 sm:px-4">
                                    {{ $hallazgo->created_at?->format('d/m H:i') }}
                                </td>
                                <td class="px-3 py-2 sm:px-4">
                                    <span class="block break-all font-mono text-xs text-zinc-800 dark:text-zinc-100">{{ $hallazgo->urlCorta() }}</span>
                                    <span class="block text-[11px] text-zinc-400">{{ $hallazgo->sitio }} · {{ $hallazgo->etiquetaTipo() }}</span>
                                </td>
                                <td class="px-3 py-2 sm:px-4">
                                    <span class="inline-block rounded px-2 py-0.5 text-xs font-medium {{ $t['fondo'] }} {{ $t['texto'] }}">
                                        {{ $hallazgo->etiquetaVeredicto() }}
                                    </span>
                                </td>
                                <td class="hidden px-3 py-2 md:table-cell sm:px-4">
                                    <span class="break-words font-mono text-[11px] text-zinc-500 dark:text-zinc-400">
                                        {{ implode(', ', $hallazgo->reglas()) }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
