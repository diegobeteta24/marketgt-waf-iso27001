<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linea de un carrito: un producto y su cantidad.
 *
 * @property int $id
 * @property int $carrito_id
 * @property int $producto_id
 * @property int $cantidad
 * @property string $precio_unitario
 * @property-read Producto $producto
 */
class LineaCarrito extends Model
{
    protected $table = 'lineas_carrito';

    protected $fillable = [
        'carrito_id',
        'producto_id',
        'cantidad',
        'precio_unitario',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'precio_unitario' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Carrito, $this> */
    public function carrito(): BelongsTo
    {
        return $this->belongsTo(Carrito::class, 'carrito_id');
    }

    /** @return BelongsTo<Producto, $this> */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function subtotal(): float
    {
        return round((float) $this->precio_unitario * $this->cantidad, 2);
    }

    public function subtotalFormateado(): string
    {
        return Producto::quetzales($this->subtotal());
    }

    public function precioFormateado(): string
    {
        return Producto::quetzales($this->precio_unitario);
    }
}
