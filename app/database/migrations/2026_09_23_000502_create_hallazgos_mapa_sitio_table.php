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
        Schema::create('hallazgos_mapa_sitio', function (Blueprint $table) {
            $table->id();

            // Identificador de la corrida (ULID). Una auditoria del mapa del sitio es una
            // FOTOGRAFIA, no un flujo de eventos: sin esta columna no se puede enseñar "lo
            // que se encontro el martes" ni comparar dos pasadas, que es justo donde vive
            // el valor del control, porque lo que delata la inyeccion es el CAMBIO.
            $table->char('ejecucion', 26);

            // Anfitrion auditado. El control sirve para auditar un sitio ajeno —el de la
            // empresa donde trabaja el estudiante, por ejemplo—, no solo el propio.
            $table->string('sitio', 190);

            // direccion_declarada | directiva_exclusion
            $table->string('tipo', 30);

            // Texto, no string: una direccion inyectada puede traer cientos de caracteres
            // de palabras clave, y recortarla en la base de datos destruiria la evidencia.
            $table->text('url')->nullable();

            // Mapa o archivo donde se declaro. Con indices de mapas anidados, saber en cual
            // de los hijos aparecio es la diferencia entre limpiar el archivo correcto y
            // borrar el sitemap entero a ciegas.
            $table->text('origen')->nullable();

            $table->string('veredicto', 20)->default('limpia');

            // Puntuacion de anomalia acumulada, misma escala que DetectorSpamSeo y que el
            // Core Rule Set del WAF: ninguna señal aislada prueba nada, lo que delata la
            // inyeccion es la acumulacion.
            $table->unsignedSmallInteger('puntuacion')->default(0);

            // Que regla disparo, con cuantos puntos y con que fragmento como prueba. Un
            // veredicto sin los motivos no se puede discutir con quien administra el sitio,
            // y un hallazgo que no se puede discutir no se corrige: se ignora.
            $table->json('motivos')->nullable();

            $table->unsignedTinyInteger('profundidad')->nullable();

            // El lastmod que declaraba el mapa. Se guarda como fecha para poder ordenar y
            // ver de un vistazo el bloque de paginas con fecha en el futuro.
            $table->timestamp('fecha_declarada')->nullable();

            // Lo que respondio el servidor cuando se pidio la pagina. Es lo que separa la
            // basura (404) de la pagina inyectada que esta viva ahora mismo (200), y sin
            // esta columna las dos cosas se veian iguales en el informe.
            $table->unsignedSmallInteger('codigo_http')->nullable();
            $table->string('titulo_remoto')->nullable();
            $table->text('destino_final')->nullable();
            $table->timestamp('comprobada_en')->nullable();

            // Incidente que agrupa este hallazgo. Nullable porque una direccion sospechosa
            // por debajo del umbral se guarda como evidencia sin abrir incidente: no toda
            // señal merece una alarma, y una tabla de alarmas que siempre esta encendida es
            // una tabla que nadie mira.
            $table->foreignId('incidente_id')->nullable()->constrained('incidentes_seo')->nullOnDelete();

            $table->timestamps();

            // Pintar una corrida completa ordenada de peor a mejor es la consulta que hace
            // la pantalla cada vez que se abre, y la que hace el comando programado.
            $table->index(['ejecucion', 'puntuacion']);

            // "Enseñame todo lo grave de este sitio" es la pregunta del auditor.
            $table->index(['sitio', 'veredicto']);

            $table->index(['sitio', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hallazgos_mapa_sitio');
    }
};
