<?php

namespace App\Models;

use Database\Factories\ProductoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Producto del catalogo de MarketGT.
 *
 * @property int $id
 * @property int $categoria_id
 * @property string $nombre
 * @property string $slug
 * @property string $descripcion
 * @property string $precio
 * @property int $existencias
 * @property string|null $imagen_url
 * @property bool $activo
 * @property-read Categoria $categoria
 */
class Producto extends Model
{
    /** @use HasFactory<ProductoFactory> */
    use HasFactory;

    protected $table = 'productos';

    protected $fillable = [
        'categoria_id',
        'nombre',
        'slug',
        'descripcion',
        'precio',
        'existencias',
        'imagen_url',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            // Cadena y no float: el punto flotante binario no representa exactamente los
            // centavos y un total mal redondeado es un hallazgo de auditoria contable.
            'precio' => 'decimal:2',
            'existencias' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected static function booted(): void
    {
        static::saving(function (Producto $producto): void {
            if (blank($producto->slug)) {
                $producto->slug = Str::slug($producto->nombre);
            }
        });
    }

    /** @return BelongsTo<Categoria, $this> */
    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    /** @param  Builder<Producto>  $consulta */
    public function scopeDisponibles(Builder $consulta): void
    {
        $consulta->where('activo', true);
    }

    public function hayExistencias(): bool
    {
        return $this->existencias > 0;
    }

    public function precioFormateado(): string
    {
        return self::quetzales($this->precio);
    }

    /**
     * Punto unico de formato monetario de la tienda. El profesor revisa que todos los
     * montos aparezcan igual (Q1,250.00) en catalogo, carrito y comprobante.
     */
    public static function quetzales(float|int|string|null $monto): string
    {
        return 'Q'.number_format((float) ($monto ?? 0), 2, '.', ',');
    }

    /**
     * Color estable derivado del slug para el mosaico de portada cuando el producto aun
     * no tiene fotografia. Depender del slug y no de rand() evita que la tarjeta cambie
     * de color en cada recarga.
     */
    public function tonoPortada(): string
    {
        $paleta = [
            'from-emerald-500/25 to-teal-600/25',
            'from-amber-500/25 to-orange-600/25',
            'from-sky-500/25 to-indigo-600/25',
            'from-rose-500/25 to-pink-600/25',
            'from-violet-500/25 to-purple-600/25',
            'from-lime-500/25 to-green-600/25',
        ];

        return $paleta[crc32($this->slug) % count($paleta)];
    }

    public function iniciales(): string
    {
        return Str::upper(Str::substr((string) Str::of($this->nombre)->ascii()->trim(), 0, 2));
    }
}
