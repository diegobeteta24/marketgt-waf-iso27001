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
        Schema::create('reglas_correlacion', function (Blueprint $table) {
            $table->id();

            // La clave es el contrato con el motor: el codigo busca la regla por clave,
            // el auditor ajusta umbral y ventana desde la base sin tocar PHP.
            $table->string('clave', 64)->unique();

            $table->string('nombre');
            $table->text('descripcion');
            $table->string('severidad', 20);

            $table->unsignedInteger('umbral');
            $table->unsignedInteger('ventana_minutos');

            // Sin accion recomendada una alerta es solo ruido: el analista no sabria que hacer.
            $table->text('accion_recomendada');

            $table->json('parametros')->nullable();
            $table->boolean('activa')->default(true);
            $table->text('justificacion_umbral')->nullable();

            $table->timestamps();

            $table->index('activa');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reglas_correlacion');
    }
};
