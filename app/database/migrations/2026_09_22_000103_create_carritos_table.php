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
        Schema::create('carritos', function (Blueprint $table) {
            $table->id();
            // Un carrito pertenece a un usuario autenticado o, si aun no inicia sesion, a un
            // testigo de sesion. Nunca a los dos a la vez.
            $table->foreignId('usuario_id')->nullable()->constrained('users')->cascadeOnUpdate()->cascadeOnDelete();
            // Testigo aleatorio guardado en la sesion del navegador. Es opaco a proposito:
            // no se deriva del identificador de sesion ni de datos del visitante, de modo que
            // conocerlo no permite deducir ningun otro dato del cliente.
            $table->char('testigo_sesion', 36)->nullable()->unique();
            $table->timestamps();

            $table->index('usuario_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carritos');
    }
};
