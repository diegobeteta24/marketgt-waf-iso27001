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
        // Hasta ahora una alerta triada solo decia QUE se decidio y QUIEN la atendio. Faltaba
        // lo que pregunta primero una auditoria: si la decision la tomo una persona mirando la
        // evidencia o un proceso por lote. Sin esa distincion, una cobertura de triaje del cien
        // por cien se puede alcanzar ejecutando un comando, y la metrica deja de significar nada.
        Schema::table('alertas_seguridad', function (Blueprint $table) {
            // humana | automatica. Nulo mientras la alerta no se haya triado, que es un tercer
            // estado real y distinto de los otros dos: no se rellena con "automatica" por defecto.
            $table->string('procedencia_triaje', 20)->nullable()->after('notas_triaje');

            // Clave de la regla del criterio declarado que decidio la clasificacion. Es lo que
            // permite a un tercero recorrer el criterio en el codigo y rehacer la decision.
            // Nulo en el triaje humano: ahi el criterio es el juicio del analista, no una regla.
            $table->string('triaje_regla', 60)->nullable()->after('procedencia_triaje');

            // El criterio en palabras, tal como se mostro en el panel el dia que se aplico.
            // Se copia aqui a proposito: si manana se reescribe la regla, esta alerta sigue
            // diciendo con que texto se clasifico. Un criterio que cambia sin dejar rastro
            // convierte el historico en una afirmacion imposible de comprobar.
            $table->text('triaje_criterio')->nullable()->after('triaje_regla');

            // Los hechos concretos que hicieron saltar la regla: cuantos eventos, cuantos los
            // corto el cortafuegos, que direcciones se miraron y que reglas dispararon. Es la
            // diferencia entre "el sistema lo decidio" y "el sistema lo decidio por esto".
            $table->json('triaje_evidencia')->nullable()->after('triaje_criterio');

            $table->timestamp('triado_en')->nullable()->after('triaje_evidencia');

            // Texto y no clave foranea: el actor puede no ser una cuenta de la aplicacion.
            // Cuando es el comando, aqui va el nombre del comando, el anfitrion y el usuario
            // del sistema que lo lanzo. La cuenta, cuando existe, ya vive en atendida_por.
            $table->string('triado_por', 190)->nullable()->after('triado_en');

            // El orden en que se leen estas columnas es siempre el mismo: agrupar por
            // procedencia dentro de la ventana de observacion para partir la cobertura en dos.
            $table->index(['procedencia_triaje', 'triado_en']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alertas_seguridad', function (Blueprint $table) {
            $table->dropIndex(['procedencia_triaje', 'triado_en']);

            $table->dropColumn([
                'procedencia_triaje',
                'triaje_regla',
                'triaje_criterio',
                'triaje_evidencia',
                'triado_en',
                'triado_por',
            ]);
        });
    }
};
