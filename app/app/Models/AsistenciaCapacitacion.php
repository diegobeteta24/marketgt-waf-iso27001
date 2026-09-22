<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Asistencia de una persona a una sesion de capacitacion.
 *
 * Cada fila afirma un hecho del mundo real: alguien estuvo en una sesion y, si se le evaluo,
 * con que resultado. Por eso ningun semillero la escribe y por eso lleva procedencia: quien
 * la registro, cuando y desde donde. Un registro de capacitacion sin procedencia es lo que
 * convierte tantos expedientes de formacion en ficcion documental.
 *
 * @property int $id
 * @property int $capacitacion_id
 * @property int $usuario_id
 * @property string|null $funcion
 * @property bool $asistio
 * @property string|null $modalidad
 * @property bool|null $evaluacion_superada
 * @property int|null $puntuacion
 * @property CarbonInterface|null $evaluada_en
 * @property bool|null $ejercicio_practico_superado
 * @property string|null $observaciones
 * @property string|null $evidencia_ruta
 * @property string $origen
 * @property int|null $registrada_por
 * @property string $actor
 * @property CarbonInterface $registrada_en
 * @property-read Capacitacion|null $capacitacion
 * @property-read User|null $usuario
 */
class AsistenciaCapacitacion extends Model
{
    protected $table = 'asistencias_capacitacion';

    public const ORIGEN_PANEL = 'panel';

    public const ORIGEN_COMANDO = 'comando';

    public const ORIGEN_IMPORTACION = 'importacion';

    /**
     * Resultados que puede registrar quien lleva el acta. "Pendiente" existe porque es el
     * estado real mas frecuente el mismo dia de la sesion, y forzar a elegir aprobado o
     * reprobado en ese momento convertiria el acta en una suposicion.
     */
    public const RESULTADO_SUPERADA = 'superada';

    public const RESULTADO_NO_SUPERADA = 'no_superada';

    public const RESULTADO_PENDIENTE = 'pendiente';

    /**
     * @var array<string, string>
     */
    public const ETIQUETAS_RESULTADO = [
        self::RESULTADO_SUPERADA => 'Supero la evaluacion',
        self::RESULTADO_NO_SUPERADA => 'No supero',
        self::RESULTADO_PENDIENTE => 'Asistio, sin evaluar',
    ];

    protected $fillable = [
        'capacitacion_id',
        'usuario_id',
        'funcion',
        'asistio',
        'modalidad',
        'evaluacion_superada',
        'puntuacion',
        'evaluada_en',
        'ejercicio_practico_superado',
        'observaciones',
        'evidencia_ruta',
        'origen',
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
            'asistio' => 'boolean',
            'evaluacion_superada' => 'boolean',
            'ejercicio_practico_superado' => 'boolean',
            'puntuacion' => 'integer',
            'evaluada_en' => 'datetime',
            'registrada_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Capacitacion, $this>
     */
    public function capacitacion(): BelongsTo
    {
        return $this->belongsTo(Capacitacion::class, 'capacitacion_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
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
    public function scopeSuperadas(Builder $consulta): Builder
    {
        return $consulta->where('evaluacion_superada', true);
    }

    /**
     * @param  Builder<$this>  $consulta
     * @return Builder<$this>
     */
    public function scopeSinEvaluar(Builder $consulta): Builder
    {
        return $consulta->whereNull('evaluacion_superada');
    }

    public function resultado(): string
    {
        return match ($this->evaluacion_superada) {
            true => self::RESULTADO_SUPERADA,
            false => self::RESULTADO_NO_SUPERADA,
            default => self::RESULTADO_PENDIENTE,
        };
    }

    public function etiquetaResultado(): string
    {
        return self::ETIQUETAS_RESULTADO[$this->resultado()];
    }

    /**
     * ¿Acredita esta asistencia a su titular en este momento?
     *
     * Las dos condiciones son necesarias y ninguna sobra: la evaluacion superada acredita
     * conocimiento y la sesion vigente acredita que ese conocimiento sigue siendo el actual.
     */
    public function acreditaEn(?CarbonInterface $ahora = null): bool
    {
        return $this->evaluacion_superada === true
            && $this->capacitacion instanceof Capacitacion
            && $this->capacitacion->acreditaVigenciaEn($ahora);
    }

    /**
     * Registra o corrige una asistencia sellando siempre la procedencia.
     *
     * Es el unico camino que usan el panel y cualquier comando futuro, de modo que no exista
     * forma de crear una fila sin actor ni fecha de registro. Corregir vuelve a sellar: el
     * acta dice quien la dejo como esta hoy, que es lo que se pregunta cuando una cifra se
     * discute.
     *
     * @param  array<string, mixed>  $datos
     */
    public static function registrar(
        Capacitacion $capacitacion,
        User $persona,
        array $datos,
        ?User $actor = null,
        string $origen = self::ORIGEN_PANEL,
    ): self {
        $resultado = $datos['resultado'] ?? self::RESULTADO_PENDIENTE;

        $superada = match ($resultado) {
            self::RESULTADO_SUPERADA => true,
            self::RESULTADO_NO_SUPERADA => false,
            default => null,
        };

        $ahora = CarbonImmutable::now();

        return self::query()->updateOrCreate(
            ['capacitacion_id' => $capacitacion->id, 'usuario_id' => $persona->id],
            [
                'funcion' => $datos['funcion'] ?? null,
                'asistio' => (bool) ($datos['asistio'] ?? true),
                'modalidad' => $datos['modalidad'] ?? $capacitacion->modalidad,
                'evaluacion_superada' => $superada,
                'puntuacion' => $datos['puntuacion'] ?? null,
                // La evaluacion solo tiene fecha cuando hay resultado. Sellarla al registrar
                // una asistencia pendiente afirmaria que se evaluo a alguien sin evaluarlo.
                'evaluada_en' => $superada === null ? null : $ahora,
                'ejercicio_practico_superado' => $datos['ejercicio_practico_superado'] ?? null,
                'observaciones' => $datos['observaciones'] ?? null,
                'evidencia_ruta' => $datos['evidencia_ruta'] ?? null,
                'origen' => $origen,
                'registrada_por' => $actor?->id,
                // El nombre y el correo se copian ahora: si la cuenta desaparece manana, la
                // clave foranea deja registrada_por en nulo y esta columna conserva quien fue.
                'actor' => $actor === null
                    ? 'proceso automatico'
                    : $actor->name.' <'.$actor->email.'>',
                'registrada_en' => $ahora,
            ],
        );
    }
}
