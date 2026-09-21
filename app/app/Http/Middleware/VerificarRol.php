<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Rol;
use App\Models\User;
use App\Services\Seguridad\Auditor;
use App\Services\Seguridad\BitacoraSeguridad;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Control de acceso por rol.
 *
 * Uso:  ->middleware('rol:administrador')
 *       ->middleware('rol:admin,auditor')   // basta con tener uno de los dos
 *
 * Se comprueba el rol en el servidor y en cada petición. Esconder un enlace del
 * menú no es un control de acceso: la URL del SIEM se adivina, se comparte por
 * chat y se queda en el historial del navegador de quien ya no trabaja aquí.
 */
class VerificarRol
{
    public function __construct(
        private readonly BitacoraSeguridad $bitacora,
        private readonly Auditor $auditor,
    ) {}

    public function handle(Request $peticion, Closure $siguiente, string ...$roles): Response
    {
        $usuario = $peticion->user();

        // Sin sesión se responde 401 y no 403: el 403 significa "sé quién eres y
        // aun así no puedes", y decirle eso a un anónimo le confirma que la ruta
        // existe. El middleware 'auth' debería ir delante y redirigir antes.
        if (! $usuario instanceof User) {
            abort(401);
        }

        $exigidos = $this->normalizar($roles);

        if ($exigidos !== [] && $usuario->tieneRol(...$exigidos)) {
            return $siguiente($peticion);
        }

        // El intento denegado se registra en los dos sitios: en la bitácora JSON,
        // porque el SIEM correlaciona un 403 de la aplicación con los 403 del WAF
        // de la misma IP, y en la tabla, porque es exactamente el asiento que un
        // auditor busca cuando pregunta quién intentó entrar donde no debía.
        $this->bitacora->registrar('acceso_denegado_por_rol', [
            'usuario_id' => $usuario->id,
            'correo' => $usuario->email,
            // Nivel de aviso y no informativo: una cuenta legítima no tropieza por
            // casualidad con la ruta del SIEM. Cuando ocurre, o alguien está
            // curioseando o alguien entró con credenciales que no son suyas.
            'nivel' => 'warning',
            'detalle' => [
                'ruta' => $peticion->path(),
                'metodo' => $peticion->method(),
                'roles_exigidos' => $exigidos,
                'roles_del_usuario' => $usuario->roles->pluck('nombre')->all(),
            ],
        ]);

        $this->auditor->registrar('acceso_denegado_por_rol', [
            'resultado' => 'denegado',
            'tipo_recurso' => 'ruta',
            'identificador_recurso' => $peticion->path(),
            'detalle' => ['roles_exigidos' => $exigidos],
        ]);

        abort(403, 'No tiene el rol necesario para acceder a esta sección.');
    }

    /**
     * Acepta "rol:admin,auditor" y "rol:admin|auditor", y traduce los alias.
     *
     * @param  array<int, string>  $roles
     * @return array<int, string>
     */
    private function normalizar(array $roles): array
    {
        $planos = [];

        foreach ($roles as $rol) {
            foreach (preg_split('/[,|]/', $rol) ?: [] as $pieza) {
                $pieza = trim($pieza);

                if ($pieza !== '') {
                    $planos[] = Rol::canonico($pieza);
                }
            }
        }

        return array_values(array_unique($planos));
    }
}
