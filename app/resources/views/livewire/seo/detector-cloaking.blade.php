@php
    use App\Models\ComparacionContenido;
    use App\Services\Seo\DetectorContenidoDiferenciado;

    $resultado = $this->resultado;
    $resumen = $resultado['resumen'] ?? null;
    $perfiles = $resultado['perfiles'] ?? [];
    $comparaciones = $resultado['comparaciones'] ?? [];

    // El color del veredicto se decide una vez y se usa en los cuatro sitios donde aparece.
    // Repetir el match en cada bloque es la forma segura de que dentro de un mes la tabla
    // pinte en rojo lo que la cabecera pinta en verde.
    //
    // Las clases van ESCRITAS ENTERAS, nunca compuestas como "border-{$color}-300". Tailwind
    // no ejecuta la plantilla: busca nombres de clase como texto en el archivo, y una clase
    // armada a trozos no existe para él. El resultado sería un panel sin colores justo en la
    // pantalla cuyo trabajo es que se vea de un vistazo lo que está mal.
    $paleta = [
        ComparacionContenido::VEREDICTO_CLOAKING => [
            'badge' => 'red',
            'marco' => 'border-red-300 bg-red-50 dark:border-red-700 dark:bg-red-950/30',
            'numero' => 'text-red-700 dark:text-red-300',
            'borde' => 'border-red-200 dark:border-red-800',
            'texto' => 'text-red-800 dark:text-red-200',
        ],
        ComparacionContenido::VEREDICTO_SOSPECHOSO => [
            'badge' => 'amber',
            'marco' => 'border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-950/30',
            'numero' => 'text-amber-700 dark:text-amber-300',
            'borde' => 'border-amber-200 dark:border-amber-800',
            'texto' => 'text-amber-800 dark:text-amber-200',
        ],
        ComparacionContenido::VEREDICTO_ESPERABLE => [
            'badge' => 'blue',
            'marco' => 'border-blue-300 bg-blue-50 dark:border-blue-700 dark:bg-blue-950/30',
            'numero' => 'text-blue-700 dark:text-blue-300',
            'borde' => 'border-blue-200 dark:border-blue-800',
            'texto' => 'text-blue-800 dark:text-blue-200',
        ],
        ComparacionContenido::VEREDICTO_SIN_DIFERENCIAS => [
            'badge' => 'green',
            'marco' => 'border-green-300 bg-green-50 dark:border-green-700 dark:bg-green-950/30',
            'numero' => 'text-green-700 dark:text-green-300',
            'borde' => 'border-green-200 dark:border-green-800',
            'texto' => 'text-green-800 dark:text-green-200',
        ],
        ComparacionContenido::VEREDICTO_INCOMPLETO => [
            'badge' => 'zinc',
            'marco' => 'border-zinc-300 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800/50',
            'numero' => 'text-zinc-700 dark:text-zinc-300',
            'borde' => 'border-zinc-200 dark:border-zinc-700',
            'texto' => 'text-zinc-800 dark:text-zinc-200',
        ],
    ];

    $estiloDe = static fn (?string $veredicto): array => $paleta[$veredicto]
        ?? $paleta[ComparacionContenido::VEREDICTO_INCOMPLETO];
@endphp

