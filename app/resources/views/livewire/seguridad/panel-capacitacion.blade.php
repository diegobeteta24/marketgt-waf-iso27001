@php
    use App\Livewire\Seguridad\PanelCapacitacion;
    use App\Models\AsistenciaCapacitacion;
    use App\Models\Capacitacion;
    use App\Services\Siem\CalculadoraMetricas;

    $metrica = $this->metrica;
    $medicion = $this->medicion;
    $sesiones = $this->sesiones;
    $sesion = $this->sesion;
    $asistencias = $this->asistenciasDeLaSesion;

    $colorEstado = [
        CalculadoraMetricas::CUMPLE => 'var(--siem-cumple, #34d399)',
        CalculadoraMetricas::INCUMPLE => 'var(--siem-critica, #f87171)',
        CalculadoraMetricas::SIN_DATOS => 'var(--siem-informativa, #a3a3a3)',
    ];

    // Un color por estado de la persona. El verde es el unico que cumple la meta; el ambar
    // marca lo accionable —falta evaluar, esta por vencer— y el rojo lo que no acredita.
    $colorMiembro = [
        'vigente' => 'var(--siem-cumple, #34d399)',
        'pendiente_evaluacion' => '#fbbf24',
        'vencida' => '#fb923c',
        'no_superada' => 'var(--siem-critica, #f87171)',
        'sin_registro' => 'var(--siem-informativa, #a3a3a3)',
    ];
@endphp

