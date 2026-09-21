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
        // Tabla puente: permite abrir una alerta y recorrer uno por uno los eventos que la
        // dispararon, que es lo que pide el auditor cuando duda de un hallazgo.
        Schema::create('alerta_evento', function (Blueprint $table) {
            $table->foreignId('alerta_seguridad_id')->constrained('alertas_seguridad')->cascadeOnDelete();
            $table->foreignId('evento_seguridad_id')->constrained('eventos_seguridad')->cascadeOnDelete();

            $table->primary(['alerta_seguridad_id', 'evento_seguridad_id'], 'alerta_evento_primaria');
            $table->index('evento_seguridad_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alerta_evento');
    }
};