<div class="space-y-6">
    {{-- ------------------------------------------------------------------ --}}
    {{-- Por qué existe esta pantalla. Es el guion de la demostración.       --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 p-3 dark:border-zinc-700 sm:p-4">
            <flux:heading size="lg">Detección de contenido diferenciado (cloaking)</flux:heading>
            <flux:subheading>
                Se pide la misma dirección cinco veces cambiando solo cómo se presenta el cliente, y se
                compara lo que vuelve. Si la versión que recibe Googlebot lleva contenido que la versión
                del navegador no lleva, el sitio sirve contenido diferenciado.
            </flux:subheading>
        </div>

        <div class="grid gap-4 p-3 text-sm text-zinc-600 dark:text-zinc-300 sm:p-4 lg:grid-cols-2">
            <div class="min-w-0 space-y-2">
                <p class="font-semibold text-zinc-800 dark:text-zinc-100">El caso que originó este control</p>
                <p class="break-words">
                    Una empresa guatemalteca que vende cámaras de seguridad tenía en Search Console, junto a
                    sus consultas legítimas, estas otras:
                    <span class="font-mono">p9bet login</span> (45 impresiones, 0 clics),
                    <span class="font-mono">0016bet</span> (13/0),
                    <span class="font-mono">96n.com</span> (11/1),
                    <span class="font-mono">kmj888</span> (10/0) y
                    <span class="font-mono">porh300</span> (2/1). Marcas de casas de apuestas asiáticas bajo
                    un dominio de cámaras.
                </p>
            </div>

            <div class="min-w-0 space-y-2">
                <p class="font-semibold text-zinc-800 dark:text-zinc-100">Por qué el laboratorio de contenido no lo habría visto</p>
                <p class="break-words">
                    El laboratorio sanea lo que ENTRA por un formulario. Aquí el atacante ya tenía acceso al
                    servidor: escribió el archivo directamente y ninguna sanitización llegó a verlo. El
                    responsable del sitio navegaba su propia página y no veía nada raro, y tenía razón: a él
                    no se lo servían. Esta pantalla es la que explica esa contradicción.
                </p>
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- ADVERTENCIA DE USO. En grande y arriba, no en letra pequeña.        --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="rounded-xl border-2 border-amber-400 bg-amber-50 p-3 dark:border-amber-600 dark:bg-amber-950/40 sm:p-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
            <flux:icon.exclamation-triangle class="size-6 shrink-0 text-amber-600 dark:text-amber-400" />
            <div class="min-w-0 space-y-2">
                <p class="text-base font-bold text-amber-900 dark:text-amber-100">
                    Esta herramienta hace peticiones a sitios de terceros
                </p>
                <p class="text-sm text-amber-900 dark:text-amber-100">
                    {{ $this->aviso }}
                </p>
                <p class="text-sm text-amber-900 dark:text-amber-100">
                    Es una herramienta legítima de diagnóstico, del mismo tipo que la prueba de URL de
                    Search Console. Lo que la hace legítima es el permiso, no la técnica.
                </p>
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Entrada                                                             --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 p-3 dark:border-zinc-700 sm:p-4">
            <flux:heading size="lg">1 · Dirección que se va a comparar</flux:heading>
            <flux:subheading>
                Una dirección completa, con protocolo. Solo se admiten anfitriones públicos: una dirección
                interna convertiría esta pantalla en un escáner de la red del servidor.
            </flux:subheading>
        </div>

        <div class="space-y-4 p-3 sm:p-4">
            <flux:input
                wire:model="url"
                label="Dirección"
                type="url"
                class="font-mono sm:text-sm"
                placeholder="https://ejemplo.gt/pagina"
            />

            <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                <flux:checkbox
                    wire:model="autorizado"
                    label="Declaro que este sitio es propio o que cuento con autorización del responsable para analizarlo."
                />
                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                    La casilla no se recuerda entre visitas: una autorización que se marca sola deja de ser
                    una decisión. Ver el caso real no la necesita, porque no hace ninguna petición.
                </p>
                @error('autorizado')
                    <p class="mt-2 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                <flux:button
                    size="sm"
                    variant="primary"
                    class="w-full min-h-11 sm:w-auto sm:min-h-0"
                    wire:click="comparar"
                    wire:loading.attr="disabled"
                    icon="magnifying-glass"
                >
                    Comparar las cinco identidades
                </flux:button>

                <flux:button
                    size="sm"
                    variant="ghost"
                    class="w-full min-h-11 sm:w-auto sm:min-h-0"
                    wire:click="demostrar"
                    icon="beaker"
                >
                    Ver el caso real (sin red)
                </flux:button>

                <flux:button
                    size="sm"
                    variant="ghost"
                    class="w-full min-h-11 sm:w-auto sm:min-h-0"
                    wire:click="limpiar"
                    icon="x-mark"
                >
                    Limpiar
                </flux:button>

                <span wire:loading wire:target="comparar" class="text-xs text-zinc-500 dark:text-zinc-400">
                    Pidiendo la página cinco veces. Puede tardar hasta un minuto si el sitio responde despacio.
                </span>
            </div>

            @error('url')
                <p class="text-sm font-semibold text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>
    </div>

    @if ($resultado !== null && $resumen !== null)
        @php
            $estilo = $estiloDe($resumen['veredicto']);
        @endphp

        {{-- -------------------------------------------------------------- --}}
        {{-- Veredicto                                                       --}}
        {{-- -------------------------------------------------------------- --}}
        <div class="rounded-xl border-2 {{ $estilo['marco'] }}">
            <div class="grid gap-4 p-3 sm:p-4 lg:grid-cols-4">
                <div class="flex items-center gap-3 lg:flex-col lg:items-start lg:justify-center">
                    <div class="text-4xl font-black {{ $estilo['numero'] }}">
                        {{ $resumen['puntuacion'] }}
                    </div>
                    <div class="min-w-0">
                        <flux:badge size="sm" :color="$estilo['badge']">{{ $resumen['etiqueta_veredicto'] }}</flux:badge>
                        <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">
                            puntos de divergencia · umbrales
                            {{ DetectorContenidoDiferenciado::UMBRAL_SOSPECHA }} y
                            {{ DetectorContenidoDiferenciado::UMBRAL_CLOAKING }}
                        </p>
                    </div>
                </div>

                <div class="min-w-0 space-y-2 lg:col-span-3">
                    <p class="break-words font-mono text-xs text-zinc-600 dark:text-zinc-300">{{ $resultado['url'] }}</p>
                    <p class="text-sm text-zinc-800 dark:text-zinc-100">{{ $resumen['explicacion'] }}</p>

                    @if ($resumen['concluyentes'] !== [])
                        <div class="space-y-1">
                            <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                Señales concluyentes
                            </p>
                            <ul class="space-y-1">
                                @foreach ($resumen['concluyentes'] as $concluyente)
                                    <li class="flex items-start gap-2 text-sm {{ $estilo['texto'] }}">
                                        <flux:icon.exclamation-circle class="mt-0.5 size-4 shrink-0" />
                                        <span class="break-words">{{ $concluyente }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="flex flex-wrap gap-2 text-xs text-zinc-600 dark:text-zinc-300">
                        <span class="rounded bg-white/70 px-2 py-1 dark:bg-zinc-900/60">
                            {{ $resumen['perfiles_alcanzados'] }} de 5 identidades respondieron
                        </span>
                        <span class="rounded bg-white/70 px-2 py-1 dark:bg-zinc-900/60">
                            {{ $resultado['duracion_ms'] }} ms
                        </span>
                        @if ($resumen['spam_en_todas_las_versiones'])
                            <span class="rounded bg-red-100 px-2 py-1 font-semibold text-red-800 dark:bg-red-900/50 dark:text-red-200">
                                Vocabulario de abuso en TODAS las versiones
                            </span>
                        @endif

                        {{-- Un secuestro servido igual a las cinco identidades no produce ni una
                             señal de divergencia. Sin este aviso, la pantalla más tranquilizadora
                             sería la del sitio que redirige a todo el mundo al dominio del atacante. --}}
                        @if ($resumen['referencia_comprometida'] ?? false)
                            <span class="rounded bg-red-100 px-2 py-1 font-semibold text-red-800 dark:bg-red-900/50 dark:text-red-200">
                                La propia versión de navegador ya está comprometida
                            </span>
                        @endif
                    </div>
                </div>
            </div>

            @if ($resultado['simulada'] ?? false)
                <div class="border-t {{ $estilo['borde'] }} p-3 text-xs text-zinc-600 dark:text-zinc-300 sm:p-4">
                    <span class="font-semibold">Reproducción, no medición en vivo.</span>
                    {{ $resultado['nota'] }}
                </div>
            @endif

            <div class="flex flex-col gap-2 border-t {{ $estilo['borde'] }} p-3 sm:flex-row sm:items-center sm:p-4">
                <flux:button
                    size="sm"
                    variant="primary"
                    class="w-full min-h-11 sm:w-auto sm:min-h-0"
                    wire:click="registrarComparacion"
                    icon="archive-box-arrow-down"
                >
                    Guardar y abrir incidente si procede
                </flux:button>

                @if ($registrado)
                    <span class="text-sm font-semibold text-green-700 dark:text-green-400">
                        Guardado en el historial.
                    </span>
                @endif

                <span class="text-xs text-zinc-500 dark:text-zinc-400">
                    Comparar no guarda nada. Guardar es un acto aparte para poder repetir sin ensuciar el histórico.
                </span>
            </div>
        </div>

        {{-- -------------------------------------------------------------- --}}
        {{-- Lo que ve cada identidad, lado a lado                           --}}
        {{-- -------------------------------------------------------------- --}}
        <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="border-b border-zinc-200 p-3 dark:border-zinc-700 sm:p-4">
                <flux:heading size="lg">2 · Lo que recibió cada identidad</flux:heading>
                <flux:subheading>
                    La primera tarjeta es la referencia: lo que ve el responsable del sitio. Las otras cuatro
                    se comparan contra ella.
                </flux:subheading>
            </div>

            <div class="grid gap-3 p-3 sm:p-4 lg:grid-cols-2">
                @foreach ($perfiles as $clave => $perfil)
                    @php
                        $divergente = in_array($clave, $resumen['perfiles_divergentes'], true);
                    @endphp

                    <div @class([
                        'min-w-0 rounded-lg border p-3',
                        'border-zinc-300 bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800/60' => $perfil['referencia'],
                        'border-red-300 bg-red-50 dark:border-red-700 dark:bg-red-950/30' => $divergente,
                        'border-zinc-200 dark:border-zinc-700' => ! $perfil['referencia'] && ! $divergente,
                    ])>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $perfil['etiqueta'] }}</span>

                            @if ($perfil['referencia'])
                                <flux:badge size="sm" color="zinc">Referencia</flux:badge>
                            @endif

                            @if ($perfil['alcanzado'])
                                <flux:badge size="sm" :color="$perfil['codigo'] >= 300 ? 'amber' : 'green'">
                                    HTTP {{ $perfil['codigo'] }}
                                </flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">Sin respuesta</flux:badge>
                            @endif

                            <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $perfil['ms'] }} ms</span>
                        </div>

                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $perfil['descripcion'] }}</p>

                        <p class="mt-2 break-all font-mono text-[11px] leading-snug text-zinc-500 dark:text-zinc-400">
                            {{ $perfil['agente_usuario'] }}
                        </p>

                        @if ($perfil['procedencia'])
                            <p class="break-all font-mono text-[11px] text-zinc-500 dark:text-zinc-400">
                                Referer: {{ $perfil['procedencia'] }}
                            </p>
                        @endif

                        @if ($perfil['error'])
                            <p class="mt-2 rounded bg-zinc-100 p-2 text-xs text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                                {{ $perfil['error'] }}
                            </p>
                        @endif

                        @if ($perfil['cadena'] !== [])
                            <div class="mt-2 space-y-1">
                                @foreach ($perfil['cadena'] as $salto)
                                    <p class="break-all text-xs text-amber-700 dark:text-amber-300">
                                        {{ $salto['codigo'] }} →
                                        <span class="font-mono">{{ $salto['hacia'] }}</span>
                                    </p>
                                @endforeach
                            </div>
                        @endif

                        <dl class="mt-3 space-y-2 text-xs">
                            <div class="min-w-0">
                                <dt class="font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Título</dt>
                                <dd class="break-words text-zinc-800 dark:text-zinc-100">{{ $perfil['titulo'] ?: '(sin título)' }}</dd>
                            </div>

                            <div class="min-w-0">
                                <dt class="font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Descripción</dt>
                                <dd class="break-words text-zinc-700 dark:text-zinc-200">{{ $perfil['descripcion_meta'] ?: '(sin descripción)' }}</dd>
                            </div>

                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                <div class="min-w-0">
                                    <dt class="font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Canónico</dt>
                                    <dd class="break-all font-mono text-zinc-700 dark:text-zinc-200">{{ $perfil['canonico'] ?: '—' }}</dd>
                                </div>
                                <div class="min-w-0">
                                    <dt class="font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Meta robots / base</dt>
                                    <dd class="break-all font-mono text-zinc-700 dark:text-zinc-200">
                                        {{ $perfil['meta_robots'] ?: '—' }}
                                        @if ($perfil['base_href'])
                                            <span class="font-semibold text-red-600 dark:text-red-400">· base: {{ $perfil['base_href'] }}</span>
                                        @endif
                                    </dd>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                                <div>
                                    <dt class="font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Texto visible</dt>
                                    <dd class="text-zinc-800 dark:text-zinc-100">{{ $perfil['longitud_visible'] }} car.</dd>
                                </div>
                                <div>
                                    <dt class="font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Enlaces ext.</dt>
                                    <dd class="text-zinc-800 dark:text-zinc-100">{{ $perfil['enlaces_externos'] }}</dd>
                                </div>
                                <div>
                                    <dt class="font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Spam</dt>
                                    <dd @class([
                                        'font-semibold',
                                        'text-red-600 dark:text-red-400' => $perfil['spam']['puntuacion'] > 0,
                                        'text-zinc-800 dark:text-zinc-100' => $perfil['spam']['puntuacion'] === 0,
                                    ])>{{ $perfil['spam']['puntuacion'] }} pts</dd>
                                </div>
                            </div>

                            @if ($perfil['hosts_enlazados'] !== [])
                                <div class="min-w-0">
                                    <dt class="font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Dominios enlazados</dt>
                                    <dd class="break-words font-mono text-zinc-700 dark:text-zinc-200">
                                        {{ implode(', ', array_slice($perfil['hosts_enlazados'], 0, 10)) }}
                                    </dd>
                                </div>
                            @endif

                            @if ($perfil['marcas'] !== [])
                                <div class="min-w-0">
                                    <dt class="font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Marcas de apuestas</dt>
                                    <dd class="flex flex-wrap gap-1">
                                        @foreach ($perfil['marcas'] as $marca)
                                            <span class="rounded bg-red-100 px-1.5 py-0.5 font-mono text-[11px] text-red-800 dark:bg-red-900/50 dark:text-red-200">
                                                {{ $marca['marca'] }}
                                            </span>
                                        @endforeach
                                    </dd>
                                </div>
                            @endif
                        </dl>

                        @if ($perfil['muestra_texto'] !== '')
                            <details class="mt-3">
                                <summary class="cursor-pointer text-xs font-semibold text-zinc-600 dark:text-zinc-300">
                                    Muestra del texto servido
                                </summary>
                                <p class="mt-2 break-words rounded bg-zinc-100 p-2 text-xs text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                                    {{ $perfil['muestra_texto'] }}
                                </p>
                            </details>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        {{-- -------------------------------------------------------------- --}}
        {{-- Qué compone la puntuación                                       --}}
        {{-- -------------------------------------------------------------- --}}
        <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="border-b border-zinc-200 p-3 dark:border-zinc-700 sm:p-4">
                <flux:heading size="lg">3 · Qué compone la divergencia</flux:heading>
                <flux:subheading>
                    Cada identidad contra la de navegador, señal por señal. Las señales marcadas como
                    esperables se enseñan igual, pero suman cero: son las que un sitio honesto produce.
                </flux:subheading>
            </div>

            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($comparaciones as $clave => $comparacion)
                    @php
                        $estiloFila = $estiloDe($comparacion['veredicto']);
                    @endphp

                    <div class="p-3 sm:p-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $comparacion['etiqueta'] }}</span>
                            <flux:badge size="sm" :color="$estiloFila['badge']">
                                {{ ComparacionContenido::ETIQUETAS_VEREDICTO[$comparacion['veredicto']] ?? $comparacion['veredicto'] }}
                            </flux:badge>
                            <span class="text-sm font-bold {{ $estiloFila['numero'] }}">
                                {{ $comparacion['puntuacion'] }} pts
                            </span>
                        </div>

                        <p class="mt-1 break-words text-sm text-zinc-600 dark:text-zinc-300">{{ $comparacion['motivo'] }}</p>

                        @if ($comparacion['senales'] !== [])
                            {{-- La tabla es ancha por naturaleza: lleva las dos caras de cada
                                 diferencia. En un teléfono se desplaza en horizontal dentro de su
                                 contenedor, sin arrastrar la página entera. --}}
                            <div class="mt-3 overflow-x-auto">
                                <table class="min-w-full text-left text-xs">
                                    <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                                        <tr>
                                            <th class="whitespace-nowrap px-2 py-2">Pts</th>
                                            <th class="px-2 py-2">Señal</th>
                                            <th class="px-2 py-2">Navegador de escritorio</th>
                                            <th class="px-2 py-2">{{ $comparacion['etiqueta'] }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                        @foreach ($comparacion['senales'] as $senal)
                                            <tr @class([
                                                'align-top',
                                                'bg-red-50 dark:bg-red-950/30' => $senal['concluyente'],
                                                'opacity-80' => $senal['esperable'],
                                            ])>
                                                <td class="whitespace-nowrap px-2 py-2 font-bold">
                                                    @if ($senal['esperable'])
                                                        <span class="text-zinc-500 line-through dark:text-zinc-400">{{ $senal['peso'] }}</span>
                                                        <span class="text-zinc-700 dark:text-zinc-300">0</span>
                                                    @else
                                                        <span class="text-zinc-800 dark:text-zinc-100">{{ $senal['puntos'] }}</span>
                                                    @endif
                                                </td>
                                                <td class="min-w-40 px-2 py-2">
                                                    <p class="font-semibold text-zinc-800 dark:text-zinc-100">{{ $senal['titulo'] }}</p>

                                                    @if ($senal['concluyente'])
                                                        <flux:badge size="sm" color="red">Concluyente</flux:badge>
                                                    @elseif ($senal['esperable'])
                                                        <flux:badge size="sm" color="zinc">Esperable</flux:badge>
                                                    @endif

                                                    @if ($senal['explicacion'] !== '')
                                                        <p class="mt-1 break-words text-[11px] text-zinc-500 dark:text-zinc-400">
                                                            {{ $senal['explicacion'] }}
                                                        </p>
                                                    @endif
                                                </td>
                                                <td class="min-w-40 break-words px-2 py-2 text-zinc-600 dark:text-zinc-300">
                                                    {{ $senal['navegador'] }}
                                                </td>
                                                <td class="min-w-40 break-words px-2 py-2 font-medium text-zinc-900 dark:text-zinc-100">
                                                    {{ $senal['otro'] }}
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="border-t border-zinc-200 p-3 text-xs text-zinc-500 dark:border-zinc-700 dark:text-zinc-400 sm:p-4">
                Una sola señal concluyente basta para el veredicto y no se diluye con la suma: una
                redirección a un dominio de apuestas que solo ocurre con el rastreador es cloaking aunque el
                resto de la página sea idéntica. Al revés, una diferencia de menos del diez por ciento en la
                longitud del texto no se informa siquiera: es el ruido normal de cualquier página con una
                fecha o un producto destacado rotatorio.
            </div>
        </div>

        {{-- -------------------------------------------------------------- --}}
        {{-- Historial                                                       --}}
        {{-- -------------------------------------------------------------- --}}
        @if ($this->historial !== [])
            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="border-b border-zinc-200 p-3 dark:border-zinc-700 sm:p-4">
                    <flux:heading size="lg">4 · Historial de esta dirección</flux:heading>
                    <flux:subheading>
                        El cloaking se limpia y vuelve. Lo que demuestra que volvió es tener las dos
                        mediciones con su fecha.
                    </flux:subheading>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="border-b border-zinc-200 text-xs uppercase text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                            <tr>
                                <th class="whitespace-nowrap px-3 py-2">Fecha</th>
                                <th class="whitespace-nowrap px-3 py-2">Puntos</th>
                                <th class="px-3 py-2">Veredicto</th>
                                <th class="px-3 py-2">Identidades</th>
                                <th class="px-3 py-2">Incidente</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($this->historial as $fila)
                                <tr>
                                    <td class="whitespace-nowrap px-3 py-2 text-zinc-600 dark:text-zinc-300">
                                        {{ $fila->created_at?->format('d/m/Y H:i') }}
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 font-bold text-zinc-900 dark:text-zinc-100">
                                        {{ $fila->puntuacion }}
                                    </td>
                                    <td class="px-3 py-2">
                                        <flux:badge size="sm" :color="$estiloDe($fila->veredicto)['badge']">
                                            {{ $fila->etiquetaVeredicto() }}
                                        </flux:badge>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-zinc-600 dark:text-zinc-300">
                                        {{ $fila->perfiles_alcanzados }}/5
                                    </td>
                                    <td class="px-3 py-2 text-zinc-600 dark:text-zinc-300">
                                        {{ $fila->incidente_seo_id !== null ? '#'.$fila->incidente_seo_id : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @else
        <div class="rounded-xl border border-dashed border-zinc-300 p-6 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
            Pulse <span class="font-semibold">Ver el caso real</span> para la demostración sin red, o escriba
            una dirección propia y pulse <span class="font-semibold">Comparar</span>.
        </div>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Lo que este control NO detecta. Va en la pantalla, no en el informe --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-800/50 sm:p-4">
        <flux:heading size="lg">Lo que este control no ve</flux:heading>
        <flux:subheading>
            Un control que se presenta como infalible es, por sí mismo, un hallazgo de auditoría.
        </flux:subheading>

        <ul class="mt-3 grid gap-2 text-sm text-zinc-600 dark:text-zinc-300 sm:grid-cols-2">
            <li class="min-w-0 rounded border border-zinc-200 bg-white p-2 dark:border-zinc-700 dark:bg-zinc-900">
                <span class="font-semibold text-zinc-800 dark:text-zinc-100">Cloaking por dirección IP.</span>
                Esta herramienta solo cambia cómo se presenta: sale de la dirección del servidor de MarketGT,
                no de un rango de Google. Un atacante que compruebe el DNS inverso —la misma técnica que usa
                el verificador de rastreadores de este proyecto— le servirá la versión limpia y aquí no se
                verá nada. Es la limitación más seria y no tiene arreglo desde dentro: contra eso, la
                herramienta de inspección de URL de Search Console sí sale desde Google.
            </li>
            <li class="min-w-0 rounded border border-zinc-200 bg-white p-2 dark:border-zinc-700 dark:bg-zinc-900">
                <span class="font-semibold text-zinc-800 dark:text-zinc-100">Contenido inyectado por JavaScript.</span>
                Se compara el HTML tal como llega. Una inyección que se monta en el navegador después de
                cargar la página no aparece en ninguna de las cinco versiones.
            </li>
            <li class="min-w-0 rounded border border-zinc-200 bg-white p-2 dark:border-zinc-700 dark:bg-zinc-900">
                <span class="font-semibold text-zinc-800 dark:text-zinc-100">Cloaking intermitente.</span>
                Hay campañas que sirven la inyección una de cada diez peticiones, o solo de madrugada. Una
                comparación es una foto; por eso cada ejecución se guarda con su fecha y por eso conviene
                repetirla.
            </li>
            <li class="min-w-0 rounded border border-zinc-200 bg-white p-2 dark:border-zinc-700 dark:bg-zinc-900">
                <span class="font-semibold text-zinc-800 dark:text-zinc-100">Páginas que nunca se visitan.</span>
                Se compara la dirección que se escriba. La inyección suele vivir en direcciones que nadie
                enlaza y que solo aparecen en el informe de Search Console o en el mapa del sitio: de ahí hay
                que sacar la lista de direcciones que merece la pena comparar.
            </li>
            <li class="min-w-0 rounded border border-zinc-200 bg-white p-2 dark:border-zinc-700 dark:bg-zinc-900">
                <span class="font-semibold text-zinc-800 dark:text-zinc-100">Marcas que aún no se conocen.</span>
                La lista de marcas de apuestas reconoce lo que ya se vio, incluidas las cinco del caso real.
                Mañana la campaña usará otros nombres. Por eso la lista no es la única señal y nunca decide
                sola el veredicto.
            </li>
            <li class="min-w-0 rounded border border-zinc-200 bg-white p-2 dark:border-zinc-700 dark:bg-zinc-900">
                <span class="font-semibold text-zinc-800 dark:text-zinc-100">Sitios detrás de un muro.</span>
                No se envía sesión ni cookies. Una página que exige inicio de sesión devolverá lo mismo a las
                cinco identidades: no hay cloaking que detectar porque no hay contenido que comparar.
            </li>
        </ul>
    </div>
</div>
