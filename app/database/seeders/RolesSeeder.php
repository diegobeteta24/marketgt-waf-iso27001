<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Rol;
use Illuminate\Database\Seeder;

/**
 * Los tres roles del sistema.
 *
 * Se usa updateOrCreate y no create para que el semillero se pueda volver a
 * ejecutar sin romper la clave única ni duplicar concesiones. Durante la
 * preparación de la demostración esto se corre muchas veces.
 */
class RolesSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [
                'nombre' => Rol::ADMINISTRADOR,
                'etiqueta' => 'Administrador',
                'descripcion' => 'Gestiona el catálogo, los pedidos y las cuentas. Acceso completo, incluido el centro de monitoreo.',
            ],
            [
                'nombre' => Rol::AUDITOR,
                'etiqueta' => 'Auditor',
                'descripcion' => 'Lee el centro de monitoreo y los registros de auditoría. No puede modificar el catálogo ni los pedidos: es la separación de funciones que exige la auditoría.',
            ],
            [
                'nombre' => Rol::CLIENTE,
                'etiqueta' => 'Cliente',
                'descripcion' => 'Compra en la tienda y administra su propia cuenta. No ve nada de otra persona.',
            ],
        ];

        foreach ($roles as $rol) {
            Rol::query()->updateOrCreate(
                ['nombre' => $rol['nombre']],
                ['etiqueta' => $rol['etiqueta'], 'descripcion' => $rol['descripcion']],
            );
        }
    }
}
