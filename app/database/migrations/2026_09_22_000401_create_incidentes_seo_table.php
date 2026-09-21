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
        Schema::create('incidentes_seo', function (Blueprint $table) {
            $table->id();

            // Familia del ataque de posicionamiento. Se guarda como cadena corta y no como
            // enumeracion de base de datos: anadir una familia nueva no puede exigir un
            // ALTER TABLE en medio de una demostracion.
            $table->string('tipo', 40);
            $table->string('severidad', 20)->default('media');

            // Regla hermana en el WAF (15021 -> APP-15021). Es la columna que permite poner
            // lado a lado lo que vio ModSecurity y lo que vio la aplicacion sobre el mismo hecho.
            $table->string('regla', 20)->nullable();

            $table->string('resumen', 255);

            $table->string('direccion_ip', 45)->nullable();
            $table->text('agente_usuario')->nullable();
            $table->string('metodo', 10)->nullable();
            $table->text('ruta')->nullable();

            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();

            // Evidencia cruda: PTR obtenido, huellas comparadas, fragmento del contenido
            // marcado. Sin la evidencia el incidente es una afirmacion, no un hallazgo.
            $table->json('detalle')->nullable();

            $table->string('estado', 20)->default('nuevo');

            // Un rastreador falsificado insiste cientos de veces por minuto. Sin agregacion,
            // la tabla del panel se vuelve ilegible justo cuando mas hay que leerla.
            $table->unsignedInteger('repeticiones')->default(1);
            $table->timestamp('primera_vez_en');
            $table->timestamp('ultima_vez_en');

            // SHA-256 de (tipo + origen + dia). Es lo que convierte la insistencia del
            // atacante en una sola fila con contador, en vez de en mil filas iguales.
            $table->char('huella', 64)->nullable()->unique();

            $table->foreignId('revisado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revisado_en')->nullable();
            $table->text('notas')->nullable();

            $table->timestamps();

            $table->index(['tipo', 'ultima_vez_en']);
            $table->index(['severidad', 'ultima_vez_en']);
            $table->index(['estado', 'ultima_vez_en']);
            $table->index(['direccion_ip', 'ultima_vez_en']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incidentes_seo');
    }
};
