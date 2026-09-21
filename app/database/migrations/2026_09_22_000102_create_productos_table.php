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
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('categoria_id')->constrained('categorias')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('nombre', 160);
            $table->string('slug', 180)->unique();
            $table->text('descripcion');
            // Decimal y no float: en moneda un error de redondeo de centavos es un hallazgo de auditoria.
            $table->decimal('precio', 10, 2);
            $table->unsignedInteger('existencias')->default(0);
            $table->string('imagen_url', 500)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            // El catalogo siempre filtra por activo y casi siempre por categoria; este indice
            // compuesto cubre esa consulta sin recorrer la tabla completa.
            $table->index(['activo', 'categoria_id']);
            $table->index('precio');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
