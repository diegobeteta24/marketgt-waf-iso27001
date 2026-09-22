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
        // Esta tabla guarda el PLAN, no la ejecucion. La distincion es justo lo que una
        // auditoria de gestion evalua: un control "definido pero no ejecutado" y un control
        // "ni siquiera definido" son dos hallazgos distintos y con plazos de correccion
        // distintos, y sin esta tabla el panel no podria diferenciarlos. Las asistencias,
        // que son la ejecucion, viven en la tabla siguiente.
        Schema::create('capacitaciones', function (Blueprint $table) {
            $table->id();

            // Identificador del registro de asistencia que exige POL-006 seccion 6:
            // CAP-AAAA-SN. Unico porque el semillero se reejecuta en cada despliegue y
            // sembrar el plan dos veces duplicaria el denominador de la metrica.
            $table->string('codigo', 30)->unique();

            // formal | induccion | actualizacion | simulacro | recordatorio.
            // Son las cinco actividades de la tabla de periodicidad de POL-006 seccion 3.
            $table->string('tipo', 20);

            $table->string('tema', 160);
            $table->text('descripcion')->nullable();

            // semestral | mensual | por_incorporacion | por_incidente. La periodicidad es
            // parte del plan, no de la sesion: es lo que permite decir si una sesion vencio.
            $table->string('periodicidad', 30);

            // Semestre al que pertenece la sesion (2026-S2). Nulo en las actividades que no
            // tienen calendario porque las dispara un hecho —una incorporacion, un incidente—
            // y fijarles un semestre seria inventarles una fecha.
            $table->string('periodo', 10)->nullable();

            // Los modulos de POL-006 seccion 4 y el material de la Fundacion OWASP que cada
            // uno cita. Se guardan con la sesion y no en un documento aparte porque la
            // evidencia del control A.6.3 exige decir QUE se impartio, no solo que hubo algo.
            $table->json('modulos')->nullable();
            $table->json('material')->nullable();

            // Funciones convocadas. POL-006 seccion 2 reparte contenido obligatorio por
            // nivel, y el panel necesita esa lista para explicar a quien le falta que cosa.
            $table->json('destinatarios')->nullable();

            // "Funcion, no nombre de persona", literal de POL-006 seccion 6. El instructor se
            // identifica por su papel para que el registro sobreviva a un cambio de personas.
            $table->string('instructor_funcion', 120)->nullable();

            // Presencial o remota. Nula mientras la sesion no se haya impartido: antes de
            // ocurrir, la modalidad es una intencion y no un hecho.
            $table->string('modalidad', 20)->nullable();

            $table->unsignedSmallInteger('duracion_minutos')->nullable();

            // Cuanto dura la vigencia de esta capacitacion. Semestral son seis meses, y es el
            // numero que convierte una asistencia antigua en una capacitacion caducada.
            $table->unsignedSmallInteger('vigencia_meses')->default(6);

            // Umbral de aprobacion de POL-006 seccion 7.1. Se guarda por sesion porque una
            // induccion y una capacitacion formal no tienen por que exigir lo mismo, y
            // porque cambiar el umbral manana no debe reescribir la historia de ayer.
            $table->unsignedTinyInteger('umbral_aprobacion')->default(80);

            // DOS BANDERAS QUE DECIDEN LA METRICA, y por eso van explicadas aqui:
            //
            // cuenta_para_vigencia: si superar esta sesion acredita a una persona como
            // capacitada. POL-006 seccion 5 dice que el simulacro NO es capacitacion sino la
            // verificacion de que la capacitacion sirvio, y un recordatorio de diez minutos
            // tampoco renueva un semestre. Contarlos inflaria la metrica sin que nadie
            // mintiera: bastaria con celebrar muchos recordatorios.
            $table->boolean('cuenta_para_vigencia')->default(false);

            // exige_a_todo_el_equipo: si la ausencia de una persona en esta sesion es una
            // asistencia que falta por registrar. La induccion no la exige (solo alcanza a
            // quien se incorpora), la formal si ("No existen personas exentas", seccion 2).
            $table->boolean('exige_a_todo_el_equipo')->default(false);

            // planificada | impartida | cancelada. Se separa de impartida_en a proposito:
            // una sesion cancelada tambien es informacion, y borrarla dejaria el plan
            // pareciendo mas corto de lo que se comprometio.
            $table->string('estado', 20)->default('planificada');

            // Fecha prevista. Anulable y sin valor por defecto: POL-006 seccion 8 programa la
            // primera edicion "para la semana previa a la presentacion" y no da un dia. Poner
            // aqui un martes inventado convertiria una imprecision del plan en un dato falso.
            $table->date('programada_para')->nullable();
            $table->text('nota_programacion')->nullable();

            // EL HECHO. Mientras sea nula, la sesion no ocurrio y ninguna asistencia suya
            // puede estar vigente, porque la vigencia se cuenta desde este momento.
            $table->timestamp('impartida_en')->nullable();

            $table->text('observaciones')->nullable();

            // Ruta del acta en evidencias/capacitacion/, conforme a POL-006 seccion 6.
            $table->string('evidencia_ruta', 500)->nullable();

            // PROCEDENCIA. Un auditor pregunta de donde salio cada fila, y aqui hay dos
            // origenes posibles con valor probatorio distinto: "plan" significa que la fila
            // la sembro el semillero copiando un documento aprobado —y fuente dice cual y que
            // seccion—, "manual" que la escribio una persona desde el panel.
            $table->string('origen', 20)->default('plan');
            $table->string('fuente', 500)->nullable();
            $table->foreignId('registrada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor', 190)->nullable();
            $table->timestamp('registrada_en')->nullable();

            $table->timestamps();

            // Los tres ordenes en que se consulta: el calculo de la metrica busca sesiones
            // impartidas que acreditan vigencia, el panel lista el plan por semestre y el
            // recuento de pendientes filtra por lo que se exige a todo el equipo.
            $table->index(['estado', 'impartida_en']);
            $table->index(['cuenta_para_vigencia', 'impartida_en']);
            $table->index(['periodo', 'tipo']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('capacitaciones');
    }
};
