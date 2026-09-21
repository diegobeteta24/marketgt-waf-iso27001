<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Alerta producida por el motor de correlacion. El ciclo de estados es lo que demuestra
 * operacion y no solo deteccion: una herramienta encendida genera alertas, un equipo que
 * opera las mueve de "nueva" a "cerrada" dejando marcas de tiempo auditables.
 *
 * @property int $id
 * @property int|null $regla_correlacion_id
 * @property string $clave_regla
 * @property string $titulo
 * @property string $descripcion
 * @property string $severidad
 * @property string $estado
 * @property string|null $direccion_ip
 * @property int|null $usuario_objetivo_id
 * @property CarbonInterface|null $primer_evento_en
 * @property CarbonInterface $detectada_en
 * @property CarbonInterface|null $confirmada_en
 * @property CarbonInterface|null $contenida_en
 * @property CarbonInterface|null $cerrada_en
 * @property int|null $atendida_por
 * @property array<string, mixed> $evidencia
 * @property string $accion_recomendada
 * @property string|null $notas_triaje
 * @property int $conteo_eventos
 * @property string $huella_agrupacion
 * @property bool $es_demostracion
 */
class AlertaSeguridad extends Model
{
    protected $table = 'alertas_seguridad';

    public const ESTADO_NUEVA = 'nueva';

    public const ESTADO_EN_TRIAJE = 'en_triaje';

    public const ESTADO_CONTENIDA = 'contenida';

    public const ESTADO_CERRADA = 'cerrada';

    public const ESTADO_FALSO_POSITIVO = 'falso_positivo';

    /**
     * @var array<string, string>
     */
    public const ETIQUETAS_ESTADO = [
        self::ESTADO_NUEVA => 'Nueva',
        self::ESTADO_EN_TRIAJE => 'En triaje',
        self::ESTADO_CONTENIDA => 'Contenida',
        self::ESTADO_CERRADA => 'Cerrada',
        self::ESTADO_FALSO_POSITIVO => 'Falso positivo',
    ];

    /**
     * Estados que significan "alguien la miro y dijo que es real". Solo desde aqui tiene
     * sentido medir el tiempo de contencion.
     *
     * @var array<int, string>
     */
    public const ESTADOS_CONFIRMADOS = [
        self::ESTADO_EN_TRIAJE,
        self::ESTADO_CONTENIDA,
        self::ESTADO_CERRADA,
    ];

    /**
     * Una alerta abierta es la que todavia reclama trabajo del analista.
     *
     * @var array<int, string>
     */
    public const ESTADOS_ABIERTOS = [
        self::ESTADO_NUEVA,
        self::ESTADO_EN_TRIAJE,
    ];

    protected $fillable = [
        'regla_correlacion_id',
        'clave_regla',
        'titulo',
        'descripcion',
        'severidad',
        'estado',
        'direccion_ip',
        'usuario_objetivo_id',
        'primer_evento_en',
        'detectada_en',
        'confirmada_en',
        'contenida_en',
        'cerrada_en',
        'atendida_por',
        'evidencia',
        'accion_recomendada',
        'notas_triaje',
        'conteo_eventos',
        'huella_agrupacion',
        'es_demostracion',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'primer_evento_en' => 'datetime',
            'detectada_en' => 'datetime',
            'confirmada_en' => 'datetime',
            'contenida_en' => 'datetime',
            'cerrada_en' => 'datetime',
            'evidencia' => 'array',
            'es_demostracion' => 'boolean',
            'conteo_eventos' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ReglaCorrelacion, $this>
     */
    public function regla(): BelongsTo
    {
        return $this->belongsTo(ReglaCorrelacion::class, 'regla_correlacion_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuarioObjetivo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_objetivo_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function analista(): BelongsTo
    {
        return $this->belongsTo(User::class, 'atendida_por');
    }

    /**
     * @return BelongsToMany<EventoSeguridad, $this>
     */
    public function eventos(): BelongsToMany
    {
        return $this->belongsToMany(
            EventoSeguridad::class,
            'alerta_evento',
            'alerta_seguridad_id',
            'evento_seguridad_id',
        );
    }

    /**
     * @param  Builder<$this>  $consulta
     * @return Builder<$this>
     */
    public function scopeAbiertas(Builder $consulta): Builder
    {
        return $consulta->whereIn('estado', self::ESTADOS_ABIERTOS);
    }

    /**
     * Mueve la alerta por su ciclo de vida sellando la marca de tiempo del estado alcanzado.
     * Las marcas no se sobrescriben: la primera vez que se confirma o contiene es la que cuenta
     * para las metricas, y reabrir una alerta no debe mejorar el historico del equipo.
     */
    public function cambiarEstado(string $nuevoEstado, ?int $analistaId = null, ?string $notas = null): void
    {
        $momento = CarbonImmutable::now();

        $this->estado = $nuevoEstado;

        if (in_array($nuevoEstado, self::ESTADOS_CONFIRMADOS, true) && $this->confirmada_en === null) {
            $this->confirmada_en = $momento;
        }

        if ($nuevoEstado === self::ESTADO_CONTENIDA && $this->contenida_en === null) {
            $this->contenida_en = $momento;
        }

        if (in_array($nuevoEstado, [self::ESTADO_CERRADA, self::ESTADO_FALSO_POSITIVO], true) && $this->cerrada_en === null) {
            $this->cerrada_en = $momento;
        }

        if ($analistaId !== null) {
            $this->atendida_por = $analistaId;
        }

        if ($notas !== null && trim($notas) !== '') {
            $this->notas_triaje = $notas;
        }

        $this->save();
    }

    /**
     * Minutos entre el primer evento del ataque y el momento en que el motor lo detecto.
     */
    public function minutosHastaDeteccion(): ?float
    {
        if ($this->primer_evento_en === null) {
            return null;
        }

        return round($this->primer_evento_en->diffInSeconds($this->detectada_en, absolute: true) / 60, 1);
    }

    /**
     * Minutos entre la confirmacion humana y la contencion.
     */
    public function minutosHastaContencion(): ?float
    {
        if ($this->confirmada_en === null || $this->contenida_en === null) {
            return null;
        }

        return round($this->confirmada_en->diffInSeconds($this->contenida_en, absolute: true) / 60, 1);
    }

    public function etiquetaEstado(): string
    {
        return self::ETIQUETAS_ESTADO[$this->estado] ?? $this->estado;
    }
}
