<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Evidencia de una dirección declarada en el mapa del sitio o de una directiva del archivo
 * de exclusión de rastreadores.
 *
 * POR QUE ESTA TABLA NO ES incidentes_seo
 * ---------------------------------------------------------------------------
 * Un incidente es "el mapa de este sitio esta contaminado": una afirmacion, con su
 * severidad y su estado de revision. Esta tabla es la PRUEBA de esa afirmacion, direccion
 * por direccion, con su puntuacion, sus motivos y el codigo que devolvio el servidor.
 *
 * Separarlas no es coleccionar tablas. Una campana que inyecta doscientas paginas tiene que
 * ser UN incidente en el panel —si no, el panel se vuelve ilegible justo cuando mas hay que
 * leerlo— y doscientas filas de evidencia aqui, porque cuando el auditor pregunta "¿cuales?"
 * la respuesta no puede ser "muchas". Sin la evidencia, el hallazgo es una opinion.
 *
 * Cada fila lleva el identificador de la corrida que la produjo, de modo que se puede
 * mostrar una auditoria concreta sin mezclarla con la del dia anterior, y comparar dos.
 *
 * @property int $id
 * @property string $ejecucion
 * @property string $sitio
 * @property string $tipo
 * @property string|null $url
 * @property string|null $origen
 * @property string $veredicto
 * @property int $puntuacion
 * @property array<int, array{regla: string, descripcion: string, puntos: int, evidencia: string}>|null $motivos
 * @property int|null $profundidad
 * @property Carbon|null $fecha_declarada
 * @property int|null $codigo_http
 * @property string|null $titulo_remoto
 * @property string|null $destino_final
 * @property Carbon|null $comprobada_en
 * @property int|null $incidente_id
 * @property-read IncidenteSeo|null $incidente
 */
class HallazgoMapaSitio extends Model
{
    protected $table = 'hallazgos_mapa_sitio';

    /** Direccion declarada dentro de un mapa del sitio. */
    public const TIPO_DIRECCION = 'direccion_declarada';

    /** Directiva del archivo de exclusion de rastreadores (robots.txt). */
    public const TIPO_EXCLUSION = 'directiva_exclusion';

    public const VEREDICTO_LIMPIA = 'limpia';

    public const VEREDICTO_SOSPECHOSA = 'sospechosa';

    public const VEREDICTO_ANOMALA = 'anomala';

    /** Anomala y ademas viva: el servidor la sirve con codigo 200 ahora mismo. */
    public const VEREDICTO_INYECTADA = 'inyectada';

    /** Anomala y ademas inexistente: el mapa declara al buscador paginas que ya no estan. */
    public const VEREDICTO_FANTASMA = 'fantasma';

    /**
     * Etiquetas en español. Viven en el modelo y no en la vista porque el comando de
     * consola escribe exactamente las mismas palabras en la terminal, y dos vocabularios
     * distintos para lo mismo obligan al analista a traducir de cabeza.
     *
     * @var array<string, string>
     */
    public const ETIQUETAS_VEREDICTO = [
        self::VEREDICTO_LIMPIA => 'Limpia',
        self::VEREDICTO_SOSPECHOSA => 'Sospechosa',
        self::VEREDICTO_ANOMALA => 'Anómala',
        self::VEREDICTO_INYECTADA => 'Inyectada y viva',
        self::VEREDICTO_FANTASMA => 'Declarada e inexistente',
    ];

    /**
     * @var array<string, string>
     */
    public const ETIQUETAS_TIPO = [
        self::TIPO_DIRECCION => 'Dirección del mapa',
        self::TIPO_EXCLUSION => 'Directiva de exclusión',
    ];

    /**
     * Veredictos que obligan a actuar. Se declaran aqui y no en cada consulta porque "que
     * es grave" es una politica del proyecto, no una decision de quien escribe el filtro.
     *
     * @var array<int, string>
     */
    public const VEREDICTOS_GRAVES = [
        self::VEREDICTO_ANOMALA,
        self::VEREDICTO_INYECTADA,
        self::VEREDICTO_FANTASMA,
    ];

    protected $fillable = [
        'ejecucion',
        'sitio',
        'tipo',
        'url',
        'origen',
        'veredicto',
        'puntuacion',
        'motivos',
        'profundidad',
        'fecha_declarada',
        'codigo_http',
        'titulo_remoto',
        'destino_final',
        'comprobada_en',
        'incidente_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'motivos' => 'array',
            'fecha_declarada' => 'datetime',
            'comprobada_en' => 'datetime',
            'puntuacion' => 'integer',
            'profundidad' => 'integer',
            'codigo_http' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<IncidenteSeo, $this>
     */
    public function incidente(): BelongsTo
    {
        return $this->belongsTo(IncidenteSeo::class, 'incidente_id');
    }

    /**
     * @param  Builder<$this>  $consulta
     * @return Builder<$this>
     */
    public function scopeGraves(Builder $consulta): Builder
    {
        return $consulta->whereIn('veredicto', self::VEREDICTOS_GRAVES);
    }

    /**
     * @param  Builder<$this>  $consulta
     * @return Builder<$this>
     */
    public function scopeDeEjecucion(Builder $consulta, string $ejecucion): Builder
    {
        return $consulta->where('ejecucion', $ejecucion);
    }

    /**
     * @param  Builder<$this>  $consulta
     * @return Builder<$this>
     */
    public function scopeDelSitio(Builder $consulta, string $sitio): Builder
    {
        return $consulta->where('sitio', $sitio);
    }

    public function etiquetaVeredicto(): string
    {
        return self::ETIQUETAS_VEREDICTO[$this->veredicto] ?? $this->veredicto;
    }

    public function etiquetaTipo(): string
    {
        return self::ETIQUETAS_TIPO[$this->tipo] ?? $this->tipo;
    }

    public function esGrave(): bool
    {
        return in_array($this->veredicto, self::VEREDICTOS_GRAVES, true);
    }

    /**
     * Nombres de las reglas que dispararon, para poner en una celda estrecha sin tener que
     * desplegar el detalle completo.
     *
     * @return array<int, string>
     */
    public function reglas(): array
    {
        return array_values(array_map(
            static fn (array $motivo): string => (string) ($motivo['regla'] ?? 'desconocida'),
            $this->motivos ?? [],
        ));
    }

    /**
     * Parte de la direccion que se puede leer en pantalla sin romper la tabla. Se conserva
     * el final y no el principio: el prefijo de todas las direcciones del sitio es igual y
     * lo que distingue a la inyectada esta siempre al final.
     */
    public function urlCorta(int $maximo = 70): string
    {
        $url = (string) $this->url;

        if (mb_strlen($url) <= $maximo) {
            return $url;
        }

        return '…'.mb_substr($url, -($maximo - 1));
    }
}
