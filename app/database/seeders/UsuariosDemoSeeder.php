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
        // Estas cuentas llevan contraseñas conocidas y publicadas, de modo que
        // crearlas en un entorno de producción real sería una vulnerabilidad
        // grave. La guarda se mantiene activa por omisión.
        //
        // El entorno publicado de este proyecto es una demostración académica:
        // se marca como producción para heredar sus optimizaciones y para que
        // los mensajes de error no expongan trazas, pero necesita las cuentas
        // con las que se enseña el sistema. La excepción exige entonces un acto
        // deliberado —declarar PERMITIR_CUENTAS_DEMO— en lugar de desactivar
        // la comprobación, de manera que quede constancia de que alguien la
        // tomó a conciencia y de que el valor por omisión sigue siendo seguro.
        //
        // Al desplegar esta plataforma en un entorno real, esa variable no debe
        // existir y estas cuentas deben eliminarse. Así consta en la política
        // de seguridad y en el manual de operación.
        if (app()->isProduction() && ! env('PERMITIR_CUENTAS_DEMO', false)) {
            $this->command?->warn('UsuariosDemoSeeder omitido: crearía cuentas con contraseñas conocidas.');
            $this->command?->warn('Para un entorno de demostración, definí PERMITIR_CUENTAS_DEMO=true.');

            return;
        }

        if (app()->isProduction()) {
            $this->command?->warn('ATENCIÓN: se crean cuentas de demostración con contraseñas conocidas.');
            $this->command?->warn('Este entorno no debe tratar datos reales de personas.');
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
                    // Hash::make explícito y no la contraseña en claro: el molde
                    // 'hashed' del modelo la resumiría igual —y es idempotente, así
                    // que reejecutar el semillero no la rompería—, pero dejarla en
                    // claro aquí significa que el valor viaja sin resumir por los
                    // eventos del modelo y por cualquier observador que se enganche.
                    // Resumida en el sitio, la contraseña en claro no sale de esta
                    // línea.
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
