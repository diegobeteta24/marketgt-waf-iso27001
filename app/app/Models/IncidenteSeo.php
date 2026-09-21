<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Incidente de posicionamiento detectado por la capa 5.
 *
 * Esta tabla existe porque el WAF no puede llegar a donde llega la aplicacion. ModSecurity
 * ve la peticion entrante y la corta; no puede resolver un DNS inverso, no sabe que reseña
 * quedo marcada para revision y no se entera de que alguien reescribio robots.txt por SSH.
 * Cada fila de aqui es una deteccion que solo podia nacer dentro de Laravel, y lleva la
 * regla hermana del WAF para poder ponerlas lado a lado en el SIEM.
 *
 * @property int $id
 * @property string $tipo
 * @property string $severidad
 * @property string|null $regla
 * @property string $resumen
 * @property string|null $direccion_ip
 * @property string|null $agente_usuario
 * @property string|null $metodo
 * @property string|null $ruta
 * @property int|null $usuario_id
 * @property array<string, mixed>|null $detalle
 * @property string $estado
 * @property int $repeticiones
 * @property Carbon $primera_vez_en
 * @property Carbon $ultima_vez_en
 * @property string|null $huella
 * @property int|null $revisado_por
 * @property Carbon|null $revisado_en
 * @property string|null $notas
 * @property-read User|null $usuario
 * @property-read User|null $revisor
 */
class IncidenteSeo extends Model
{
    protected $table = 'incidentes_seo';

    public const TIPO_CRAWLER_FALSIFICADO = 'crawler_falsificado';

    public const TIPO_CLOAKING = 'cloaking_respuesta';

    public const TIPO_CONTENIDO_SPAM = 'contenido_spam';

    public const TIPO_REDIRECCION_BLOQUEADA = 'redireccion_bloqueada';

    public const TIPO_INTEGRIDAD = 'integridad_artefacto';

    public const TIPO_SITEMAP_AJENO = 'sitemap_contaminado';

    public const ESTADO_NUEVO = 'nuevo';

    public const ESTADO_CONFIRMADO = 'confirmado';

    public const ESTADO_FALSO_POSITIVO = 'falso_positivo';

    public const ESTADO_CERRADO = 'cerrado';

    /**
     * Etiquetas en español para el panel. Viven en el modelo y no en la vista porque el
     * comando de consola escribe las mismas palabras en la terminal.
     *
     * @var array<string, string>
     */
    public const ETIQUETAS_TIPO = [
        self::TIPO_CRAWLER_FALSIFICADO => 'Rastreador falsificado',
        self::TIPO_CLOAKING => 'Cloaking',
        self::TIPO_CONTENIDO_SPAM => 'Contenido marcado como spam',
        self::TIPO_REDIRECCION_BLOQUEADA => 'Redirección abierta bloqueada',
        self::TIPO_INTEGRIDAD => 'Integridad de artefacto de indexación',
        self::TIPO_SITEMAP_AJENO => 'Sitemap contaminado',
    ];

    /**
     * @var array<string, string>
     */
    public const ETIQUETAS_ESTADO = [
        self::ESTADO_NUEVO => 'Nuevo',
        self::ESTADO_CONFIRMADO => 'Confirmado',
        self::ESTADO_FALSO_POSITIVO => 'Falso positivo',
        self::ESTADO_CERRADO => 'Cerrado',
    ];

    /**
     * Misma escala que eventos_seguridad. Dos escalas distintas en el mismo sistema
     * obligarian al analista a traducir de cabeza durante un incidente real.
     *
     * @var array<int, string>
     */
    public const ESCALA_SEVERIDAD = ['critica', 'alta', 'media', 'baja', 'informativa'];

    protected $fillable = [
        'tipo',
        'severidad',
        'regla',
        'resumen',
        'direccion_ip',
        'agente_usuario',
        'metodo',
        'ruta',
        'usuario_id',
        'detalle',
        'estado',
        'repeticiones',
        'primera_vez_en',
        'ultima_vez_en',
        'huella',
        'revisado_por',
        'revisado_en',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'detalle' => 'array',
            'primera_vez_en' => 'datetime',
            'ultima_vez_en' => 'datetime',
            'revisado_en' => 'datetime',
            'repeticiones' => 'integer',
        ];
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
    public function revisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisado_por');
    }

    /**
     * @param  Builder<$this>  $consulta
     * @return Builder<$this>
     */
    public function scopeAbiertos(Builder $consulta): Builder
    {
        return $consulta->whereIn('estado', [self::ESTADO_NUEVO, self::ESTADO_CONFIRMADO]);
    }

    /**
     * @param  Builder<$this>  $consulta
     * @return Builder<$this>
     */
    public function scopeDeTipo(Builder $consulta, string $tipo): Builder
    {
        return $consulta->where('tipo', $tipo);
    }

    /**
     * @param  Builder<$this>  $consulta
     * @return Builder<$this>
     */
    public function scopeDesde(Builder $consulta, Carbon $momento): Builder
    {
        return $consulta->where('ultima_vez_en', '>=', $momento);
    }

    public function etiquetaTipo(): string
    {
        return self::ETIQUETAS_TIPO[$this->tipo] ?? $this->tipo;
    }

    public function etiquetaEstado(): string
    {
        return self::ETIQUETAS_ESTADO[$this->estado] ?? $this->estado;
    }

    /**
     * Un incidente que se repite sin parar no es "el mismo incidente": es una campaña en
     * curso. El panel lo destaca para que el analista no lo confunda con un caso aislado.
     */
    public function esCampanaActiva(): bool
    {
        return $this->repeticiones >= 10
            && $this->ultima_vez_en->greaterThan(now()->subMinutes(15));
    }
}
