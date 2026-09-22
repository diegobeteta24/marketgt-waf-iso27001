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
        // Sin esta tabla, el RTO y el RPO solo se pueden afirmar leyendo la configuracion de
        // los respaldos, y la configuracion declara una intencion, no un hecho. Un auditor
        // no pregunta cada cuanto esta programado el respaldo: pregunta cuando fue la ultima
        // vez que alguien lo restauro y cuanto tardo. Eso es lo que se guarda aqui.
        Schema::create('pruebas_restauracion', function (Blueprint $table) {
            $table->id();

            $table->timestamp('iniciada_en');
            $table->timestamp('terminada_en')->nullable();

            // satisfactoria | fallida. Lo decide el comando a partir de los hechos medidos,
            // nunca el guion que los reporta: quien ejecuta la prueba no se califica solo.
            $table->string('resultado', 20)->default('fallida');
            $table->string('fase_fallida', 30)->nullable();
            $table->text('error')->nullable();

            // PROCEDENCIA. Es la mitad del valor del registro: una medicion sin origen no se
            // puede repetir ni refutar, y es lo primero que se pregunta en una auditoria.
            $table->string('origen', 20)->default('manual');
            $table->foreignId('ejecutada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor', 160);
            $table->string('anfitrion', 160)->nullable();
            $table->text('comando')->nullable();
            $table->string('modo_cliente', 80)->nullable();

            $table->string('conexion', 40);
            $table->string('base_origen', 120);

            // Se guarda tambien el nombre de la base de prueba para poder demostrar que la
            // restauracion NO se hizo sobre produccion. Es la primera objecion que levanta
            // cualquiera que lea el procedimiento.
            $table->string('base_prueba', 120);

            // Cada fase por separado. Sumar todo daria una cifra mayor que el RTO real:
            // volcar y cifrar pertenecen al respaldo, no a la recuperacion del servicio.
            $table->decimal('segundos_volcado', 12, 3)->nullable();
            $table->decimal('segundos_cifrado', 12, 3)->nullable();
            $table->decimal('segundos_descifrado', 12, 3)->nullable();
            $table->decimal('segundos_restauracion', 12, 3)->nullable();
            $table->decimal('segundos_verificacion', 12, 3)->nullable();

            // Descifrado + restauracion + verificacion. Esta es la cifra que alimenta el RTO.
            $table->decimal('segundos_recuperacion', 12, 3)->nullable();

            $table->unsignedBigInteger('bytes_volcado')->nullable();

            // El documento del proyecto declara AES-256. Se guarda el algoritmo leido del
            // propio archivo cifrado, no el que se pidio en la linea de ordenes: declarar un
            // algoritmo y usar otro es precisamente lo que un auditor comprueba.
            $table->string('algoritmo_cifrado', 30)->nullable();
            $table->boolean('cifrado_verificado')->default(false);
            $table->string('huella_volcado', 64)->nullable();

            // Una restauracion que termina sin error pero deja tablas vacias es peor que una
            // que falla, porque da falsa confianza. Por eso se comparan filas, no codigos de salida.
            $table->boolean('verificacion_superada')->default(false);
            $table->unsignedInteger('tablas_comparadas')->default(0);
            $table->unsignedBigInteger('filas_comparadas')->default(0);
            $table->json('conteos_origen')->nullable();
            $table->json('conteos_restaurada')->nullable();
            $table->json('discrepancias')->nullable();

            // Punto de recuperacion: se mide ANTES de volcar nada, sobre el directorio de
            // respaldos reales. Si se midiera despues, el volcado de la propia prueba seria
            // el respaldo mas reciente y el RPO saldria siempre en cero.
            $table->timestamp('respaldo_mas_reciente_en')->nullable();
            $table->text('respaldo_mas_reciente_ruta')->nullable();
            $table->unsignedInteger('antiguedad_respaldo_minutos')->nullable();
            $table->unsignedInteger('respaldos_encontrados')->default(0);
            $table->text('ruta_respaldos')->nullable();

            // El acta: la salida literal de la ejecucion. Es lo que se adjunta al anexo.
            $table->longText('salida')->nullable();

            $table->boolean('es_demostracion')->default(false);

            $table->timestamps();

            $table->index(['resultado', 'iniciada_en']);
            $table->index('iniciada_en');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pruebas_restauracion');
    }
};
