<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Estado autorizado de un artefacto de indexación.
 *
 * La linea base se guarda en la base de datos y no en la caché por una razón de auditoría:
 * la caché se vacía con un comando y nadie se entera. Si el estado autorizado de robots.txt
 * puede desaparecer sin dejar rastro, el control de integridad se puede desactivar sin
 * dejar rastro, y entonces no es un control: es un adorno.
 *
 * @property int $id
 * @property string $artefacto
 * @property string|null $url
 * @property string $huella
 * @property array<string, mixed>|null $resumen
 * @property int|null $sellada_por
 * @property Carbon $sellada_en
 * @property string|null $notas
 * @property-read User|null $firmante
 */
class LineaBaseSeo extends Model
{
    protected $table = 'lineas_base_seo';

    public const ARTEFACTO_ROBOTS = 'robots.txt';

    public const ARTEFACTO_SITEMAP = 'sitemap.xml';

    /** Prefijo de los artefactos que son páginas: "pagina:/tienda". */
    public const PREFIJO_PAGINA = 'pagina:';

    protected $fillable = [
        'artefacto',
        'url',
        'huella',
        'resumen',
        'sellada_por',
        'sellada_en',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resumen' => 'array',
            'sellada_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function firmante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sellada_por');
    }

    public function esPagina(): bool
    {
        return str_starts_with($this->artefacto, self::PREFIJO_PAGINA);
    }

    public function etiqueta(): string
    {
        return $this->esPagina()
            ? 'Página '.substr($this->artefacto, strlen(self::PREFIJO_PAGINA))
            : $this->artefacto;
    }

    /**
     * Huella recortada para la pantalla. Ocho caracteres bastan para comparar a simple
     * vista durante la demostración y el valor completo sigue disponible en la columna.
     */
    public function huellaCorta(): string
    {
        return substr($this->huella, 0, 8);
    }
}
