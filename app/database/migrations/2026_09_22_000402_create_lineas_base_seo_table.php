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
        Schema::create('lineas_base_seo', function (Blueprint $table) {
            $table->id();

            // Identificador del artefacto vigilado: "robots.txt", "sitemap.xml" o
            // "pagina:/tienda". La clave es unica porque la linea base es el estado
            // autorizado: si hubiera dos, ninguna seria la verdad.
            $table->string('artefacto', 80)->unique();
            $table->text('url')->nullable();

            $table->char('huella', 64);

            // Contenido normalizado que produjo esa huella (titulo, canonico, meta robots,
            // encabezados y hosts enlazados). Guardarlo permite decir QUE cambio, y no solo
            // que algo cambio: un hash que no coincide, por si solo, no se puede investigar.
            $table->json('resumen')->nullable();

            // Quien autorizo el estado actual. Un control de integridad sin firma humana no
            // distingue un cambio legitimo del equipo de un cambio del atacante.
            $table->foreignId('sellada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sellada_en');
            $table->text('notas')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lineas_base_seo');
    }
};
