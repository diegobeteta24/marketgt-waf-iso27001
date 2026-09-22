<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sesion del plan de concienciacion y capacitacion (POL-006, control A.6.3 del Anexo A).
 *
 * Esta tabla guarda lo que el equipo se comprometio a impartir; las asistencias guardan lo
 * que de verdad ocurrio. Mantenerlas separadas es lo que permite al panel distinguir "no
 * planificado" de "planificado y no ejecutado", que son dos hallazgos de auditoria muy
 * distintos: el primero es una carencia del sistema de gestion, el segundo un retraso con
 * responsable y fecha.
 *
 * @property int $id
 * @property string $codigo
 * @property string $tipo
 * @property string $tema
 * @property string|null $descripcion
 * @property string $periodicidad
 * @property string|null $periodo
 * @property array<int, string>|null $modulos
 * @property array<int, string>|null $material
 * @property array<int, string>|null $destinatarios
 * @property string|null $instructor_funcion
 * @property string|null $modalidad
 * @property int|null $duracion_minutos
 * @property int $vigencia_meses
 * @property int $umbral_aprobacion
 * @property bool $cuenta_para_vigencia
 * @property bool $exige_a_todo_el_equipo
 * @property string $estado
 * @property CarbonInterface|null $programada_para
 * @property string|null $nota_programacion
 * @property CarbonInterface|null $impartida_en
 * @property string|null $observaciones
 * @property string|null $evidencia_ruta
 * @property string $origen
 * @property string|null $fuente
 * @property int|null $registrada_por
 * @property string|null $actor
 * @property CarbonInterface|null $registrada_en
 * @property-read Collection<int, AsistenciaCapacitacion> $asistencias
 */
class Capacitacion extends Model
{
    protected $table = 'capacitaciones';

    public const TIPO_FORMAL = 'formal';

    public const TIPO_INDUCCION = 'induccion';

    public const TIPO_ACTUALIZACION = 'actualizacion';

    public const TIPO_SIMULACRO = 'simulacro';

    public const TIPO_RECORDATORIO = 'recordatorio';

    public const ESTADO_PLANIFICADA = 'planificada';

    public const ESTADO_IMPARTIDA = 'impartida';

    public const ESTADO_CANCELADA = 'cancelada';

    public const ORIGEN_PLAN = 'plan';

    public const ORIGEN_MANUAL = 'manual';

    /**
     * @var array<string, string>
     */
    public const ETIQUETAS_TIPO = [
        self::TIPO_FORMAL => 'Capacitacion formal',
        self::TIPO_INDUCCION => 'Induccion',
        self::TIPO_ACTUALIZACION => 'Actualizacion tras incidente',
        self::TIPO_SIMULACRO => 'Simulacro de mesa',
        self::TIPO_RECORDATORIO => 'Recordatorio breve',
    ];

    /**
     * @var array<string, string>
     */
    public const ETIQUETAS_ESTADO = [
        self::ESTADO_PLANIFICADA => 'Planificada',
        self::ESTADO_IMPARTIDA => 'Impartida',
        self::ESTADO_CANCELADA => 'Cancelada',
    ];

    /**
     * Estados posibles de un miembro del equipo frente a la meta, de mejor a peor.
     * El orden es el que se usa para resumir a alguien que tiene varias asistencias.
     *
     * @var array<string, string>
     */
    public const ETIQUETAS_MIEMBRO = [
        'vigente' => 'Capacitacion vigente',
        'pendiente_evaluacion' => 'Asistio, sin evaluar',
        'vencida' => 'Capacitacion vencida',
        'no_superada' => 'No supero la evaluacion',
        'sin_registro' => 'Sin capacitacion registrada',
    ];

    /**
     * Aviso anticipado de caducidad. Treinta dias es el plazo con el que todavia se puede
     * convocar una sesion; avisar el dia del vencimiento equivale a no avisar.
     */
    public const DIAS_AVISO_VENCIMIENTO = 30;

