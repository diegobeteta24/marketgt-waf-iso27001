<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una comparación de contenido diferenciado sobre una dirección concreta.
 *
 * POR QUÉ SE GUARDA CADA COMPARACIÓN Y NO SOLO LA ÚLTIMA
 * ---------------------------------------------------------------------------
 * El cloaking se limpia y vuelve. Quien tiene acceso al servidor repone la inyección a los
 * pocos días, y si la herramienta solo conserva el último resultado, el día que vuelve a
 * salir limpio nadie puede demostrar que estuvo sucio. Cada ejecución es una fila con su
 * fecha: eso convierte la pantalla en un historial y permite responder la única pregunta
 * que importa después de limpiar, que es si volvió a aparecer.
 *
 * La huella agrupa por dirección, NO por día ni por resultado: todas las comparaciones de
 * https://ejemplo.gt/tienda comparten huella y se leen como una línea de tiempo.
 *
 * @property int $id
 * @property string $url
 * @property string $url_normalizada
 * @property string $dominio
 * @property int $puntuacion
 * @property string $veredicto
 * @property array<string, mixed>|null $perfiles
 * @property array<string, mixed>|null $comparaciones
 * @property array<string, mixed>|null $resumen
 * @property int $perfiles_alcanzados
 * @property int $perfiles_fallidos
 * @property int|null $duracion_ms
 * @property string $huella
 * @property int|null $incidente_seo_id
 * @property int|null $ejecutada_por
 * @property string|null $notas
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read IncidenteSeo|null $incidente
 * @property-read User|null $operador
 */
class ComparacionContenido extends Model
{
    protected $table = 'comparaciones_contenido';

    /** Ninguna señal se activó o todas quedaron por debajo del ruido normal de la página. */
    public const VEREDICTO_SIN_DIFERENCIAS = 'sin_diferencias';

    /**
     * Hay diferencias, pero son de las que un sitio honesto produce: diseño adaptado al
     * teléfono, renderizado previo para el buscador, negociación de idioma.
     */
    public const VEREDICTO_ESPERABLE = 'diferencias_esperables';

    /** Diferencias que no se explican solas. Requiere que una persona mire la página. */
    public const VEREDICTO_SOSPECHOSO = 'sospechoso';

    /**
     * Contenido diferenciado demostrado: el buscador recibe algo que la persona no recibe,
     * y lo que recibe de más pertenece a un sector de abuso o lleva a otro dominio.
     */
    public const VEREDICTO_CLOAKING = 'cloaking';

    /**
     * No se pudo comparar: el sitio no respondió a la versión de referencia. Se guarda
     * igual, porque "no respondió" también es un dato del historial, y porque un sitio que
     * deja de responder justo a la versión de navegador ya es raro de por sí.
     */
    public const VEREDICTO_INCOMPLETO = 'incompleto';

    /**
     * Etiquetas en español. Viven en el modelo y no en la vista porque el mismo texto se
     * escribe en el resumen del incidente que llega a la bitácora del SIEM.
     *
     * @var array<string, string>
     */
    public const ETIQUETAS_VEREDICTO = [
        self::VEREDICTO_SIN_DIFERENCIAS => 'Sin diferencias',
        self::VEREDICTO_ESPERABLE => 'Diferencias esperables',
        self::VEREDICTO_SOSPECHOSO => 'Sospechoso',
        self::VEREDICTO_CLOAKING => 'Cloaking confirmado',
        self::VEREDICTO_INCOMPLETO => 'Comparación incompleta',
    ];

    /**
     * Severidad equivalente en la escala de IncidenteSeo. Se declara aquí para que el
     * servicio no tenga que traducir a mano y para que las dos tablas hablen la misma
     * escala cuando el auditor las pone lado a lado.
     *
     * @var array<string, string>
     */
    public const SEVERIDAD_VEREDICTO = [
        self::VEREDICTO_SIN_DIFERENCIAS => 'informativa',
        self::VEREDICTO_ESPERABLE => 'baja',
        self::VEREDICTO_SOSPECHOSO => 'media',
        self::VEREDICTO_CLOAKING => 'critica',
        self::VEREDICTO_INCOMPLETO => 'informativa',
    ];

    protected $fillable = [
        'url',
        'url_normalizada',
        'dominio',
        'puntuacion',
        'veredicto',
        'perfiles',
        'comparaciones',
        'resumen',
        'perfiles_alcanzados',
        'perfiles_fallidos',
        'duracion_ms',
        'huella',
        'incidente_seo_id',
        'ejecutada_por',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'perfiles' => 'array',
            'comparaciones' => 'array',
            'resumen' => 'array',
            'puntuacion' => 'integer',
            'perfiles_alcanzados' => 'integer',
            'perfiles_fallidos' => 'integer',
            'duracion_ms' => 'integer',
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
    public function operador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ejecutada_por');
    }

    /**
     * Historial de una misma dirección, de lo más reciente a lo más antiguo.
     *
     * @param  Builder<$this>  $consulta
     * @return Builder<$this>
     */
    public function scopeDeHuella(Builder $consulta, string $huella): Builder
    {
        return $consulta->where('huella', $huella)->orderByDesc('created_at');
    }

    /**
     * @param  Builder<$this>  $consulta
     * @return Builder<$this>
     */
    public function scopeConHallazgo(Builder $consulta): Builder
    {
        return $consulta->whereIn('veredicto', [self::VEREDICTO_SOSPECHOSO, self::VEREDICTO_CLOAKING]);
    }

    public function etiquetaVeredicto(): string
    {
        return self::ETIQUETAS_VEREDICTO[$this->veredicto] ?? $this->veredicto;
    }

    public function severidad(): string
    {
        return self::SEVERIDAD_VEREDICTO[$this->veredicto] ?? 'media';
    }

    public function esConcluyente(): bool
    {
        return $this->veredicto === self::VEREDICTO_CLOAKING;
    }

    /**
     * Qué señales concluyentes se encontraron, en texto corto, para pintarlas en la tabla
     * del historial sin volver a abrir el detalle completo.
     *
     * @return array<int, string>
     */
    public function motivosConcluyentes(): array
    {
        $motivos = $this->resumen['concluyentes'] ?? [];

        return is_array($motivos) ? array_values(array_map(strval(...), $motivos)) : [];
    }
}
