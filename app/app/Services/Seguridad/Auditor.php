<?php

declare(strict_types=1);

namespace App\Services\Seguridad;

use App\Models\RegistroAuditoria;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Escribe asientos en la tabla registros_auditoria.
 *
 * Catálogo vivo de acciones que registra el proyecto (se añaden aquí para que
 * exista un único sitio donde leerlas):
 *
 *   inicio_sesion, inicio_sesion_fallido, cierre_sesion, bloqueo_por_intentos,
 *   contrasena_actualizada, contrasena_restablecida,
 *   segundo_factor_activado, segundo_factor_desactivado, segundo_factor_confirmado,
 *   codigos_recuperacion_regenerados, codigo_recuperacion_usado,
 *   passkey_eliminada, sesiones_cerradas, acceso_denegado_por_rol,
 *   peticion_sensible
 */
class Auditor
{
    /**
     * @param  array{resultado?: string, tipo_recurso?: string|null, identificador_recurso?: string|int|null, usuario_id?: int|null, correo?: string|null, metodo?: string|null, ruta?: string|null, codigo_respuesta?: int|null, detalle?: array<string, mixed>}  $datos
     */
    public function registrar(string $accion, array $datos = []): void
    {
        $usuario = auth()->user();

        try {
            RegistroAuditoria::query()->create([
                'accion' => $accion,
                'tipo_recurso' => $datos['tipo_recurso'] ?? null,
                'identificador_recurso' => isset($datos['identificador_recurso'])
                    ? Str::limit((string) $datos['identificador_recurso'], 64, '')
                    : null,
                'usuario_id' => $datos['usuario_id'] ?? ($usuario instanceof User ? $usuario->id : null),
                'correo' => $datos['correo'] ?? ($usuario instanceof User ? $usuario->email : null),
                'direccion_ip' => request()->ip(),
                'agente_usuario' => Str::limit((string) request()->userAgent(), 512, ''),
                'metodo' => $datos['metodo'] ?? request()->method(),
                'ruta' => $datos['ruta'] ?? request()->path(),
                'codigo_respuesta' => $datos['codigo_respuesta'] ?? null,
                'resultado' => $datos['resultado'] ?? RegistroAuditoria::EXITO,
                'detalle' => $this->censurar($datos['detalle'] ?? []),
            ]);
        } catch (Throwable $error) {
            // Mismo criterio que en la bitácora: la trazabilidad no puede tumbar la
            // operación. Si la base de datos no acepta el asiento —por ejemplo
            // porque la migración aún no corrió— queda el rastro en el registro
            // general y el usuario no se entera de nada.
            Log::error('No se pudo escribir el registro de auditoría', [
                'accion' => $accion,
                'excepcion' => $error->getMessage(),
            ]);
        }
    }

    /**
     * Sustituye por un marcador todo campo cuyo nombre esté en la lista negra.
     *
     * Se recorre en profundidad porque los datos sensibles llegan anidados: un
     * formulario de Livewire manda "componentes[0].datos.password".
     *
     * @param  array<array-key, mixed>  $datos
     * @return array<array-key, mixed>
     */
    public function censurar(array $datos): array
    {
        /** @var array<int, string> $prohibidos */
        $prohibidos = config('seguridad.auditoria.campos_censurados', []);

        $limpio = [];

        foreach ($datos as $clave => $valor) {
            if (is_string($clave) && in_array(mb_strtolower($clave), $prohibidos, true)) {
                $limpio[$clave] = '[censurado]';

                continue;
            }

            $limpio[$clave] = is_array($valor) ? $this->censurar($valor) : $valor;
        }

        return $limpio;
    }
}
