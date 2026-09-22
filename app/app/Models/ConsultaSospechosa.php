<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Consulta de búsqueda con la que el sitio aparece en Google y que no pertenece al negocio.
 *
 * POR QUÉ ES UNA TABLA PROPIA Y NO UNA FILA MÁS DE incidentes_seo
 * ---------------------------------------------------------------------------
 * Un incidente responde a "qué pasó"; esta tabla responde a "qué sigue indexado". Son dos
 * preguntas con ciclos de vida distintos: el incidente se cierra cuando el analista lo
 * atiende, pero la consulta sigue apareciendo en Search Console mientras el contenido
 * inyectado continúe en el índice de Google, que es semanas DESPUÉS de haberlo borrado del
 * servidor. Guardar la consulta aparte permite responder lo que de verdad importa después de
 * limpiar: "¿ya dejó de salir?".
 *
 * Por eso guarda también el vocabulario con el que se juzgó. El veredicto depende de lo que
 * el responsable declaró como propio de su actividad; sin ese dato, nadie puede reproducir la
 * decisión meses después, y un control cuyo criterio no se puede reconstruir no se puede auditar.
 *
 * @property int $id
 * @property string $consulta
 * @property string $consulta_normalizada
 * @property int|null $clics
 * @property int|null $impresiones
 * @property float|null $tasa_clics
 * @property int $puntuacion
 * @property string $veredicto
 * @property array<int, array<string, mixed>>|null $senales
 * @property array<int, string>|null $vocabulario
 * @property string $estado
 * @property int $veces_vista
 * @property Carbon $primera_vez_en
 * @property Carbon $ultima_vez_en
 * @property string $huella
 * @property int|null $incidente_seo_id
 * @property int|null $analizado_por
 * @property string|null $notas
 * @property-read IncidenteSeo|null $incidente
 * @property-read User|null $analista
 */
class ConsultaSospechosa extends Model
{
    protected $table = 'consultas_sospechosas';

    public const VEREDICTO_LIMPIA = 'limpia';

    public const VEREDICTO_REVISAR = 'revisar';

    public const VEREDICTO_ENVENENADA = 'envenenada';

    public const ESTADO_NUEVA = 'nueva';

    public const ESTADO_CONFIRMADA = 'confirmada';

    public const ESTADO_FALSO_POSITIVO = 'falso_positivo';

    public const ESTADO_CERRADA = 'cerrada';

    /**
     * @var array<string, string>
     */
    public const ETIQUETAS_VEREDICTO = [
        self::VEREDICTO_LIMPIA => 'Limpia',
        self::VEREDICTO_REVISAR => 'Revisar',
        self::VEREDICTO_ENVENENADA => 'Envenenada',
    ];

    /**
     * @var array<string, string>
     */
    public const ETIQUETAS_ESTADO = [
        self::ESTADO_NUEVA => 'Nueva',
        self::ESTADO_CONFIRMADA => 'Confirmada',
        self::ESTADO_FALSO_POSITIVO => 'Falso positivo',
        self::ESTADO_CERRADA => 'Cerrada',
    ];

    protected $fillable = [
        'consulta',
        'consulta_normalizada',
        'clics',
        'impresiones',
        'tasa_clics',
        'puntuacion',
        'veredicto',
        'senales',
        'vocabulario',
        'estado',
        'veces_vista',
        'primera_vez_en',
        'ultima_vez_en',
        'huella',
        'incidente_seo_id',
        'analizado_por',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'senales' => 'array',
            'vocabulario' => 'array',
            'clics' => 'integer',
            'impresiones' => 'integer',
            'tasa_clics' => 'float',
            'puntuacion' => 'integer',
            'veces_vista' => 'integer',
            'primera_vez_en' => 'datetime',
            'ultima_vez_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<IncidenteSeo, $this>
     */
    public function incidente(): BelongsTo
    {
        return $this->belongsTo(IncidenteSeo::class, 'incidente_seo_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function analista(): BelongsTo
    {
        return $this->belongsTo(User::class, 'analizado_por');
    }

    /**
     * @param  Builder<$this>  $consulta
     * @return Builder<$this>
     */
    public function scopeAbiertas(Builder $consulta): Builder
    {
        return $consulta->whereIn('estado', [self::ESTADO_NUEVA, self::ESTADO_CONFIRMADA]);
    }

    /**
     * @param  Builder<$this>  $consulta
     * @return Builder<$this>
     */
    public function scopeEnvenenadas(Builder $consulta): Builder
    {
        return $consulta->where('veredicto', self::VEREDICTO_ENVENENADA);
    }

    public function etiquetaVeredicto(): string
    {
        return self::ETIQUETAS_VEREDICTO[$this->veredicto] ?? $this->veredicto;
    }

    public function etiquetaEstado(): string
    {
        return self::ETIQUETAS_ESTADO[$this->estado] ?? $this->estado;
    }

    /**
     * Tasa de clics en porcentaje para la pantalla. Devuelve null y no cero cuando no hay
     * datos: cero por ciento es un hallazgo y "no lo sabemos" no lo es, y confundirlos sería
     * inventar la señal más importante del control.
     */
    public function porcentajeClics(): ?float
    {
        return $this->tasa_clics === null ? null : round($this->tasa_clics * 100, 2);
    }

    /**
     * Una consulta que se vuelve a ver en informes posteriores sigue indexada. Es el dato que
     * dice si la limpieza funcionó o si el contenido inyectado nunca se llegó a quitar.
     */
    public function persisteTrasLimpieza(): bool
    {
        return $this->veces_vista > 1
            && $this->veredicto === self::VEREDICTO_ENVENENADA;
    }
}
