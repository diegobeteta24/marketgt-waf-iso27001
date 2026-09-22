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
        Schema::create('consultas_sospechosas', function (Blueprint $table) {
            $table->id();

            // La consulta tal como la informa Google, y su forma normalizada (minusculas,
            // sin acentos). Se guardan las dos: la primera es la evidencia que se ensena, la
            // segunda es por la que se compara, y mezclarlas haria que "Camaras" y "camaras"
            // fueran dos hallazgos distintos.
            $table->string('consulta', 255);
            $table->string('consulta_normalizada', 255);

            // Los datos que da Search Console. Nulos a proposito: quien pega una lista de
            // consultas sin columnas tambien tiene derecho a analizarla, y un cero fingido
            // activaria la senal de desproporcion sin que nadie haya medido nada.
            $table->unsignedInteger('clics')->nullable();
            $table->unsignedInteger('impresiones')->nullable();
            $table->decimal('tasa_clics', 6, 4)->nullable();

            $table->unsignedSmallInteger('puntuacion')->default(0);
            $table->string('veredicto', 20)->default('revisar');

            // Que senales se activaron, con sus puntos y su evidencia. Sin esto la fila dice
            // "sospechosa" y no dice por que, que es lo mismo que no decir nada.
            $table->json('senales')->nullable();

            // El vocabulario del negocio con el que se juzgo. El veredicto depende de el, de
            // modo que sin guardarlo la decision no se puede reproducir en una auditoria.
            $table->json('vocabulario')->nullable();

            $table->string('estado', 20)->default('nueva');

            // Cuantos informes distintos han traido esta misma consulta. Es lo que responde
            // la pregunta de despues de limpiar: si sigue subiendo, sigue indexada.
            $table->unsignedInteger('veces_vista')->default(1);
            $table->timestamp('primera_vez_en');
            $table->timestamp('ultima_vez_en');

            // SHA-256 de la consulta normalizada. Unica de verdad, sin el dia dentro: la
            // misma consulta analizada en marzo y en abril es UNA fila con contador, porque
            // lo que interesa es si sigue apareciendo, no cuantas veces se pego el informe.
            $table->char('huella', 64)->unique();

            $table->foreignId('incidente_seo_id')->nullable()->constrained('incidentes_seo')->nullOnDelete();
            $table->foreignId('analizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notas')->nullable();

            $table->timestamps();

            // Los tres ordenes en los que se lee el panel: por gravedad, por novedad y por
            // estado de la revision.
            $table->index(['veredicto', 'puntuacion']);
            $table->index(['estado', 'ultima_vez_en']);
            $table->index('ultima_vez_en');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consultas_sospechosas');
    }
};
