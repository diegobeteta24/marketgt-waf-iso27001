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
        // El marcador es lo que convierte la ingesta en incremental: sin el, cada ejecucion
        // volveria a leer el archivo de auditoria completo desde el byte cero.
        Schema::create('marcadores_ingesta', function (Blueprint $table) {
            $table->id();

            $table->string('clave', 64)->unique();
            $table->text('ruta_archivo');

            $table->unsignedBigInteger('desplazamiento')->default(0);

            // Tamano e inodo detectan la rotacion del registro: si el archivo encogio o cambio
            // de inodo, el desplazamiento guardado ya no apunta a donde creiamos y hay que volver a cero.
            $table->unsignedBigInteger('tamano_anterior')->default(0);
            $table->string('inodo', 64)->nullable();

            $table->unsignedBigInteger('lineas_procesadas')->default(0);
            $table->unsignedBigInteger('lineas_descartadas')->default(0);

            $table->timestamp('ultima_ejecucion')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marcadores_ingesta');
    }
};
