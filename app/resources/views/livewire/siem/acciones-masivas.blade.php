@php
    use App\Models\AlertaSeguridad;
    use App\Services\Siem\TriajeAsistido;

    $conteos = $this->conteos;
    $porRegla = $this->porRegla;
    $criterios = $this->criterios;
    $destinos = $this->destinosPosibles;

    $etiquetasEstado = AlertaSeguridad::ETIQUETAS_ESTADO;

    // La ventana la fija la calculadora de metricas. Se nombra en pantalla porque una
    // cobertura sin periodo no se puede comprobar: quien audite tiene que poder rehacer la
    // misma consulta y llegar al mismo numero.
    $dias = $this->diasObservacion();

    // El denominador de la barra es el total de alertas, no solo las revisadas: la franja de
    // "sin revisar" tiene que ocupar su sitio o la imagen mentiria por omision.
    $total = max((int) $conteos['total'], 1);

    $tramos = [
        ['clave' => 'humana', 'titulo' => 'A mano', 'valor' => (int) $conteos['humana'], 'color' => 'var(--siem-cumple, #34d399)'],
        ['clave' => 'automatica', 'titulo' => 'Por lote', 'valor' => (int) $conteos['automatica'], 'color' => 'var(--siem-baja, #38bdf8)'],
        ['clave' => 'sin_registro', 'titulo' => 'Sin procedencia', 'valor' => (int) $conteos['sin_registro'], 'color' => 'var(--siem-media, #fbbf24)'],
        ['clave' => 'sin_triar', 'titulo' => 'Sin revisar', 'valor' => (int) $conteos['sin_triar'], 'color' => 'var(--siem-informativa, #a3a3a3)'],
    ];
@endphp

