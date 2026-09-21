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
        Schema::create('registros_auditoria', function (Blueprint $table) {
            $table->id();

            // Cadena libre y no enumeración de base de datos: cada componente del
            // proyecto añade sus propias acciones sin migrar la tabla. La lista
            // viva de acciones se documenta en App\Services\Seguridad\Auditor.
            $table->string('accion', 80);

            // Sobre qué se actuó, en forma polimórfica ligera. No se usa una
            // relación polimórfica de Eloquent porque el registro debe sobrevivir
            // al borrado de la fila original: si el pedido desaparece, el asiento
            // de auditoría que dice que alguien lo borró tiene que quedarse.
            $table->string('tipo_recurso', 80)->nullable();
            $table->string('identificador_recurso', 64)->nullable();

            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();

            // Se copia el correo además de la clave foránea porque la clave se
            // anula si la cuenta se elimina, y entonces el asiento perdería a su
            // protagonista justo en el caso que más importa investigar.
            $table->string('correo', 255)->nullable();

            $table->string('direccion_ip', 45)->nullable();
            $table->text('agente_usuario')->nullable();

            $table->string('metodo', 10)->nullable();
            $table->text('ruta')->nullable();
            $table->unsignedSmallInteger('codigo_respuesta')->nullable();

            $table->string('resultado', 20)->default('exito');

            // Detalle censurado: nunca contraseñas, códigos de recuperación ni
            // secretos del segundo factor. Lo garantiza el middleware, no la
            // buena voluntad de quien llama.
            $table->json('detalle')->nullable();

            $table->timestamps();

            // Las consultas de auditoría son casi siempre "qué hizo esta cuenta"
            // o "qué pasó en esta ventana de tiempo".
            $table->index(['usuario_id', 'created_at']);
            $table->index(['accion', 'created_at']);
            $table->index(['direccion_ip', 'created_at']);
            $table->index(['tipo_recurso', 'identificador_recurso']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registros_auditoria');
    }
};
