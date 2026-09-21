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
        Schema::create('eventos_seguridad', function (Blueprint $table) {
            $table->id();

            // Tres fuentes conviven en la misma tabla para poder correlacionar entre capas:
            // un 403 del WAF y un fallo de contrasena de la aplicacion son el mismo ataque.
            $table->string('fuente', 20);
            $table->string('subfuente', 40)->nullable();

            $table->timestamp('marca_tiempo');
            $table->string('direccion_ip', 45);

            // Se rellena con el encabezado CF-IPCountry que inyecta Cloudflare en la capa 1.
            // Si la peticion no paso por Cloudflare queda nulo: no se adivina.
            $table->char('pais', 2)->nullable();

            $table->string('metodo', 10)->nullable();
            $table->text('ruta')->nullable();
            $table->unsignedSmallInteger('codigo_respuesta')->nullable();

            // unique_id de ModSecurity: permite volver al registro crudo del WAF durante una auditoria.
            $table->string('identificador_transaccion', 64)->nullable();

            $table->json('identificadores_regla')->nullable();
            $table->unsignedSmallInteger('puntuacion_anomalia')->default(0);
            $table->string('severidad', 20)->default('informativa');
            $table->json('etiquetas')->nullable();
            $table->text('mensaje')->nullable();
            $table->longText('carga_util')->nullable();
            $table->boolean('fue_bloqueado')->default(false);

            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('agente_usuario')->nullable();

            // Distingue los eventos del semillero de demostracion de los que produjo el WAF real.
            $table->boolean('es_demostracion')->default(false);

            // SHA-256 de los campos identificadores. Hace la ingesta idempotente: si el archivo
            // de auditoria se rota o se reprocesa por error, la linea repetida no duplica el evento.
            $table->char('huella', 64)->nullable()->unique();

            $table->timestamps();

            // El panel consulta casi siempre por ventana de tiempo y luego filtra por IP o severidad.
            $table->index('marca_tiempo');
            $table->index(['direccion_ip', 'marca_tiempo']);
            $table->index(['severidad', 'marca_tiempo']);
            $table->index(['fue_bloqueado', 'marca_tiempo']);
            $table->index(['fuente', 'marca_tiempo']);
            $table->index('identificador_transaccion');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('eventos_seguridad');
    }
};
