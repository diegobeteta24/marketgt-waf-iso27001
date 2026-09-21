<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lineas_carrito', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrito_id')->constrained('carritos')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnUpdate()->cascadeOnDelete();
            $table->unsignedInteger('cantidad')->default(1);
            // Precio congelado al momento de agregar. Si el catalogo sube de precio, el visitante
            // no ve cambiar su carrito a mitad de la compra.
            $table->decimal('precio_unitario', 10, 2);
            $table->timestamps();

            // Un producto ocupa una sola linea por carrito: agregar dos veces suma cantidad,
            // no duplica la fila. La base lo garantiza aunque la aplicacion falle.
            $table->unique(['carrito_id', 'producto_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lineas_carrito');
    }
};