<div class="space-y-6">

    {{-- 1. La cifra partida en dos. Es el motivo de toda esta pantalla: una cobertura del
         cien por cien alcanzada por lote y otra alcanzada revisando no valen lo mismo, y un
         panel que las suma sin distinguirlas esconde justo lo que interesa. --}}
    <div class="rounded-xl border border-zinc-200 bg-white p-3 sm:p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
            <div class="min-w-0">
                <p class="text-sm font-medium text-white">Cobertura de triaje por procedencia</p>
                <p class="mt-0.5 text-xs text-zinc-400">
                    Quien reviso cada alerta: una persona o el lote automatico sobre datos de laboratorio.
                    Ventana de observacion: ultimos {{ $dias }} dias, la misma del triangulo.
                </p>
            </div>

            @if ($conteos['total'] > 0)
                <p class="siem-numero shrink-0 text-2xl font-semibold text-white">
                    {{ number_format($conteos['triadas'] * 100 / $conteos['total'], 1) }} %
                </p>
            @else
                <p class="shrink-0 text-base italic text-zinc-400">sin datos</p>
            @endif
        </div>

        @if (! $conteos['procedencia_registrable'])
            <div class="mt-3 flex items-start gap-3 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3">
                <flux:icon.exclamation-triangle class="mt-0.5 size-5 shrink-0 text-amber-400" />
                <p class="text-sm text-zinc-300">
                    <span class="font-semibold text-amber-300">La procedencia no se esta registrando.</span>
                    Falta aplicar la migracion <code class="break-all rounded bg-zinc-800 px-1">2026_09_24_000605_agregar_procedencia_triaje_a_alertas</code>.
                    Hasta entonces se sabe cuantas alertas se revisaron, pero no quien las reviso, y esa mitad
                    de la metrica se declara <span class="font-semibold">sin datos</span> en lugar de suponerla.
                </p>
            </div>
        @endif

        @if ($conteos['total'] > 0)
            {{-- Barra apilada con div, no con SVG: son cuatro rectangulos y una hoja de estilos
                 no puede dejarlos negros sobre negro. El color va en el atributo style. --}}
            <div class="mt-3 flex h-3 w-full overflow-hidden rounded-full bg-zinc-800" role="presentation">
                @foreach ($tramos as $tramo)
                    @if ($tramo['valor'] > 0)
                        <div
                            style="width: {{ $tramo['valor'] * 100 / $total }}%; background-color: {{ $tramo['color'] }};"
                            title="{{ $tramo['titulo'] }}: {{ $tramo['valor'] }}"
                        ></div>
                    @endif
                @endforeach
            </div>

            {{-- La leyenda repite la cifra en texto: el color nunca comunica solo. --}}
            <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach ($tramos as $tramo)
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs text-zinc-400">
                            <span class="size-2 shrink-0 rounded-full" style="background-color: {{ $tramo['color'] }};"></span>
                            {{ $tramo['titulo'] }}
                        </dt>
                        <dd class="siem-numero mt-0.5 text-lg font-semibold text-white">{{ number_format($tramo['valor']) }}</dd>
                    </div>
                @endforeach
            </dl>

            <p class="mt-3 break-words text-sm leading-relaxed text-zinc-400 sm:text-xs">
                Sobre trafico real:
                <span class="siem-numero font-semibold text-zinc-200">{{ number_format($conteos['reales_triadas']) }}</span>
                de {{ number_format($conteos['reales_total']) }} revisadas.
                Esta es la cifra que el lote automatico no puede mover, porque nunca toca una alerta real.
            </p>

            @if ($conteos['sin_registro'] > 0)
                <p class="mt-2 text-sm font-medium text-amber-400 sm:text-xs">
                    {{ number_format($conteos['sin_registro']) }} alertas se revisaron antes de que existiera el registro de
                    procedencia. No se reparten entre las otras dos cifras: no consta quien las decidio.
                </p>
            @endif
        @else
            <p class="mt-3 text-sm text-zinc-400">
                No hay ninguna alerta detectada en los ultimos {{ $dias }} dias. La cobertura se declara
                sin datos: con denominador cero no hay porcentaje que calcular, y un cien por cien aqui
                significaria que nadie tuvo nada que revisar.
            </p>
        @endif
    </div>

    {{-- 2. El criterio, escrito donde lo ve quien audita y no solo en el codigo. --}}
    <div class="rounded-xl border border-zinc-200 bg-white p-3 sm:p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <p class="text-sm font-medium text-white">Criterio del triaje automatico</p>
        <p class="mt-0.5 text-xs text-zinc-400">
            Se aplica <span class="font-semibold text-zinc-300">solo</span> a alertas cuyos eventos son todos de
            demostracion, con el comando
            <code class="break-all rounded bg-zinc-800 px-1">php artisan siem:triar-sinteticas</code>.
            Las reglas se evaluan en este orden.
        </p>

        <ol class="mt-3 space-y-3">
            @foreach ($criterios as $indice => $criterio)
                <li class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700 dark:bg-zinc-800/40">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="siem-numero rounded-full bg-zinc-800 px-2 py-0.5 text-[0.7rem] font-semibold text-zinc-200">
                            {{ $indice + 1 }}
                        </span>
                        <p class="min-w-0 break-words text-sm font-semibold text-white">{{ $criterio['nombre'] }}</p>

                        @if ($criterio['clave'] === TriajeAsistido::SIN_ENCAJE)
                            <flux:badge size="sm" color="amber">se deja para revision humana</flux:badge>
                        @else
                            <flux:badge size="sm">{{ $etiquetasEstado[$criterio['resultado']] }}</flux:badge>
                        @endif

                        @if (($porRegla[$criterio['clave']] ?? 0) > 0)
                            <span class="siem-numero text-xs text-zinc-400">
                                · {{ number_format($porRegla[$criterio['clave']]) }} alertas decididas asi
                            </span>
                        @endif
                    </div>

                    <p class="mt-1.5 break-words text-sm text-zinc-300">{{ $criterio['condicion'] }}</p>
                    <p class="mt-1 break-words text-sm leading-relaxed text-zinc-400 sm:text-xs">{{ $criterio['porque'] }}</p>
                </li>
            @endforeach
        </ol>
    </div>

    {{-- 3. La respuesta a "¿de donde sale este numero?", fila por fila. Sin esta lista la
         evidencia se escribe en la base y no la ve nadie, que para una auditoria es lo mismo
         que no haberla escrito. --}}
    @if ($this->ultimasDecisiones !== [])
        <div class="rounded-xl border border-zinc-200 bg-white p-3 sm:p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <p class="text-sm font-medium text-white">Ultimas decisiones y su procedencia</p>
            <p class="mt-0.5 text-xs text-zinc-400">
                Quien decidio, con que criterio y sobre que hechos. Es lo que un auditor pide cuando ve un
                porcentaje: la fila que lo sostiene.
            </p>

            <ul class="mt-3 space-y-3">
                @foreach ($this->ultimasDecisiones as $decision)
                    @php($alerta = $decision['alerta'])
                    @php($procedencia = $decision['procedencia'])

                    <li class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700 dark:bg-zinc-800/40">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="siem-numero break-all text-xs text-zinc-400">#{{ $alerta->id }}</span>
                            <flux:badge size="sm" :color="$procedencia['procedencia'] === 'humana' ? 'emerald' : 'sky'">
                                {{ $procedencia['etiqueta'] }}
                            </flux:badge>
                            @if ($procedencia['regla'])
                                <span class="siem-numero break-all text-xs text-zinc-300">{{ $procedencia['regla'] }}</span>
                            @endif
                            @if ($procedencia['momento'])
                                <span class="siem-numero text-xs text-zinc-400">
                                    {{ $procedencia['momento']->format('d/m/Y H:i') }}
                                </span>
                            @endif
                        </div>

                        <p class="mt-1.5 break-words text-sm text-zinc-300">{{ $alerta->titulo }}</p>

                        @if ($procedencia['actor'])
                            <p class="mt-1 break-words text-sm text-zinc-400 sm:text-xs">
                                Decidio: <span class="text-zinc-300">{{ $procedencia['actor'] }}</span>
                            </p>
                        @endif

                        @if ($procedencia['criterio'])
                            <p class="mt-1 break-words text-sm leading-relaxed text-zinc-400 sm:text-xs">
                                {{ $procedencia['criterio'] }}
                            </p>
                        @endif

                        @if ($procedencia['evidencia'] !== [])
                            {{-- La evidencia en crudo, sin resumir: resumirla seria volver a
                                 pedir que alguien se fie de lo que dice el panel. --}}
                            <dl class="mt-2 grid grid-cols-1 gap-x-4 gap-y-1 sm:grid-cols-2">
                                @foreach ($procedencia['evidencia'] as $clave => $valor)
                                    <div class="flex flex-wrap gap-x-2">
                                        <dt class="text-xs text-zinc-400">{{ str_replace('_', ' ', $clave) }}:</dt>
                                        <dd class="siem-numero min-w-0 break-words text-xs text-zinc-300">
                                            {{ is_array($valor) ? implode(', ', array_map('strval', $valor)) : var_export($valor, true) }}
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- 4. El trabajo humano: seleccionar varias alertas reales y cambiarlas de estado. --}}
    <div class="space-y-4 rounded-xl border border-zinc-200 bg-white p-3 sm:p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="min-w-0">
                <flux:heading size="lg">Acciones masivas</flux:heading>
                <flux:subheading>
                    Para las alertas de trafico real. Cada cambio queda firmado a su nombre y con su nota.
                </flux:subheading>
            </div>

            <div class="grid w-full grid-cols-1 gap-3 sm:flex sm:w-auto sm:flex-wrap sm:items-center">
                <flux:select wire:model.live="filtroEstado" size="sm" class="min-h-11 text-base sm:min-h-0 sm:text-sm">
                    <flux:select.option value="{{ AlertaSeguridad::ESTADO_NUEVA }}">Sin revisar</flux:select.option>
                    <flux:select.option value="{{ AlertaSeguridad::ESTADO_EN_TRIAJE }}">En triaje</flux:select.option>
                    <flux:select.option value="abiertas">Todas las abiertas</flux:select.option>
                </flux:select>

                <flux:checkbox
                    wire:model.live="incluirDemostracion"
                    label="Incluir las de demostracion"
                    class="min-h-11 sm:min-h-0"
                />
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2 text-sm text-zinc-400">
            <p class="siem-numero">
                {{ number_format($this->totalPendientes) }} alertas pendientes con este filtro ·
                {{ number_format($this->cantidadMarcada()) }} marcadas
                @if ($this->totalPendientes > $this->limite)
                    · se muestran las {{ $this->limite }} mas graves
                @endif
            </p>

            <div class="flex flex-wrap gap-2">
                <flux:button size="sm" variant="ghost" wire:click="seleccionarTodas" class="min-h-11 sm:min-h-0">
                    Marcar las visibles
                </flux:button>
                @if ($this->totalPendientes > $this->limite)
                    <flux:button size="sm" variant="ghost" wire:click="marcarTodasLasPendientes" class="min-h-11 sm:min-h-0">
                        Marcar las {{ number_format($this->totalPendientes) }} pendientes
                    </flux:button>
                @endif
                <flux:button size="sm" variant="ghost" wire:click="limpiarSeleccion" class="min-h-11 sm:min-h-0">
                    Desmarcar
                </flux:button>
            </div>
        </div>

        {{-- El formulario de la accion va ARRIBA de la lista: en el telefono, con cuarenta
             alertas debajo, un boton al final obliga a recorrer toda la pagina para aplicar
             lo que ya se decidio. --}}
        <div class="space-y-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700 dark:bg-zinc-800/40">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <flux:select wire:model.live="destino" label="Pasar las marcadas a" class="min-h-11 text-base sm:min-h-0 sm:text-sm">
                    <flux:select.option value="">Elija un estado</flux:select.option>
                    @foreach ($destinos as $estado)
                        <flux:select.option value="{{ $estado }}">{{ $etiquetasEstado[$estado] }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div class="flex items-end">
                    <flux:button
                        variant="primary"
                        wire:click="aplicar"
                        wire:loading.attr="disabled"
                        class="min-h-11 w-full sm:min-h-0"
                    >
                        Aplicar a {{ number_format($this->cantidadMarcada()) }} alertas
                    </flux:button>
                </div>
            </div>

            <flux:textarea
                wire:model="nota"
                label="Justificacion del triaje{{ $this->exigeNota() ? ' (obligatoria)' : '' }}"
                rows="2"
                placeholder="Que se comprobo, que se decidio y por que. Quien lea esto manana no estara en la sala."
            />

            @error('nota')
                <p class="text-sm font-medium text-red-400">{{ $message }}</p>
            @enderror
            @error('seleccionadas')
                <p class="text-sm font-medium text-red-400">{{ $message }}</p>
            @enderror
            @error('destino')
                <p class="text-sm font-medium text-red-400">{{ $message }}</p>
            @enderror

            @if (in_array($destino, [\App\Models\AlertaSeguridad::ESTADO_CONTENIDA, \App\Models\AlertaSeguridad::ESTADO_CERRADA], true))
                {{-- Se describe lo que AFIRMA cada estado, no como mueve una metrica: el estado
                     se elige por lo que paso. --}}
                <p class="text-sm text-zinc-300 sm:text-xs">
                    «Contenida» afirma que había una amenaza activa y que se detuvo; su hora entra en el tiempo
                    medio de contención. Si era tráfico hostil sin impacto, elija «Cerrada» y escriba qué comprobó.
                    Si era tráfico legítimo, «Falso positivo».
                </p>
            @endif

            @if ($this->exigeNota())
                <p class="text-sm text-amber-400 sm:text-xs">
                    @if ($destino === \App\Models\AlertaSeguridad::ESTADO_FALSO_POSITIVO)
                        Marcar falso positivo exige escribir el motivo: sin justificacion no se distingue de cerrar
                        la alerta para bajar el contador.
                    @else
                        Un lote de mas de 40 alertas exige escribir que se reviso y con que criterio: nadie las ha
                        visto una por una, y la nota es lo que convierte el lote en una revision por muestreo documentada.
                    @endif
                </p>
            @endif
        </div>

        <div class="space-y-2">
            @forelse ($this->alertas as $alerta)
                <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-zinc-200 p-3 transition hover:border-zinc-500 dark:border-zinc-700">
                    <flux:checkbox
                        wire:model.live="seleccionadas"
                        value="{{ $alerta->id }}"
                        class="mt-0.5 shrink-0"
                    />

                    <div class="min-w-0 flex-1 space-y-1.5">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-pages::siem.severidad :valor="$alerta->severidad" />

                            <span class="rounded-full bg-zinc-800 px-2 py-0.5 text-[0.7rem] font-semibold text-zinc-200">
                                {{ $alerta->etiquetaEstado() }}
                            </span>

                            @if ($alerta->es_demostracion)
                                <flux:badge size="sm">demo</flux:badge>
                            @endif

                            <span class="siem-numero break-all text-xs text-zinc-400">
                                #{{ $alerta->id }} · {{ $alerta->clave_regla }}
                            </span>
                        </div>

                        <p class="break-words text-sm font-semibold text-white sm:truncate">{{ $alerta->titulo }}</p>

                        <p class="siem-numero break-words text-xs text-zinc-400">
                            {{ $alerta->detectada_en?->format('d/m/Y H:i') }}
                            · {{ $alerta->direccion_ip ?? 'sin direccion' }}
                            · {{ number_format($alerta->conteo_eventos) }} eventos
                            @if ($alerta->analista)
                                · atiende {{ $alerta->analista->name }}
                            @endif
                        </p>
                    </div>
                </label>
            @empty
                <div class="rounded-xl border border-dashed border-zinc-300 p-6 text-center sm:p-10 dark:border-zinc-700">
                    <flux:icon.check-circle class="mx-auto size-8 text-zinc-400" />
                    <p class="mt-3 font-medium text-white">No queda nada pendiente con este filtro</p>
                    <flux:text class="mt-1">
                        Si la cobertura de triaje sigue por debajo del cien por cien, mire la ventana de
                        observacion: la cifra de arriba solo cuenta las alertas de los ultimos {{ $dias }} dias,
                        y esta lista las muestra todas.
                    </flux:text>
                </div>
            @endforelse
        </div>
    </div>
</div>
