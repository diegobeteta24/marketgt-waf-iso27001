@php
    use App\Models\PruebaRestauracion;
    use App\Services\Siem\CalculadoraMetricas;

    $medicion = $this->medicion;
    $metricas = $this->metricas;
    $resumen = $this->resumen;
    $pruebas = $this->pruebas;
    $ocupacion = $this->ocupacionRto;

    $colorEstado = [
        CalculadoraMetricas::CUMPLE => 'var(--siem-cumple, #34d399)',
        CalculadoraMetricas::INCUMPLE => 'var(--siem-critica, #f87171)',
        CalculadoraMetricas::SIN_DATOS => 'var(--siem-informativa, #a3a3a3)',
    ];
@endphp

{{-- Una prueba de restauracion dura minutos: refrescar cada minuto basta para ver aparecer
     el acta sin recargar mientras se ejecuta el simulacro delante del tribunal. --}}
<div wire:poll.60s class="space-y-6">

    {{-- El mismo aviso que vigila la ingesta de eventos, aplicado a la evidencia: si la
         ultima prueba satisfactoria caduco, la cifra de arriba describe otra plataforma. --}}
    @if ($this->pruebaVencida)
        <div class="flex items-start gap-3 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 sm:p-4">
            <flux:icon.exclamation-triangle class="mt-0.5 size-5 shrink-0 text-amber-400" />
            <div class="text-sm">
                <p class="font-semibold text-amber-300">
                    @if ($medicion['prueba'] === null)
                        No hay ninguna prueba de restauracion satisfactoria
                    @else
                        La ultima prueba satisfactoria tiene {{ $medicion['dias_desde_prueba'] }} dias
                    @endif
                </p>
                <p class="mt-1 text-zinc-300">
                    El anexo exige una prueba cada {{ $this->periodicidad }} dias con acta de resultado.
                    Mientras no exista una prueba vigente, el tiempo y el punto de recuperacion se declaran
                    <span class="font-semibold">sin datos</span>: se miden restaurando, no leyendo la
                    configuracion del respaldo.
                </p>
                <p class="mt-2 break-words text-zinc-400">
                    Ejecutela con
                    <code class="rounded bg-zinc-800 px-1">bash infra/scripts/probar-restauracion.sh --json | php artisan siem:probar-restauracion --registrar=-</code>
                </p>
            </div>
        </div>
    @endif

    {{-- Las dos metricas del vertice de respuesta, con el mismo lenguaje visual que el
         triangulo: cifra grande, meta, procedencia y, si la hay, advertencia. --}}
    <div class="grid gap-4 sm:grid-cols-2">
        @foreach ($metricas as $metrica)
            <div class="flex flex-col rounded-xl border border-zinc-200 bg-white p-3 sm:p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                    <p class="min-w-0 text-sm font-medium text-white">{{ $metrica['nombre'] }}</p>

                    {{-- Icono mas texto: el estado nunca se comunica solo con color. --}}
                    @if ($metrica['estado'] === CalculadoraMetricas::CUMPLE)
                        <span class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold" style="color: var(--siem-cumple, #34d399)">
                            <flux:icon.check-circle class="size-4" /> Cumple
                        </span>
                    @elseif ($metrica['estado'] === CalculadoraMetricas::INCUMPLE)
                        <span class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold" style="color: var(--siem-critica, #f87171)">
                            <flux:icon.x-circle class="size-4" /> No cumple
                        </span>
                    @else
                        <span class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-zinc-400">
                            <flux:icon.minus-circle class="size-4" /> Sin datos
                        </span>
                    @endif
                </div>

                <p @class([
                    'siem-numero mt-2 break-words',
                    'text-2xl font-semibold text-white' => $metrica['estado'] !== CalculadoraMetricas::SIN_DATOS,
                    'text-base font-normal italic text-zinc-400' => $metrica['estado'] === CalculadoraMetricas::SIN_DATOS,
                ])>{{ $metrica['valor_texto'] }}</p>

                <p class="mt-2 text-xs text-zinc-400">
                    <span class="font-medium">Meta:</span> {{ $metrica['meta'] }}
                    @if ($metrica['muestra'] > 0)
                        · muestra de {{ number_format($metrica['muestra']) }}
                    @endif
                </p>

                <p class="mt-2 break-words text-sm leading-relaxed text-zinc-400 sm:text-xs">{{ $metrica['origen'] }}</p>

                @if ($metrica['advertencia'])
                    <p class="mt-2 text-sm font-medium text-amber-400 sm:text-xs">{{ $metrica['advertencia'] }}</p>
                @endif

                {{-- Barra solo para el RTO: es la unica de las dos que tiene sentido dibujar
                     contra su meta, porque la meta es un techo de tiempo. --}}
                @if ($metrica['clave'] === 'objetivo_tiempo_recuperacion' && $ocupacion !== null)
                    @php
                        // El porcentaje medido y el ancho dibujado son dos cosas distintas y no
                        // deben confundirse: el ancho lleva un minimo para que la barra se vea,
                        // y ese minimo no es un dato. El rotulo accesible anuncia el porcentaje
                        // MEDIDO, el mismo que lee quien ve la pantalla.
                        $porcentaje = $ocupacion * 100;

                        // Los decimales se eligen para que la cifra medida nunca se redondee
                        // hasta desaparecer: una recuperacion de tres segundos consume el
                        // 0,022 % de una meta de cuatro horas, y publicarla como "0,0 %"
                        // equivaldria a decir que no se midio.
                        $porcentajeTexto = number_format($porcentaje, match (true) {
                            $porcentaje >= 1 => 1,
                            $porcentaje >= 0.01 => 3,
                            default => 5,
                        });
                        // El lienzo mide 200 unidades: el ancho se acota a los dos extremos
                        // para que la barra se vea siempre y no se salga cuando la meta se
                        // rebasa. Acotar el DIBUJO es legitimo; acotar la cifra no lo seria.
                        $ancho = max(3, min(200, round($porcentaje * 2, 2)));
                        $colorBarra = $metrica['estado'] === CalculadoraMetricas::CUMPLE
                            ? 'var(--siem-cumple, #34d399)'
                            : 'var(--siem-critica, #f87171)';
                    @endphp

                    {{-- El color va en style y no en fill: una regla de hoja de estilos sobre
                         los elementos del panel pisaria el atributo de presentacion y la barra
                         saldria negra sobre fondo negro. --}}
                    <svg viewBox="0 0 200 12" class="mt-3 h-3 w-full" role="img"
                         aria-label="La recuperacion medida consume el {{ $porcentajeTexto }} por ciento del margen de la meta">
                        <rect x="0" y="0" width="200" height="12" rx="6"
                              style="fill: rgba(255,255,255,0.10)" />
                        {{-- Un minimo de tres unidades de ancho sobre un lienzo de 200: cuando la
                             recuperacion tarda milesimas de la meta, una barra proporcional seria
                             invisible y pareceria que no se midio nada. --}}
                        <rect x="0" y="0" width="{{ $ancho }}" height="12" rx="6"
                              style="fill: {{ $colorBarra }}" />
                    </svg>
                    <p class="mt-1 text-xs text-zinc-400">
                        Consume el {{ $porcentajeTexto }} % del margen de la meta.
                    </p>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Historial. Tarjetas en vez de tabla: en un telefono una tabla de once columnas se
         convierte en un desplazamiento horizontal que nadie recorre. --}}
    <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 p-3 sm:p-4 dark:border-zinc-700">
            <flux:heading size="lg">Historial de pruebas de restauracion</flux:heading>
            <flux:subheading>
                Cada linea es un simulacro que se ejecuto: volcado, cifrado en AES-256, restauracion
                sobre una base desechable y verificacion por conteo de filas.
            </flux:subheading>

            <p class="mt-3 text-sm text-zinc-300">
                <span class="siem-numero font-semibold text-zinc-100">{{ number_format($resumen['total']) }}</span>
                pruebas registradas;
                <span class="siem-numero font-semibold text-zinc-100">{{ number_format($resumen['satisfactorias']) }}</span>
                satisfactorias y
                <span class="siem-numero font-semibold text-zinc-100">{{ number_format($resumen['fallidas']) }}</span>
                fallidas. Las fallidas se guardan a proposito: un intento que no funciono tambien es evidencia.
            </p>
        </div>

        @if ($pruebas->isEmpty())
            <p class="p-4 text-sm text-zinc-400">
                Todavia no se ha registrado ninguna prueba. Ejecute
                <code class="rounded bg-zinc-800 px-1">php artisan siem:probar-restauracion</code>
                en el anfitrion o registre el acta del guion con <code class="rounded bg-zinc-800 px-1">--registrar=-</code>.
            </p>
        @else
            <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($pruebas as $prueba)
                    @php
                        $satisfactoria = $prueba->fueSatisfactoria();
                        $color = $satisfactoria
                            ? 'var(--siem-cumple, #34d399)'
                            : 'var(--siem-critica, #f87171)';
                    @endphp

                    <div class="space-y-3 p-3 sm:p-4">
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span class="siem-muestra" style="background: {{ $color }}" aria-hidden="true"></span>

                            <p class="siem-numero text-sm font-semibold text-white">
                                {{ $prueba->iniciada_en->format('d/m/Y H:i') }}
                            </p>

                            <span class="inline-flex items-center gap-1 text-xs font-semibold" style="color: {{ $color }}">
                                @if ($satisfactoria)
                                    <flux:icon.check-circle class="size-4" /> Satisfactoria
                                @else
                                    <flux:icon.x-circle class="size-4" /> Fallida{{ $prueba->fase_fallida ? ' en '.$prueba->fase_fallida : '' }}
                                @endif
                            </span>

                            <span class="rounded border border-zinc-700 px-2 py-0.5 text-xs text-zinc-300">
                                {{ $prueba->etiquetaOrigen() }}
                            </span>
                        </div>

                        {{-- Dos columnas ya en el telefono: cuatro cifras apiladas obligarian a
                             desplazarse antes de haber visto la duracion, que es el dato. --}}
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            <div class="rounded-lg border border-zinc-200 p-2 dark:border-zinc-700">
                                <p class="text-xs uppercase tracking-wide text-zinc-400">Recuperacion</p>
                                <p class="siem-numero mt-1 text-base font-semibold text-zinc-100">
                                    {{ PruebaRestauracion::duracionLegible($prueba->segundos_recuperacion) }}
                                </p>
                            </div>
                            <div class="rounded-lg border border-zinc-200 p-2 dark:border-zinc-700">
                                <p class="text-xs uppercase tracking-wide text-zinc-400">Volcado</p>
                                <p class="siem-numero mt-1 text-base font-semibold text-zinc-100">
                                    {{ PruebaRestauracion::tamanoLegible($prueba->bytes_volcado) }}
                                </p>
                            </div>
                            <div class="rounded-lg border border-zinc-200 p-2 dark:border-zinc-700">
                                <p class="text-xs uppercase tracking-wide text-zinc-400">Filas</p>
                                <p class="siem-numero mt-1 text-base font-semibold text-zinc-100">
                                    {{ number_format($prueba->filas_comparadas) }}
                                </p>
                            </div>
                            <div class="rounded-lg border border-zinc-200 p-2 dark:border-zinc-700">
                                <p class="text-xs uppercase tracking-wide text-zinc-400">Cifrado</p>
                                <p class="siem-numero mt-1 text-base font-semibold"
                                   style="color: {{ $prueba->cifrado_verificado ? 'var(--siem-cumple, #34d399)' : 'var(--siem-critica, #f87171)' }}">
                                    {{ $prueba->algoritmo_cifrado ?? 'sin datos' }}
                                </p>
                            </div>
                        </div>

                        <p class="break-words text-sm leading-relaxed text-zinc-400 sm:text-xs">
                            Ejecutada por <span class="text-zinc-300">{{ $prueba->responsable() }}</span>
                            sobre la base de prueba <span class="text-zinc-300">{{ $prueba->base_prueba }}</span>
                            @if ($prueba->modo_cliente)
                                mediante <span class="text-zinc-300">{{ $prueba->modo_cliente }}</span>
                            @endif
                            · descifrado {{ PruebaRestauracion::duracionLegible($prueba->segundos_descifrado) }},
                            restauracion {{ PruebaRestauracion::duracionLegible($prueba->segundos_restauracion) }},
                            verificacion {{ PruebaRestauracion::duracionLegible($prueba->segundos_verificacion) }}.
                        </p>

                        @if ($prueba->error)
                            <p class="break-words text-sm font-medium text-amber-400 sm:text-xs">{{ $prueba->error }}</p>
                        @endif

                        <button
                            type="button"
                            wire:click="alternarDetalle({{ $prueba->id }})"
                            class="inline-flex min-h-11 items-center gap-1 text-sm font-medium text-zinc-300 underline underline-offset-4 hover:text-white sm:min-h-0"
                        >
                            {{ $this->detalle === $prueba->id ? 'Ocultar el acta' : 'Ver el acta completa' }}
                        </button>

                        @if ($this->detalle === $prueba->id)
                            <div class="space-y-3 rounded-lg border border-zinc-700 bg-zinc-950/40 p-3">
                                <dl class="grid gap-x-4 gap-y-2 text-xs sm:grid-cols-2">
                                    <div>
                                        <dt class="text-zinc-400">Anfitrion</dt>
                                        <dd class="break-words text-zinc-200">{{ $prueba->anfitrion ?? 'sin datos' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-zinc-400">Base de origen</dt>
                                        <dd class="break-words text-zinc-200">{{ $prueba->base_origen }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-zinc-400">Huella SHA-256 del volcado cifrado</dt>
                                        <dd class="siem-numero break-all text-zinc-200">{{ $prueba->huella_volcado ?? 'sin datos' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-zinc-400">Respaldo mas reciente al iniciar</dt>
                                        <dd class="break-words text-zinc-200">
                                            @if ($prueba->respaldo_mas_reciente_en)
                                                {{ $prueba->respaldo_mas_reciente_en->format('d/m/Y H:i') }}
                                                ({{ PruebaRestauracion::antiguedadLegible($prueba->antiguedad_respaldo_minutos) }} de antiguedad)
                                            @else
                                                sin datos: no se encontro ningun respaldo en
                                                {{ $prueba->ruta_respaldos ?? 'el directorio configurado' }}
                                            @endif
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-zinc-400">Tablas comparadas</dt>
                                        <dd class="text-zinc-200">{{ number_format($prueba->tablas_comparadas) }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-zinc-400">Orden ejecutada</dt>
                                        <dd class="break-words text-zinc-200">{{ $prueba->comando ?? 'sin datos' }}</dd>
                                    </div>
                                </dl>

                                @if ($prueba->discrepancias)
                                    <div>
                                        <p class="text-xs font-semibold text-amber-400">Tablas cuyo conteo no cuadro con el origen</p>
                                        {{-- La unica parte que puede desbordar a lo ancho: se le da su propio
                                             contenedor con desplazamiento para no romper el resto de la pagina. --}}
                                        <div class="mt-1 overflow-x-auto">
                                            <pre class="siem-numero text-xs leading-relaxed text-zinc-300">{{ json_encode($prueba->discrepancias, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                        </div>
                                    </div>
                                @endif

                                @if ($prueba->salida)
                                    <div>
                                        <p class="text-xs font-semibold text-zinc-300">Bitacora de la ejecucion</p>
                                        <div class="mt-1 max-h-72 overflow-auto rounded bg-zinc-950 p-2">
                                            <pre class="text-xs leading-relaxed text-zinc-300">{{ $prueba->salida }}</pre>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
