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
        Schema::create('roles', function (Blueprint $table) {
            $table->id();

            // El nombre viaja tal cual en el middleware ("rol:auditor"), por eso
            // es único y corto. Si dos filas pudieran llamarse igual, conceder un
            // rol dejaría de ser una operación determinista.
            $table->string('nombre', 40)->unique();

            $table->string('etiqueta', 80);
            $table->string('descripcion', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('rol_usuario', function (Blueprint $table) {
            $table->id();

            $table->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('rol_id')->constrained('roles')->cascadeOnDelete();

            // Quién concedió el rol y cuándo. Sin esto, la pregunta de auditoría
            // "¿quién le dio permisos de administrador a esta cuenta?" no tiene
            // respuesta posible, y esa pregunta se hace siempre después de un
            // incidente, cuando ya nadie recuerda.
            $table->foreignId('asignado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('asignado_en')->nullable();

            $table->timestamps();

            // Una concesión por par usuario-rol: evita que revocar un rol deje
            // una segunda fila viva y el permiso siga en pie.
            $table->unique(['usuario_id', 'rol_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rol_usuario');
        Schema::dropIfExists('roles');
    }
};
