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
        Schema::create('estados_parche', function (Blueprint $table) {
            $table->id();

            // El anfitrion forma parte de la identidad de la fila: el mismo paquete y la
            // misma version en dos servidores son dos hechos distintos, y mezclarlos daria
            // una cobertura de parcheo que no describe a ninguno de los dos.
            $table->string('anfitrion', 190);
            $table->string('paquete', 190);
            $table->string('arquitectura', 40)->nullable();
            $table->string('version', 120);
            $table->string('version_anterior', 120)->nullable();

            // pendiente | aplicado | no_aplicable
            $table->string('estado', 20);

            // Anulable A PROPOSITO, y es la decision central de esta tabla. Un booleano
            // obligatorio forzaria a decidir entre "de seguridad" y "no lo es" para un
            // paquete cuyo registro de cambios no esta instalado, y esa decision seria un
            // supuesto. Nulo significa "no se pudo clasificar", y la metrica lo declara.
            $table->boolean('es_seguridad')->nullable();

            // De que archivo de Ubuntu viene la actualizacion pendiente (noble-security,
            // noble-updates...). Es el hecho que respalda es_seguridad cuando el paquete
            // todavia no esta instalado.
            $table->string('origen_archivo', 120)->nullable();

            // Fecha firmada en el pie de la entrada del registro de cambios del paquete.
            // Cuando falta, falta: no se sustituye por la de otra version ni por la del
            // archivo, porque el plazo de 72 h se mide contra ella.
            $table->timestamp('publicado_en')->nullable();
            $table->string('fuente_publicacion', 500)->nullable();

            // Por que no se conoce la fecha de publicacion. Un nulo sin explicacion obliga
            // al auditor a adivinar si falta el dato o fallo el recolector.
            $table->text('nota_publicacion')->nullable();

            $table->json('identificadores_cve')->nullable();

            $table->timestamp('aplicado_en')->nullable();
            $table->string('fuente_aplicacion', 500)->nullable();

            // Distingue "lo aplico el parcheador automatico" de "lo aplico una persona".
            // El control A.8.8 pregunta exactamente eso, y las dos respuestas valen.
            $table->string('aplicado_por', 190)->nullable();

            // Primera vez que el recolector vio el parche pendiente. Para los parches que
            // nunca traen fecha de publicacion es la unica medida real del retraso, y es
            // una cota inferior: el parche pudo estar disponible antes de que miraramos.
            $table->timestamp('visto_pendiente_desde')->nullable();

            // Horas entre publicacion y aplicacion. Se recalcula en la ingesta a partir de
            // las dos marcas de tiempo de esta misma fila, de modo que la cifra siempre es
            // reproducible desde los datos guardados.
            $table->decimal('desfase_horas', 10, 2)->nullable();

            // Procedencia de la medicion, que es lo primero que pregunta una auditoria.
            $table->timestamp('recolectado_en');
            $table->string('recolectado_por', 190);
            $table->string('version_recolector', 40)->nullable();

            $table->timestamps();

            // SHA-256 de anfitrion|paquete|arquitectura|version. Unica a proposito: una
            // version de un paquete en una maquina es un hecho, y reprocesar el archivo de
            // recoleccion tras una rotacion no debe duplicarlo. La transicion de pendiente
            // a aplicado actualiza esta misma fila, que es lo que permite medir cuanto
            // tiempo estuvo esperando.
            $table->char('huella', 64)->unique();

            // Los tres ordenes en que se lee: el calculo de la metrica, el repaso de lo
            // que sigue pendiente y la comprobacion de que el inventario esta fresco.
            $table->index(['es_seguridad', 'estado']);
            $table->index(['estado', 'visto_pendiente_desde']);
            $table->index('recolectado_en');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estados_parche');
    }
};
