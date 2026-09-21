<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linea de un pedido. Copia el nombre y el precio del producto en el momento de la compra
 * para que el comprobante siga siendo fiel aunque el catalogo cambie despues.
 *
 * @property int $id
 * @property int $pedido_id
 * @property int|null $producto_id
 * @property string $nombre_producto
 * @property string $precio_unitario
 * @property int $cantidad
 * @property string $subtotal
 */
class LineaPedido extends Model
{
    protected $table = 'lineas_pedido';

    protected $fillable = [
        'pedido_id',
        'producto_id',
        'nombre_producto',
        'precio_unitario',
        'cantidad',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'precio_unitario' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'cantidad' => 'integer',
        ];
    }

    /** @return BelongsTo<Pedido, $this> */
    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    /** @return BelongsTo<Producto, $this> */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function subtotalFormateado(): string
    {
        return Producto::quetzales($this->subtotal);
    }

    public function precioFormateado(): string
    {
        return Producto::quetzales($this->precio_unitario);
    }
}
