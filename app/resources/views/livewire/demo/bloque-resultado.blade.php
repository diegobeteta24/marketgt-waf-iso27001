{{--
    Bloque de un disparo: la petición enviada, la respuesta del borde y el asiento del
    registro de auditoría. Se incluye tanto en el detalle de un ataque individual como en
    cada mitad del modo comparativo.

    Recibe:  $resultado  (array devuelto por LanzadorAtaques::lanzar)
             $etiquetaModo (texto que rotula la columna en el modo comparativo)
--}}
@php
    $peticion = $resultado['peticion'] ?? [];
    $respuesta = $resultado['respuesta'] ?? [];
    $evento = $resultado['evento'] ?? [];
    $codigo = $respuesta['codigo'] ?? null;

    // El color del código es un refuerzo del número, nunca su único canal.
    $claseHttp = match (true) {
        $codigo === 403 => 'demo-http-bloqueado',
        $codigo === null => 'demo-http-error',
        default => 'demo-http-paso',
    };
@endphp

<div class="space-y-4">
    @isset($etiquetaModo)
        <div class="flex items-center gap-2">
            <flux:badge size="sm">{{ $etiquetaModo }}</flux:badge>
            @if (($peticion['modo'] ?? null) === 'deteccion')
                <span class="text-xs text-zinc-500 dark:text-zinc-400">motor en solo detección (cabecera secreta)</span>
            @else
                <span class="text-xs text-zinc-500 dark:text-zinc-400">motor plenamente activo</span>
            @endif
        </div>
    @endisset

    {{-- Columna 1: la petición que se envió --}}
    <div>
        <p class="mb-1 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Petición enviada</p>
        <div class="demo-codigo">
<span class="font-semibold">{{ $peticion['metodo'] ?? '?' }}</span> {{ $peticion['ruta'] ?? '' }}
@if (! empty($peticion['consulta']))@foreach ($peticion['consulta'] as $clave => $valor)
  ?{{ $clave }} = {{ $valor }}
@endforeach
@endif
@if (! empty($peticion['cuerpo']))
cuerpo:
@foreach ($peticion['cuerpo'] as $clave => $valor)  {{ $clave }} = {{ $valor }}
@endforeach
@endif
@if (! empty($peticion['cabeceras']))
cabeceras:
@foreach ($peticion['cabeceras'] as $clave => $valor)  {{ $clave }}: {{ $clave === 'X-MarketGT-Console' ? '••••••• (secreto de consola)' : $valor }}
@endforeach
@endif
        </div>
    </div>

    {{-- Columna 2: la respuesta del borde --}}
    <div>
        <p class="mb-1 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Respuesta</p>
        @if (($respuesta['ok'] ?? false) === false)
            <p class="text-sm" style="color: var(--demo-peligro)">
                {{ $respuesta['error'] ?? 'No se pudo enviar la petición.' }}
            </p>
        @else
            <div class="flex flex-wrap items-center gap-3">
                <span class="demo-http {{ $claseHttp }}">
                    @if ($codigo === 403)
                        <flux:icon.shield-check class="size-4" />
                    @endif
                    HTTP {{ $codigo }}
                </span>
                <span class="text-sm text-zinc-600 dark:text-zinc-300">
                    @if ($codigo === 403)
                        Bloqueado en el borde: el WAF cortó la petición.
                    @elseif ($codigo === 405)
                        Método rechazado por el enrutador de la aplicación.
                    @else
                        La petición atravesó el borde y llegó a la aplicación.
                    @endif
                </span>
            </div>

            @if (! empty($respuesta['cuerpo']))
                <details class="mt-2">
                    <summary class="cursor-pointer text-xs text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">
                        Ver cuerpo de la respuesta
                    </summary>
                    <div class="demo-codigo mt-2">{{ $respuesta['cuerpo'] }}@if ($respuesta['cuerpo_recortado'] ?? false)

… (recortado)@endif</div>
                </details>
            @endif
        @endif
    </div>

    {{-- Columna 3: el evento correlacionado del registro de auditoría del WAF --}}
    <div>
        <p class="mb-1 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
            Evento del registro de auditoría del WAF
        </p>

        @if (($evento['disponible'] ?? false) === false)
            <div class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-xs text-amber-700 dark:text-amber-300">
                No se pudo leer el registro de auditoría en
                <code>{{ $evento['ruta'] ?? 'ruta no configurada' }}</code>.
                Verifique que el volumen del WAF está montado en el contenedor de la aplicación.
            </div>
        @elseif (($evento['encontrado'] ?? false) === false)
            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                El asiento aún no aparece en el registro. Puede tardar un instante en escribirse;
                use «Reintentar lectura» o vuelva a lanzar.
            </p>
        @else
            <dl class="space-y-1.5 text-sm">
                <div class="flex flex-wrap gap-x-2">
                    <dt class="text-zinc-500 dark:text-zinc-400">Transacción</dt>
                    <dd class="demo-numero font-medium text-zinc-900 dark:text-white">{{ $evento['id_transaccion'] ?? 'sin dato' }}</dd>
                </div>
                <div class="flex flex-wrap items-center gap-x-2">
                    <dt class="text-zinc-500 dark:text-zinc-400">Interrumpido</dt>
                    <dd class="font-medium">
                        @if ($evento['interrumpido'])
                            <span style="color: var(--demo-bloqueado)">sí — el motor cortó la transacción</span>
                        @else
                            <span style="color: var(--demo-detectado)">no — registrado sin bloqueo (solo detección o por debajo del umbral)</span>
                        @endif
                    </dd>
                </div>
                <div class="flex flex-wrap items-center gap-x-2">
                    <dt class="text-zinc-500 dark:text-zinc-400">Puntuación de anomalía</dt>
                    <dd class="demo-numero font-medium text-zinc-900 dark:text-white">
                        @if (! is_null($evento['puntuacion']))
                            {{ $evento['puntuacion'] }} / umbral 5
                        @else
                            sin dato en el asiento
                        @endif
                        @if (! is_null($evento['puntuacion_seo'] ?? null))
                            <span class="text-zinc-500 dark:text-zinc-400">(SEO {{ $evento['puntuacion_seo'] }})</span>
                        @endif
                    </dd>
                </div>
            </dl>

            @if (! empty($evento['reglas']))
                <p class="mt-3 mb-1.5 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                    Reglas activadas
                </p>
                <ul class="space-y-2">
                    @foreach ($evento['reglas'] as $regla)
                        @php $idRegla = (int) ($regla['id'] ?? 0); $propia = $idRegla >= 15000 && $idRegla < 16000; @endphp
                        <li class="text-sm">
                            <span class="demo-regla {{ $propia ? 'demo-regla-propia' : '' }}">
                                {{ $regla['id'] ?: '—' }}@if ($propia) · propia @endif
                            </span>
                            <span class="ms-1 text-zinc-700 dark:text-zinc-300">{{ $regla['mensaje'] }}</span>
                            @if (! empty($regla['dato']))
                                <span class="block text-xs text-zinc-500 dark:text-zinc-400">casa con: <code>{{ \Illuminate\Support\Str::limit($regla['dato'], 120) }}</code></span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        @endif
    </div>
</div>
