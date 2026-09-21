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
        Schema::create('alertas_seguridad', function (Blueprint $table) {
            $table->id();

            $table->foreignId('regla_correlacion_id')->nullable()
                ->constrained('reglas_correlacion')->nullOnDelete();

            // Se guarda tambien la clave suelta: si manana se borra la regla, la alerta historica
            // sigue diciendo que la detecto. Un registro de auditoria no puede quedar huerfano.
            $table->string('clave_regla', 64);

            $table->string('titulo');
            $table->text('descripcion');
            $table->string('severidad', 20);

            // nueva | en_triaje | contenida | cerrada | falso_positivo
            $table->string('estado', 20)->default('nueva');

            $table->string('direccion_ip', 45)->nullable();
            $table->foreignId('usuario_objetivo_id')->nullable()->constrained('users')->nullOnDelete();

            // primer_evento_en es el inicio real del ataque y detectada_en el momento en que el
            // motor lo vio. La resta de ambos es el tiempo medio de deteccion que exige la metrica.
            $table->timestamp('primer_evento_en')->nullable();
            $table->timestamp('detectada_en');

            // confirmada_en se sella al salir de "nueva" hacia un estado que afirma que es real.
            // contenida_en menos confirmada_en es el tiempo medio de contencion.
            $table->timestamp('confirmada_en')->nullable();
            $table->timestamp('contenida_en')->nullable();
            $table->timestamp('cerrada_en')->nullable();

            $table->foreignId('atendida_por')->nullable()->constrained('users')->nullOnDelete();

            // Copia inmutable de la evidencia en el instante de la deteccion. La relacion con
            // eventos_seguridad sirve para navegar; esta copia sirve para sostener el hallazgo
            // aunque los eventos se depuren por retencion.
            $table->json('evidencia');

            $table->text('accion_recomendada');
            $table->text('notas_triaje')->nullable();

            $table->unsignedInteger('conteo_eventos')->default(0);

            // Evita que la misma rafaga genere una alerta por cada pasada del motor:
            // clave de regla + objetivo + cubo de tiempo de la ventana.
            $table->string('huella_agrupacion', 160)->unique();

            $table->boolean('es_demostracion')->default(false);

            $table->timestamps();

            $table->index(['estado', 'detectada_en']);
            $table->index(['severidad', 'detectada_en']);
            $table->index('clave_regla');
            $table->index('direccion_ip');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alertas_seguridad');
    }
};
