<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Tres cuentas de demostración, una por rol.
 *
 * Los nombres y los datos personales son de fantasía: un proyecto de aula no debe
 * llevar datos reales de nadie ni siquiera de ejemplo, porque la base termina en
 * un repositorio y en una memoria USB que circula.
 *
 * Las contraseñas están escritas aquí en claro a propósito: son credenciales de un
 * entorno de demostración que se destruye al terminar, y esconderlas daría la
 * falsa impresión de que este semillero es apto para producción. NO LO ES. Para
 * que no pueda serlo por accidente, el semillero se niega a correr en producción.
 */
class UsuariosDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('UsuariosDemoSeeder no se ejecuta en producción: crearía cuentas con contraseñas conocidas.');

            return;
        }

        $usuarios = [
            [
                'name' => 'Ixchel Morataya',
                'email' => 'admin@marketgt.test',
                'password' => 'Admin#MarketGT2026',
                'rol' => Rol::ADMINISTRADOR,
                'direccion' => '7a Avenida 12-34, Zona 10, Ciudad de Guatemala',
                'telefono' => '2255-1010',
                'documento_identidad' => '1234 56789 0101',
            ],
            [
                'name' => 'Baltazar Chiquín',
                'email' => 'auditor@marketgt.test',
                'password' => 'Auditor#MarketGT2026',
                'rol' => Rol::AUDITOR,
                'direccion' => '3a Calle 5-21, Zona 1, Quetzaltenango',
                'telefono' => '7761-2020',
                'documento_identidad' => '2345 67890 0902',
            ],
            [
                'name' => 'Rosalinda Us Tzoc',
                'email' => 'cliente@marketgt.test',
                'password' => 'Cliente#MarketGT2026',
                'rol' => Rol::CLIENTE,
                'direccion' => 'Calzada Aguilar Batres 18-90, Zona 12, Ciudad de Guatemala',
                'telefono' => '5544-3030',
                'documento_identidad' => '3456 78901 0103',
            ],
        ];

        foreach ($usuarios as $datos) {
            $usuario = User::query()->updateOrCreate(
                ['email' => $datos['email']],
                [
                    'name' => $datos['name'],
                    // Hash::make explícito: el molde 'hashed' del modelo ya lo haría,
                    // pero updateOrCreate con un valor ya cifrado volvería a cifrarlo
                    // en una segunda ejecución y la contraseña dejaría de servir.
                    'password' => Hash::make($datos['password']),
                    'direccion' => $datos['direccion'],
                    'telefono' => $datos['telefono'],
                    'documento_identidad' => $datos['documento_identidad'],
                    'activo' => true,
                ],
            );

            // La verificación del correo va por forceFill: email_verified_at no es
            // asignable en masa a propósito, porque un campo que declara "esta
            // persona demostró tener acceso a este buzón" no debe poder llegar
            // nunca desde un formulario.
            $usuario->forceFill(['email_verified_at' => now()])->save();

            $usuario->asignarRol($datos['rol']);

            // El administrador también compra en su propia tienda durante la
            // demostración; sin el rol de cliente, el carrito le quedaría vedado si
            // otro componente lo protege con "rol:cliente".
            if ($datos['rol'] === Rol::ADMINISTRADOR) {
                $usuario->asignarRol(Rol::CLIENTE);
            }
        }
    }
}
