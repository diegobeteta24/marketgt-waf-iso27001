<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Categoria del catalogo de MarketGT.
 *
 * @property int $id
 * @property string $nombre
 * @property string $slug
 * @property string|null $descripcion
 * @property int $orden
 * @property bool $activa
 */
class Categoria extends Model
{
    protected $table = 'categorias';

    protected $fillable = [
        'nombre',
        'slug',
        'descripcion',
        'orden',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
            'orden' => 'integer',
        ];
    }

    /**
     * El catalogo publico enlaza las categorias por slug, nunca por id.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Genera el slug a partir del nombre cuando no se proporciona uno explicito.
     */
    protected static function booted(): void
    {
        static::saving(function (Categoria $categoria): void {
            if (blank($categoria->slug)) {
                $categoria->slug = Str::slug($categoria->nombre);
            }
        });
    }

    /** @return HasMany<Producto, $this> */
    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class, 'categoria_id');
    }

    /** @param  Builder<Categoria>  $consulta */
    public function scopeActivas(Builder $consulta): void
    {
        $consulta->where('activa', true)->orderBy('orden')->orderBy('nombre');
    }
}
