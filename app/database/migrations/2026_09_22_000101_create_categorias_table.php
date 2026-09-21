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
        Schema::create('categorias', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 120);
            // El slug viaja en la URL publica, por eso es unico: evita que dos categorias
            // distintas respondan a la misma direccion y confundan al rastreador de buscadores.
            $table->string('slug', 140)->unique();
            $table->string('descripcion', 255)->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->index(['activa', 'orden']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categorias');
    }
};
