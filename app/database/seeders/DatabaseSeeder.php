<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Orquestador de los datos iniciales de MarketGT.
 *
 * El orden importa y no es arbitrario: los roles tienen que existir antes de
 * que haya usuarios a quienes asignárselos, y el catálogo antes que los
 * eventos de demostración, que hacen referencia a rutas de productos reales.
 *
 * Aquí venía la llamada a la factoría de usuarios que trae el andamiaje
 * inicial. Se retiró porque esa factoría depende de la biblioteca de datos
 * ficticios, que es una dependencia de desarrollo y no viaja a producción: al
 * desplegar, la carga de datos abortaba con «Call to undefined function
 * fake()». Los semilleros de este proyecto escriben sus datos de forma
 * explícita, lo cual además los hace reproducibles: el mismo catálogo y los
 * mismos usuarios en cada despliegue, que es lo que corresponde cuando esos
 * datos son parte de la evidencia que se presenta.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // Control de acceso: primero los roles, después quienes los tienen.
            RolesSeeder::class,
            UsuariosDemoSeeder::class,

            // El catálogo es el activo que protege todo el esquema.
            CatalogoSeeder::class,

            // El PLAN de capacitación de POL-006, y nada más. Sin él, la métrica de
            // personal capacitado no tiene contra qué medirse y el panel no ofrece
            // ninguna sesión donde registrar asistencias. No siembra asistencias a
            // propósito: esas las registra quien presenció la sesión, desde el panel.
            CapacitacionesSeeder::class,

            // Eventos de demostración para que el panel de detección tenga
            // historial desde el primer minuto. Van marcados como sintéticos
            // para poder distinguirlos de los reales durante una auditoría.
            EventosDemoSeeder::class,
        ]);
    }
}