    protected $fillable = [
        'codigo',
        'tipo',
        'tema',
        'descripcion',
        'periodicidad',
        'periodo',
        'modulos',
        'material',
        'destinatarios',
        'instructor_funcion',
        'modalidad',
        'duracion_minutos',
        'vigencia_meses',
        'umbral_aprobacion',
        'cuenta_para_vigencia',
        'exige_a_todo_el_equipo',
        'estado',
        'programada_para',
        'nota_programacion',
        'impartida_en',
        'observaciones',
        'evidencia_ruta',
        'origen',
        'fuente',
        'registrada_por',
        'actor',
        'registrada_en',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'modulos' => 'array',
            'material' => 'array',
            'destinatarios' => 'array',
            'duracion_minutos' => 'integer',
            'vigencia_meses' => 'integer',
            'umbral_aprobacion' => 'integer',
            'cuenta_para_vigencia' => 'boolean',
            'exige_a_todo_el_equipo' => 'boolean',
            'programada_para' => 'date',
            'impartida_en' => 'datetime',
            'registrada_en' => 'datetime',
        ];
    }

    /**
     * @return HasMany<AsistenciaCapacitacion, $this>
     */
    public function asistencias(): HasMany
    {
        return $this->hasMany(AsistenciaCapacitacion::class, 'capacitacion_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function registradaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrada_por');
    }

    /**
     * @param  Builder<$this>  $consulta
     * @return Builder<$this>
     */
    public function scopeImpartidas(Builder $consulta): Builder
    {
        return $consulta->where('estado', self::ESTADO_IMPARTIDA)->whereNotNull('impartida_en');
    }

    /**
     * Momento en que caduca lo aprendido en esta sesion.
     *
     * No se guarda en una columna a proposito. Copiado en cada asistencia habria dos
     * verdades sobre la misma fecha, y al corregir el dia en que se impartio la sesion una
     * de las dos se quedaria vieja sin que nada avisara.
     */
    public function venceEn(): ?CarbonImmutable
    {
        if ($this->impartida_en === null) {
            return null;
        }

        // copy() y no addMonths() a secas: el molde datetime devuelve un Carbon mutable y
        // sumarle meses corromperia la propia marca de tiempo del modelo en memoria.
        return CarbonImmutable::instance($this->impartida_en->copy())->addMonths($this->vigencia_meses);
    }

    /**
     * Una sesion acredita a quien la supero mientras no haya caducado, y solo si el plan
     * dice que acredita: el simulacro verifica la capacitacion, no la sustituye.
     */
    public function acreditaVigenciaEn(?CarbonInterface $ahora = null): bool
    {
        if (! $this->cuenta_para_vigencia) {
            return false;
        }

        $vence = $this->venceEn();

        return $vence !== null && $vence->greaterThanOrEqualTo($ahora ?? CarbonImmutable::now());
    }

    public function fueImpartida(): bool
    {
        return $this->estado === self::ESTADO_IMPARTIDA && $this->impartida_en !== null;
    }

    public function etiquetaTipo(): string
    {
        return self::ETIQUETAS_TIPO[$this->tipo] ?? $this->tipo;
    }

    public function etiquetaEstado(): string
    {
        return self::ETIQUETAS_ESTADO[$this->estado] ?? $this->estado;
    }

    public function duracionLegible(): string
    {
        if ($this->duracion_minutos === null) {
            return 'sin duracion declarada';
        }

        if ($this->duracion_minutos < 60) {
            return $this->duracion_minutos.' min';
        }

        $horas = $this->duracion_minutos / 60;

        return rtrim(rtrim(number_format($horas, 1), '0'), '.').' h';
    }

    /**
     * Roles que definen al equipo, es decir, el denominador de la metrica.
     *
     * Se lee de la configuracion con este valor por defecto para que el integrador pueda
     * ajustarlo sin tocar el modelo. El criterio no es arbitrario: POL-006 seccion 3
     * establece que "la capacitacion precede al acceso", de modo que el conjunto de personas
     * que deben estar capacitadas es exactamente el de las cuentas activas con acceso
     * privilegiado a la plataforma. Los clientes de la tienda no son el equipo.
     *
     * @return array<int, string>
     */
    public static function rolesEquipo(): array
    {
        $roles = config('siem.capacitacion.roles_equipo', [Rol::ADMINISTRADOR, Rol::AUDITOR]);

        return is_array($roles) && $roles !== [] ? array_values($roles) : [Rol::ADMINISTRADOR, Rol::AUDITOR];
    }

    /**
     * Miembros del equipo segun el criterio anterior. Es un HECHO de la base —una concesion
     * de rol con su fecha y quien la otorgo—, no una lista escrita a mano en ningun sitio.
     *
     * @return Collection<int, User>
     */
    public static function equipo(): Collection
    {
        return User::query()
            ->where('activo', true)
            ->whereHas('roles', fn (Builder $consulta) => $consulta->whereIn(
                'nombre',
                array_map(static fn (string $rol): string => Rol::canonico($rol), self::rolesEquipo()),
            ))
            ->orderBy('name')
            ->get();
    }

    public static function descripcionEquipo(): string
    {
        $etiquetas = Rol::query()
            ->whereIn('nombre', array_map(static fn (string $rol): string => Rol::canonico($rol), self::rolesEquipo()))
            ->pluck('etiqueta')
            ->all();

        if ($etiquetas === []) {
            $etiquetas = self::rolesEquipo();
        }

        return 'cuentas activas con rol '.implode(' o ', $etiquetas);
    }

    /**
     * La medicion del vertice de proteccion: que proporcion del equipo tiene hoy una
     * capacitacion vigente superada.
     *
     * Devuelve los hechos y su procedencia; quien la consume decide si cumple la meta. Si no
     * hay con que medir, 'medible' viene en falso y 'origen' explica exactamente que falta,
     * que es un resultado legitimo y preferible a un porcentaje inventado.
     *
     * @return array<string, mixed>
     */
    public static function medicionPersonalCapacitado(?CarbonInterface $ahora = null): array
    {
        $ahora = CarbonImmutable::instance(($ahora ?? CarbonImmutable::now())->toDateTime());

        $equipo = self::equipo();
        $sesiones = self::query()->orderBy('codigo')->get();

        $planificadas = $sesiones->where('estado', self::ESTADO_PLANIFICADA)->count();
        $impartidas = $sesiones->filter(static fn (self $sesion): bool => $sesion->fueImpartida())->count();

        // Sesiones cuya asistencia superada acredita capacitacion vigente en este momento.
        $acreditan = $sesiones->filter(static fn (self $sesion): bool => $sesion->acreditaVigenciaEn($ahora));

        // Sesiones que el plan exige a todo el equipo. De ellas sale el recuento de
        // asistencias que faltan por registrar, que es lo que separa "no planificado" de
        // "planificado y no ejecutado".
        $exigidas = $sesiones->filter(
            static fn (self $sesion): bool => $sesion->exige_a_todo_el_equipo && $sesion->estado !== self::ESTADO_CANCELADA,
        );

        $identificadoresEquipo = $equipo->pluck('id')->all();

        $asistencias = $identificadoresEquipo === []
            ? new Collection
            : AsistenciaCapacitacion::query()
                ->whereIn('usuario_id', $identificadoresEquipo)
                ->get();

        $registradas = $asistencias->count();
        $totalRegistradas = AsistenciaCapacitacion::query()->count();

        // Cuantas asistencias faltan: una por cada miembro del equipo que no figura en una
        // sesion que se le exige. Se cuenta sobre el plan completo, impartido o no.
        $faltantes = 0;
        $actasIncompletas = 0;

        foreach ($exigidas as $sesion) {
            $presentes = $asistencias->where('capacitacion_id', $sesion->id)->count();
            $hueco = max(0, count($identificadoresEquipo) - $presentes);
            $faltantes += $hueco;

            if ($sesion->fueImpartida()) {
                $actasIncompletas += $hueco;
            }
        }

        $base = [
            'porcentaje' => null,
            'capacitados' => 0,
            'equipo' => $equipo->count(),
            'miembros' => [],
            'sesiones_totales' => $sesiones->count(),
            'sesiones_planificadas' => $planificadas,
            'sesiones_impartidas' => $impartidas,
            'sesiones_acreditan' => $acreditan->count(),
            'asistencias_registradas' => $registradas,
            'asistencias_totales' => $totalRegistradas,
            'asistencias_pendientes' => $faltantes,
            'actas_incompletas' => $actasIncompletas,
            'pendientes_evaluacion' => 0,
            'proximos_a_vencer' => 0,
            'medible' => false,
            'muestra' => 0,
            'origen' => '',
            'advertencia' => null,
            'criterio_equipo' => self::descripcionEquipo(),
        ];

        // Sin denominador no hay proporcion. Devolver cero por ciento aqui seria afirmar que
        // el equipo esta sin capacitar cuando lo que ocurre es que no hay equipo declarado.
        if ($equipo->isEmpty()) {
            $base['origen'] = 'No hay ninguna cuenta activa que cumpla el criterio de equipo ('
                .self::descripcionEquipo().'). Sin denominador no existe proporcion que calcular.';

            return $base;
        }

        // Ninguna asistencia registrada: el plan puede estar definido, pero no hay ningun
        // hecho medido. Se distingue el motivo porque no es lo mismo que nada haya ocurrido
        // todavia que que haya ocurrido y nadie lo anotara.
        if ($registradas === 0) {
            $base['origen'] = $impartidas === 0
                ? sprintf(
                    'El plan esta definido: %d sesiones previstas en POL-006, ninguna impartida todavia. '
                        .'Faltan %d asistencias por registrar para poder medir.',
                    $sesiones->count(),
                    $faltantes,
                )
                : sprintf(
                    'Hay %s marcada%s como impartida%s pero ninguna asistencia registrada: falta el acta. '
                        .'Faltan %d asistencias por registrar para poder medir.',
                    $impartidas === 1 ? 'una sesion' : $impartidas.' sesiones',
                    $impartidas === 1 ? '' : 's',
                    $impartidas === 1 ? '' : 's',
                    $faltantes,
                );

            return $base;
        }

        $identificadoresAcreditan = $acreditan->pluck('id')->all();
        $sesionesPorId = $sesiones->keyBy('id');

        $miembros = [];
        $capacitados = 0;
        $pendientesEvaluacion = 0;
        $proximos = 0;

        foreach ($equipo as $persona) {
            $suyas = $asistencias->where('usuario_id', $persona->id);

            $estado = 'sin_registro';
            $acreditante = null;
            $vence = null;
            $dias = null;

            foreach ($suyas as $asistencia) {
                $sesion = $sesionesPorId->get($asistencia->capacitacion_id);

                if (! $sesion instanceof self || ! $sesion->cuenta_para_vigencia || ! $sesion->fueImpartida()) {
                    continue;
                }

                $esVigente = in_array($sesion->id, $identificadoresAcreditan, true);

                if ($asistencia->evaluacion_superada === true && $esVigente) {
                    // Si tiene varias vigentes se conserva la que caduca mas tarde: es la que
                    // describe hasta cuando esta acreditada esa persona.
                    $candidata = $sesion->venceEn();

                    if ($vence === null || ($candidata !== null && $candidata->greaterThan($vence))) {
                        $estado = 'vigente';
                        $acreditante = $sesion;
                        $vence = $candidata;
                    }

                    continue;
                }

                if ($estado === 'vigente') {
                    continue;
                }

                // Prioridad entre los estados no vigentes: primero lo accionable —falta
                // evaluar—, luego lo caducado, luego lo reprobado.
                if ($asistencia->evaluacion_superada === null) {
                    $estado = 'pendiente_evaluacion';
                    $acreditante = $sesion;

                    continue;
                }

                if ($asistencia->evaluacion_superada === true && $estado !== 'pendiente_evaluacion') {
                    $estado = 'vencida';
                    $acreditante = $sesion;

                    continue;
                }

                if ($estado === 'sin_registro') {
                    $estado = 'no_superada';
                    $acreditante = $sesion;
                }
            }

            if ($estado === 'vigente') {
                $capacitados++;
                $dias = (int) $ahora->startOfDay()->diffInDays($vence->startOfDay(), absolute: false);

                if ($dias <= self::DIAS_AVISO_VENCIMIENTO) {
                    $proximos++;
                }
            }

            if ($estado === 'pendiente_evaluacion') {
                $pendientesEvaluacion++;
            }

            $miembros[] = [
                'usuario' => $persona,
                'estado' => $estado,
                'etiqueta' => self::ETIQUETAS_MIEMBRO[$estado],
                'capacitacion' => $acreditante,
                'vence_en' => $vence,
                'dias_restantes' => $dias,
            ];
        }

        $total = $equipo->count();

        $avisos = [];

        if ($actasIncompletas > 0) {
            $avisos[] = 'Faltan '.$actasIncompletas.' asistencias por registrar de sesiones ya impartidas: '
                .'la cifra puede estar por debajo de la realidad hasta que se completen las actas.';
        }

        if ($pendientesEvaluacion > 0) {
            $avisos[] = ($pendientesEvaluacion === 1
                ? '1 persona figura como asistente'
                : $pendientesEvaluacion.' personas figuran como asistentes')
                .' sin resultado de evaluacion: POL-006 dice que eso acredita presencia y no capacitacion, '
                .'de modo que no cuenta en el numerador.';
        }

        if ($proximos > 0) {
            $avisos[] = 'A '.$proximos.($proximos === 1 ? ' persona le vence' : ' personas les vence')
                .' la capacitacion dentro de '.self::DIAS_AVISO_VENCIMIENTO.' dias o menos.';
        }

        $base['porcentaje'] = round($capacitados * 100 / $total, 1);
        $base['capacitados'] = $capacitados;
        $base['miembros'] = $miembros;
        $base['pendientes_evaluacion'] = $pendientesEvaluacion;
        $base['proximos_a_vencer'] = $proximos;
        $base['medible'] = true;
        $base['muestra'] = $total;
        $base['advertencia'] = $avisos === [] ? null : implode(' ', $avisos);
        $base['origen'] = sprintf(
            '%d de %d miembros del equipo con capacitacion vigente superada. Denominador: %s. '
                .'Numerador: asistencias con evaluacion superada a %s que acredita%s vigencia y no ha%s caducado. '
                .'Se leyeron %d asistencias registradas.',
            $capacitados,
            $total,
            self::descripcionEquipo(),
            $acreditan->count() === 1
                ? 'la sesion impartida'
                : 'alguna de las '.$acreditan->count().' sesiones impartidas',
            $acreditan->count() === 1 ? '' : 'n',
            $acreditan->count() === 1 ? '' : 'n',
            $registradas,
        );

        return $base;
    }
}
