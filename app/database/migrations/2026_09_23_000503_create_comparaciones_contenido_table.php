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
        Schema::create('comparaciones_contenido', function (Blueprint $table) {
            $table->id();

            // La direccion tal como la escribio el operador y la forma normalizada con la
            // que se pidio de verdad. Se guardan las dos: la primera es lo que hay que
            // reproducir en una auditoria, la segunda es por la que se agrupa el historial.
            $table->string('url', 500);
            $table->string('url_normalizada', 500);
            $table->string('dominio', 255);

            $table->unsignedSmallInteger('puntuacion')->default(0);
            $table->string('veredicto', 30);

            // Lo que devolvio cada identificacion: codigo, redirecciones, titulo, enlaces y
            // una muestra del texto. Es la evidencia lado a lado de la pantalla, y tiene que
            // sobrevivir al cierre del navegador: sin ella el veredicto es una palabra.
            $table->json('perfiles')->nullable();

            // Que senal se activo en cada comparacion, con sus puntos y con las dos caras de
            // la diferencia. Sin esto la fila dice "cloaking" y no dice por que, que en una
            // auditoria vale lo mismo que no decir nada.
            $table->json('comparaciones')->nullable();

            $table->json('resumen')->nullable();

            // Cuantas identificaciones contestaron. Un veredicto limpio con tres perfiles
            // caidos NO es un veredicto limpio, y la pantalla tiene que poder decirlo.
            $table->unsignedTinyInteger('perfiles_alcanzados')->default(0);
            $table->unsignedTinyInteger('perfiles_fallidos')->default(0);
            $table->unsignedInteger('duracion_ms')->nullable();

            // SHA-256 de la direccion normalizada. NO lleva el dia dentro y NO es unica: la
            // gracia de esta tabla es tener varias filas de la misma direccion en fechas
            // distintas, porque el cloaking se limpia y vuelve, y demostrar que volvio exige
            // conservar las dos mediciones.
            $table->char('huella', 64);

            $table->foreignId('incidente_seo_id')->nullable()->constrained('incidentes_seo')->nullOnDelete();
            $table->foreignId('ejecutada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notas')->nullable();

            $table->timestamps();

            // Los dos ordenes en los que se lee: la linea de tiempo de una direccion y el
            // repaso general de lo ultimo que se encontro.
            $table->index(['huella', 'created_at']);
            $table->index(['veredicto', 'created_at']);
            $table->index('dominio');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comparaciones_contenido');
    }
};