<div class="space-y-6">

    {{-- LA METRICA. Misma forma y mismo lenguaje visual que las del triangulo, porque es la
         misma metrica: lo que aqui se registra es lo que alli se muestra. --}}
    <div class="rounded-xl border border-zinc-200 bg-white p-3 sm:p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
            <div class="min-w-0">
                <flux:heading size="lg">{{ $metrica['nombre'] }}</flux:heading>
                <flux:subheading>Vertice de proteccion · control A.6.3 del Anexo A · POL-006</flux:subheading>
            </div>

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
            'siem-numero mt-3 break-words',
            'text-3xl font-semibold text-white' => $metrica['estado'] !== CalculadoraMetricas::SIN_DATOS,
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

        {{-- Barra del avance. Solo cuando hay algo medido: dibujar una barra vacia junto a
             "sin datos" se leeria como un cero, y cero y sin datos no son lo mismo. --}}
        @if ($metrica['estado'] !== CalculadoraMetricas::SIN_DATOS)
            @php $ancho = max(1.0, min(100.0, (float) $metrica['valor'])); @endphp

            {{-- El color va en style y no en fill: un atributo de presentacion es lo ultimo
                 que gana en la cascada y cualquier regla del panel lo pisaria, dejando la
                 barra negra sobre fondo negro. --}}
            <svg viewBox="0 0 200 12" class="mt-3 h-3 w-full" role="img"
                 aria-label="Personal capacitado: {{ $metrica['valor_texto'] }} de la meta">
                <rect x="0" y="0" width="200" height="12" rx="6" style="fill: rgba(255,255,255,0.10)" />
                <rect x="0" y="0" width="{{ round($ancho * 2, 1) }}" height="12" rx="6"
                      style="fill: {{ $colorEstado[$metrica['estado']] }}" />
            </svg>
        @endif

        {{-- El recuento que separa "no planificado" de "planificado y no ejecutado". Es la
             distincion que evalua una auditoria de gestion, y por eso va con la cifra y no
             escondida en un informe. --}}
        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                <p class="text-xs uppercase tracking-wide text-zinc-400">Sesiones del plan</p>
                <p class="siem-numero mt-1 text-xl font-semibold text-zinc-100">{{ $medicion['sesiones_totales'] }}</p>
            </div>
            <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                <p class="text-xs uppercase tracking-wide text-zinc-400">Impartidas</p>
                <p class="siem-numero mt-1 text-xl font-semibold text-zinc-100">{{ $medicion['sesiones_impartidas'] }}</p>
            </div>
            <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                <p class="text-xs uppercase tracking-wide text-zinc-400">Asistencias registradas</p>
                <p class="siem-numero mt-1 text-xl font-semibold text-zinc-100">{{ $medicion['asistencias_registradas'] }}</p>
            </div>
            <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                <p class="text-xs uppercase tracking-wide text-zinc-400">Faltan por registrar</p>
                <p class="siem-numero mt-1 text-xl font-semibold text-amber-400">{{ $medicion['asistencias_pendientes'] }}</p>
            </div>
        </div>
    </div>

    {{-- ESTADO DE CADA PERSONA. El denominador con nombre y apellido: una proporcion sin la
         lista de quien la compone no se puede verificar, y verificarla es lo que hace un
         auditor. --}}
    <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 p-3 sm:p-4 dark:border-zinc-700">
            <flux:heading size="lg">Equipo</flux:heading>
            <flux:subheading>
                Denominador de la metrica: {{ $medicion['criterio_equipo'] }}. El criterio no es arbitrario:
                POL-006 establece que la capacitacion precede al acceso, de modo que quien debe estar capacitado
                es exactamente quien tiene acceso privilegiado a la plataforma.
            </flux:subheading>
        </div>

        @if ($medicion['equipo'] === 0)
            <p class="p-4 text-sm text-zinc-400">
                No hay ninguna cuenta activa que cumpla el criterio. Sin denominador no hay proporcion que calcular,
                y por eso la metrica se declara sin datos en lugar de mostrar un cero.
            </p>
        @elseif ($medicion['miembros'] === [])
            <p class="p-4 text-sm text-zinc-400">
                {{ $medicion['equipo'] }} personas forman el equipo. Todavia no hay ninguna asistencia registrada,
                asi que no se puede afirmar nada sobre su capacitacion: eso es un dato ausente, no un cero.
            </p>
        @else
            <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($medicion['miembros'] as $miembro)
                    <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-2 p-3 sm:p-4">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-white">{{ $miembro['usuario']->name }}</p>
                            <p class="break-words text-xs text-zinc-400">
                                {{ implode(' · ', $miembro['usuario']->etiquetasRoles()) ?: 'sin rol declarado' }}
                            </p>
                        </div>

                        <div class="min-w-0 text-right">
                            <p class="inline-flex items-center gap-1.5 text-sm font-semibold"
                               style="color: {{ $colorMiembro[$miembro['estado']] ?? 'var(--siem-informativa, #a3a3a3)' }}">
                                <span class="siem-muestra"
                                      style="background: {{ $colorMiembro[$miembro['estado']] ?? 'var(--siem-informativa, #a3a3a3)' }}"
                                      aria-hidden="true"></span>
                                {{ $miembro['etiqueta'] }}
                            </p>

                            @if ($miembro['capacitacion'] instanceof Capacitacion)
                                <p class="mt-1 break-words text-xs text-zinc-400">
                                    {{ $miembro['capacitacion']->codigo }}
                                    @if ($miembro['capacitacion']->impartida_en)
                                        · impartida el {{ $miembro['capacitacion']->impartida_en->format('d/m/Y') }}
                                    @endif
                                </p>
                            @endif

                            @if ($miembro['vence_en'])
                                <p class="mt-1 text-xs {{ $miembro['dias_restantes'] !== null && $miembro['dias_restantes'] <= Capacitacion::DIAS_AVISO_VENCIMIENTO ? 'font-medium text-amber-400' : 'text-zinc-400' }}">
                                    Vence el {{ $miembro['vence_en']->format('d/m/Y') }}
                                    @if ($miembro['dias_restantes'] !== null)
                                        · quedan {{ $miembro['dias_restantes'] }} dias
                                    @endif
                                </p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- EL PLAN Y EL PASE DE LISTA. Van juntos a proposito: un control que obliga a navegar
         a otra pantalla para anotar un hecho se deja de usar, y ese abandono es la razon por
         la que tantos registros de capacitacion son ficcion documental. --}}
    <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 p-3 sm:p-4 dark:border-zinc-700">
            <flux:heading size="lg">Plan de capacitacion</flux:heading>
            <flux:subheading>
                Las actividades que declara POL-006, con su temario, su material y su periodicidad. El plan lo
                siembra el despliegue; las asistencias las registra una persona cuando la sesion ocurre.
            </flux:subheading>
        </div>

        @if ($sesiones->isEmpty())
            <p class="p-4 text-sm text-zinc-400">
                No hay ninguna sesion sembrada. Ejecute
                <code class="rounded bg-zinc-800 px-1">php artisan db:seed --class=CapacitacionesSeeder</code>
                para cargar el plan declarado en POL-006.
            </p>
        @else
            <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($sesiones as $fila)
                    @php $abierta = $this->sesionSeleccionada === $fila->id; @endphp

                    <div class="p-3 sm:p-4">
                        <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-2">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <p class="siem-numero text-sm font-semibold text-white">{{ $fila->codigo }}</p>

                                    @if ($fila->fueImpartida())
                                        <span class="inline-flex items-center gap-1 text-xs font-semibold" style="color: var(--siem-cumple, #34d399)">
                                            <flux:icon.check-circle class="size-4" />
                                            Impartida el {{ $fila->impartida_en->format('d/m/Y') }}
                                        </span>
                                    @elseif ($fila->estado === Capacitacion::ESTADO_CANCELADA)
                                        <span class="text-xs font-semibold text-zinc-400">Cancelada</span>
                                    @else
                                        <span class="inline-flex items-center gap-1 text-xs font-semibold text-amber-400">
                                            <flux:icon.clock class="size-4" /> Planificada
                                        </span>
                                    @endif
                                </div>

                                <p class="mt-1 break-words text-sm text-zinc-100">{{ $fila->tema }}</p>

                                <p class="mt-1 break-words text-xs text-zinc-400">
                                    {{ $fila->etiquetaTipo() }} · {{ $fila->periodicidad }} · {{ $fila->duracionLegible() }}
                                    @if ($fila->instructor_funcion)
                                        · {{ $fila->instructor_funcion }}
                                    @endif
                                    @if ($fila->cuenta_para_vigencia)
                                        · acredita vigencia {{ $fila->vigencia_meses }} meses
                                    @else
                                        · no acredita vigencia
                                    @endif
                                </p>

                                <p class="mt-1 text-xs text-zinc-400">
                                    {{ $fila->asistencias_count }} asistencias registradas,
                                    {{ $fila->asistencias_superadas_count }} con evaluacion superada.
                                </p>
                            </div>

                            <flux:button size="sm" variant="{{ $abierta ? 'primary' : 'filled' }}" wire:click="seleccionar({{ $fila->id }})">
                                {{ $abierta ? 'Cerrar' : 'Pasar lista' }}
                            </flux:button>
                        </div>

                        @if ($abierta && $sesion instanceof Capacitacion)
                            <div class="mt-4 space-y-4 rounded-lg border border-zinc-700 bg-zinc-950/40 p-3 sm:p-4">

                                @if ($sesion->descripcion)
                                    <p class="break-words text-sm leading-relaxed text-zinc-300">{{ $sesion->descripcion }}</p>
                                @endif

                                @if ($sesion->modulos)
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-400">Modulos</p>
                                        <ul class="mt-1 space-y-0.5 text-sm text-zinc-300">
                                            @foreach ($sesion->modulos as $modulo)
                                                <li class="break-words">{{ $modulo }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif

                                @if ($sesion->material)
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-400">Material de apoyo</p>
                                        <ul class="mt-1 space-y-0.5 text-sm text-zinc-300">
                                            @foreach ($sesion->material as $recurso)
                                                <li class="break-words">{{ $recurso }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif

                                @if ($sesion->nota_programacion)
                                    <p class="break-words text-xs leading-relaxed text-zinc-400">{{ $sesion->nota_programacion }}</p>
                                @endif

                                @if ($sesion->fuente)
                                    <p class="break-words text-xs leading-relaxed text-zinc-400">
                                        <span class="font-medium">Procedencia del plan:</span> {{ $sesion->fuente }}
                                    </p>
                                @endif

                                @if (! $sesion->fueImpartida())
                                    {{-- Sellar el hecho. Sin esta fecha la sesion es un plan y ninguna
                                         asistencia suya acredita nada, porque la vigencia se cuenta desde aqui. --}}
                                    <div class="space-y-3 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3">
                                        <p class="text-sm text-zinc-200">
                                            La sesion figura como planificada. Marquela como impartida para poder pasar lista:
                                            una asistencia a una sesion que no ocurrio no es un hecho registrable.
                                        </p>

                                        <div class="grid gap-3 sm:grid-cols-2">
                                            <flux:input
                                                type="date"
                                                label="Fecha en que se impartio"
                                                wire:model="fechaImpartida"
                                                max="{{ now()->toDateString() }}"
                                            />

                                            <flux:select label="Modalidad" wire:model="modalidad">
                                                @foreach (PanelCapacitacion::MODALIDADES as $valor => $etiqueta)
                                                    <flux:select.option value="{{ $valor }}">{{ $etiqueta }}</flux:select.option>
                                                @endforeach
                                            </flux:select>
                                        </div>

                                        @error('fechaImpartida')
                                            <p class="text-sm font-medium text-red-400">{{ $message }}</p>
                                        @enderror

                                        <flux:button size="sm" variant="primary" wire:click="marcarImpartida({{ $sesion->id }})">
                                            Marcar como impartida
                                        </flux:button>
                                    </div>
                                @else
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <p class="text-xs text-zinc-400">
                                            Impartida el {{ $sesion->impartida_en->format('d/m/Y') }}
                                            @if ($sesion->modalidad) · {{ $sesion->modalidad }} @endif
                                            @if ($sesion->venceEn() && $sesion->cuenta_para_vigencia)
                                                · vigencia hasta el {{ $sesion->venceEn()->format('d/m/Y') }}
                                            @endif
                                            @if ($sesion->actor) · sellada por {{ $sesion->actor }} @endif
                                        </p>

                                        <flux:button size="xs" variant="subtle" wire:click="devolverAPlanificada({{ $sesion->id }})">
                                            Corregir fecha
                                        </flux:button>
                                    </div>

                                    {{-- EL PASE DE LISTA. Una fila por persona y un clic por resultado. --}}
                                    <div class="divide-y divide-zinc-800 rounded-lg border border-zinc-700">
                                        @foreach ($this->equipo as $persona)
                                            @php $registro = $asistencias[$persona->id] ?? null; @endphp

                                            <div class="space-y-3 p-3">
                                                <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                                                    <div class="min-w-0">
                                                        <p class="text-sm font-medium text-white">{{ $persona->name }}</p>
                                                        <p class="break-words text-xs text-zinc-400">{{ $this->funcionDe($persona) ?? 'sin rol declarado' }}</p>
                                                    </div>

                                                    @if ($registro)
                                                        <div class="min-w-0 text-right">
                                                            <p class="text-xs font-semibold"
                                                               style="color: {{ $registro->evaluacion_superada === true ? 'var(--siem-cumple, #34d399)' : ($registro->evaluacion_superada === false ? 'var(--siem-critica, #f87171)' : '#fbbf24') }}">
                                                                {{ $registro->etiquetaResultado() }}
                                                                @if ($registro->puntuacion !== null)
                                                                    · {{ $registro->puntuacion }} %
                                                                @endif
                                                            </p>
                                                            {{-- La procedencia, a la vista y no en un informe aparte: es lo
                                                                 primero que se pregunta cuando una cifra se discute. --}}
                                                            <p class="mt-0.5 break-words text-xs text-zinc-400">
                                                                registrada por {{ $registro->actor }}
                                                                el {{ $registro->registrada_en->format('d/m/Y H:i') }}
                                                            </p>
                                                        </div>
                                                    @else
                                                        <span class="text-xs text-zinc-400">Sin registrar</span>
                                                    @endif
                                                </div>

                                                <div class="flex flex-wrap items-end gap-2">
                                                    <flux:input
                                                        type="text"
                                                        inputmode="numeric"
                                                        size="sm"
                                                        class="max-w-24"
                                                        placeholder="Nota"
                                                        wire:model="puntuaciones.{{ $persona->id }}"
                                                    />

                                                    <flux:button size="sm" variant="primary"
                                                                 wire:click="registrar({{ $persona->id }}, '{{ AsistenciaCapacitacion::RESULTADO_SUPERADA }}')">
                                                        Supero
                                                    </flux:button>

                                                    <flux:button size="sm" variant="danger"
                                                                 wire:click="registrar({{ $persona->id }}, '{{ AsistenciaCapacitacion::RESULTADO_NO_SUPERADA }}')">
                                                        No supero
                                                    </flux:button>

                                                    <flux:button size="sm" variant="filled"
                                                                 wire:click="registrar({{ $persona->id }}, '{{ AsistenciaCapacitacion::RESULTADO_PENDIENTE }}')">
                                                        Asistio, sin evaluar
                                                    </flux:button>

                                                    @if ($registro)
                                                        <flux:button size="sm" variant="subtle"
                                                                     wire:click="retirarAsistencia({{ $persona->id }})">
                                                            Retirar
                                                        </flux:button>
                                                    @endif
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>

                                    <p class="break-words text-xs leading-relaxed text-zinc-400">
                                        El umbral de aprobacion de esta sesion es {{ $sesion->umbral_aprobacion }} %.
                                        La nota es opcional: POL-006 admite registrar la presencia el mismo dia y evaluar
                                        despues, pero una asistencia sin resultado de evaluacion acredita presencia y no
                                        capacitacion, y por eso no cuenta en la metrica.
                                    </p>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
