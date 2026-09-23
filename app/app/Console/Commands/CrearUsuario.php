<?php

namespace App\Console\Commands;

use App\Models\RegistroAuditoria;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Da de alta una cuenta real desde la consola del servidor, con una contraseña aleatoria
 * que se muestra una sola vez.
 *
 * Existe para no repartir la cuenta de otra persona. La del administrador del equipo tiene
 * su segundo factor atado a un teléfono: prestarla es prestar ese teléfono, y cualquier
 * acción hecha con ella quedaría firmada a su nombre en el registro de auditoría. Una cuenta
 * por persona es lo que permite que ese registro diga quién hizo qué.
 *
 * LA CONTRASEÑA NO SE GUARDA EN NINGÚN SITIO: se genera aquí, se resume antes de tocar la
 * base y se imprime una vez. No va en el repositorio, ni en un semillero, ni en un fichero
 * de entorno. Quien la reciba puede cambiarla desde su perfil.
 *
 * Solo se ejecuta desde el servidor, como root, porque dar de alta un administrador es
 * exactamente el tipo de acción que no debe poder hacerse desde un formulario.
 */
class CrearUsuario extends Command
{
    protected $signature = 'usuarios:crear
        {nombre : Nombre completo, tal como se mostrará}
        {correo : Correo con el que iniciará sesión}
        {--rol=administrador : Rol que se asigna: administrador, auditor o cliente}';

    protected $description = 'Crea una cuenta con contraseña aleatoria que se muestra una sola vez';

    /**
     * Símbolos sin significado para la terminal ni para un formulario. Sin comillas, barras
     * ni el signo de dólar: la contraseña se copia a mano de la pantalla y no debe cambiar
     * según dónde se pegue.
     */
    private const SIMBOLOS = '!@#%*-_+=?';

    public function handle(): int
    {
        $nombre = trim((string) $this->argument('nombre'));
        $correo = mb_strtolower(trim((string) $this->argument('correo')));
        $rol = Rol::canonico((string) $this->option('rol'));

        $validacion = Validator::make(
            ['nombre' => $nombre, 'correo' => $correo],
            ['nombre' => ['required', 'string', 'max:255'], 'correo' => ['required', 'email', 'max:255']],
        );

        if ($validacion->fails()) {
            $this->error($validacion->errors()->first());

            return self::FAILURE;
        }

        // Nunca sobrescribe: si el correo ya existe, podría ser la cuenta de otra persona,
        // con su segundo factor y su historial. Restablecerla es otra operación.
        if (User::query()->where('email', $correo)->exists()) {
            $this->error("Ya existe una cuenta con el correo {$correo}. No se modifica.");

            return self::FAILURE;
        }

        if (! Rol::query()->where('nombre', $rol)->exists()) {
            $this->error("No existe el rol \"{$rol}\". Los roles se siembran con RolesSeeder.");

            return self::FAILURE;
        }

        $contrasena = $this->contrasenaAleatoria();

        $usuario = DB::transaction(function () use ($nombre, $correo, $contrasena, $rol): User {
            $usuario = User::query()->create([
                'name' => $nombre,
                'email' => $correo,
                // Resumida aquí: la contraseña en claro no viaja por los eventos del modelo.
                'password' => Hash::make($contrasena),
                'activo' => true,
            ]);

            // No hay servidor de correo que verifique el buzón: la verifica quien ejecuta
            // esto como root en el servidor. forceFill porque el campo no es asignable en masa.
            $usuario->forceFill(['email_verified_at' => now()])->save();

            $usuario->asignarRol($rol);

            // Igual que la cuenta de demostración: un administrador que prueba la tienda
            // necesita poder comprar, y el carrito exige el rol de cliente.
            if ($rol === Rol::ADMINISTRADOR) {
                $usuario->asignarRol(Rol::CLIENTE);
            }

            RegistroAuditoria::query()->create([
                'accion' => 'usuario.creado_por_consola',
                'tipo_recurso' => 'usuario',
                'identificador_recurso' => (string) $usuario->getKey(),
                'correo' => $correo,
                'metodo' => 'CLI',
                'ruta' => 'artisan usuarios:crear',
                'resultado' => RegistroAuditoria::EXITO,
                'detalle' => [
                    'rol' => $rol,
                    'ejecutado_por' => get_current_user() ?: 'desconocido',
                    'anfitrion' => gethostname() ?: 'desconocido',
                ],
            ]);

            return $usuario;
        });

        $this->newLine();
        $this->info('Cuenta creada.');
        $this->table(['Campo', 'Valor'], [
            ['Nombre', $usuario->name],
            ['Correo', $usuario->email],
            ['Rol', $rol.($rol === Rol::ADMINISTRADOR ? ' (y cliente, para usar la tienda)' : '')],
            ['Contraseña', $contrasena],
        ]);
        $this->warn('La contraseña se muestra solo ahora. Cópiala y entrégala en persona; no se puede recuperar.');
        $this->line('Quien la reciba puede cambiarla en Configuración → Contraseña.');

        return self::SUCCESS;
    }

    /**
     * Veinte caracteres con al menos una mayúscula, una minúscula, un dígito y un símbolo:
     * lo que exige la política de contraseñas en producción.
     */
    private function contrasenaAleatoria(): string
    {
        $mayusculas = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $minusculas = 'abcdefghijkmnpqrstuvwxyz';
        $digitos = '23456789';
        $todos = $mayusculas.$minusculas.$digitos.self::SIMBOLOS;

        // Se excluyen I, O, l, 0 y 1: la contraseña se lee de una pantalla y se teclea.
        $caracteres = [
            $mayusculas[random_int(0, strlen($mayusculas) - 1)],
            $minusculas[random_int(0, strlen($minusculas) - 1)],
            $digitos[random_int(0, strlen($digitos) - 1)],
            self::SIMBOLOS[random_int(0, strlen(self::SIMBOLOS) - 1)],
        ];

        while (count($caracteres) < 20) {
            $caracteres[] = $todos[random_int(0, strlen($todos) - 1)];
        }

        // Barajado con random_int (criptográfico), no con shuffle().
        for ($i = count($caracteres) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$caracteres[$i], $caracteres[$j]] = [$caracteres[$j], $caracteres[$i]];
        }

        return implode('', $caracteres);
    }
}
