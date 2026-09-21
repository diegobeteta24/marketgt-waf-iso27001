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
        Schema::create('lineas_pedido', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->cascadeOnUpdate()->cascadeOnDelete();
            // El producto puede darse de baja del catalogo anos despues; el pedido historico
            // no debe romperse, por eso la relacion queda en nulo y el nombre y el precio
            // viajan copiados en la propia linea.
            $table->foreignId('producto_id')->nullable()->constrained('productos')->cascadeOnUpdate()->nullOnDelete();
            $table->string('nombre_producto', 160);
            $table->decimal('precio_unitario', 10, 2);
            $table->unsignedInteger('cantidad');
            $table->decimal('subtotal', 10, 2);
            $table->timestamps();

            $table->index('pedido_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lineas_pedido');
    }
};
