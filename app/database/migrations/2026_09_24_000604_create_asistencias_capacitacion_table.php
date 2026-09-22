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
        // La ejecucion del plan, una fila por persona y sesion. Ninguna fila de esta tabla
        // la puede escribir un semillero: cada una afirma que alguien estuvo en una sala un
        // dia concreto y que respondio una evaluacion. Sembrarlas seria fabricar la evidencia
        // del unico control que el anexo del proyecto reprocha por estar solo escrito.
        Schema::create('asistencias_capacitacion', function (Blueprint $table) {
            $table->id();

            $table->foreignId('capacitacion_id')->constrained('capacitaciones')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();

            // Funcion que desempenaba la persona ese dia. POL-006 seccion 6 pide la funcion
            // del asistente, y se copia aqui en vez de leerla de sus roles actuales: quien
            // hoy es auditor pudo asistir siendo otra cosa, y el acta describe aquel dia.
            $table->string('funcion', 120)->nullable();

            $table->boolean('asistio')->default(true);
            $table->string('modalidad', 20)->nullable();

            // ANULABLE A PROPOSITO, y es la columna mas importante de la tabla.
            // POL-006 seccion 6: "Un registro de asistencia sin resultado de evaluacion
            // acredita presencia, no capacitacion". Nulo significa "asistio y todavia no se
            // evaluo", que no es ni aprobado ni reprobado. Un booleano obligatorio obligaria
            // a elegir uno de los dos, y esa eleccion seria un supuesto.
            $table->boolean('evaluacion_superada')->nullable();

            $table->unsignedTinyInteger('puntuacion')->nullable();
            $table->timestamp('evaluada_en')->nullable();

            // El componente practico de POL-006 seccion 7.2: cuatro ejercicios que se ejecutan
            // en el sistema real. Separado de la evaluacion escrita porque tiene consecuencia
            // operativa propia —quien no los completa no asume el turno de guardia— y porque
            // tambien puede quedar pendiente sin que eso signifique reprobar.
            $table->boolean('ejercicio_practico_superado')->nullable();

            $table->text('observaciones')->nullable();

            // Ruta del acta firmada, si la hay. El registro en la plataforma no sustituye al
            // expediente documental: lo indexa y lo hace medible.
            $table->string('evidencia_ruta', 500)->nullable();

            // PROCEDENCIA DE LA MEDICION. Es lo primero que pregunta una auditoria y la
            // razon por la que este registro vale mas que una casilla marcada en una hoja:
            // panel | comando | importacion, quien lo registro y cuando lo registro, que no
            // es lo mismo que cuando ocurrio la capacitacion.
            $table->string('origen', 20)->default('panel');
            $table->foreignId('registrada_por')->nullable()->constrained('users')->nullOnDelete();

            // El nombre y correo de quien registro, copiados en el momento. Si manana se da
            // de baja esa cuenta, registrada_por queda nulo por la clave foranea y sin esta
            // columna el acta se quedaria sin responsable.
            $table->string('actor', 190);
            $table->timestamp('registrada_en');

            $table->timestamps();

            // Una sola asistencia por persona y sesion. Corregir el resultado de la
            // evaluacion actualiza esta fila y vuelve a sellar la procedencia; duplicarla
            // permitiria que la misma persona contara dos veces en el numerador.
            $table->unique(['capacitacion_id', 'usuario_id']);

            // El orden en que lo lee la metrica: quien supero que, para cruzarlo con las
            // sesiones vigentes.
            $table->index(['usuario_id', 'evaluacion_superada']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asistencias_capacitacion');
    }
};
