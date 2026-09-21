<?php

declare(strict_types=1);

namespace App\Services\Seguridad;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Enumera y cierra las sesiones activas de una cuenta.
 *
 * Solo funciona con SESSION_DRIVER=database, que es el que trae el proyecto. Con
 * el controlador de archivos no hay forma de saber qué sesiones pertenecen a qué
 * usuario, y la pantalla mentiría mostrando una lista vacía.
 */
class GestorSesiones
{
    public function disponible(): bool
    {
        return config('session.driver') === 'database';
    }

    /**
     * Sesiones abiertas de la cuenta, la actual primero.
     *
     * @return array<int, array{id: string, ip: string, agente_usuario: string, dispositivo: string, es_actual: bool, ultima_actividad: Carbon}>
     */
    public function listar(User $usuario, string $sesionActual): array
    {
        if (! $this->disponible()) {
            return [];
        }

        $filas = DB::table($this->tabla())
            ->where('user_id', $usuario->id)
            ->orderByDesc('last_activity')
            ->limit((int) config('seguridad.sesiones.maximo_listado', 25))
            ->get(['id', 'ip_address', 'user_agent', 'last_activity']);

        return $filas->map(function (object $fila) use ($sesionActual): array {
            $agente = (string) ($fila->user_agent ?? '');

            return [
                'id' => (string) $fila->id,
                'ip' => (string) ($fila->ip_address ?? 'desconocida'),
                'agente_usuario' => $agente,
                'dispositivo' => $this->describirDispositivo($agente),
                'es_actual' => $fila->id === $sesionActual,
                'ultima_actividad' => Carbon::createFromTimestamp((int) $fila->last_activity),
            ];
        })
            ->sortByDesc('es_actual')
            ->values()
            ->all();
    }

    /**
     * Cierra todas las sesiones de la cuenta salvo la indicada.
     *
     * Borrar la fila de la tabla es lo que de verdad cierra la sesión: la cookie
     * del atacante sigue existiendo en su navegador, pero ya no apunta a ningún
     * estado guardado, así que la siguiente petición llega como anónima.
     *
     * Quien llama debe además regenerar la contraseña o el identificador de sesión
     * propio; esto solo expulsa a los demás.
     */
    public function cerrarOtras(User $usuario, string $sesionActual): int
    {
        if (! $this->disponible()) {
            return 0;
        }

        return DB::table($this->tabla())
            ->where('user_id', $usuario->id)
            ->where('id', '!=', $sesionActual)
            ->delete();
    }

    public function cerrarUna(User $usuario, string $sesionId): bool
    {
        if (! $this->disponible()) {
            return false;
        }

        // El filtro por user_id no es decorativo: sin él, cualquiera podría cerrar
        // la sesión de otra persona mandando un identificador ajeno desde el
        // navegador. Es el acceso directo a objetos inseguro del OWASP Top 10.
        return DB::table($this->tabla())
            ->where('user_id', $usuario->id)
            ->where('id', $sesionId)
            ->delete() > 0;
    }

    private function tabla(): string
    {
        return (string) config('seguridad.sesiones.tabla', 'sessions');
    }

    /**
     * Descripción legible del dispositivo.
     *
     * Se hace con coincidencias simples y no con una biblioteca de análisis de
     * agentes de usuario: el dato es orientativo —lo escribe el propio cliente y
     * se puede falsificar entero— y sirve para que el usuario reconozca "ah, ese
     * soy yo desde el teléfono", no para tomar decisiones de seguridad.
     */
    private function describirDispositivo(string $agente): string
    {
        if ($agente === '') {
            return 'Dispositivo desconocido';
        }

        $sistema = match (true) {
            str_contains($agente, 'Windows') => 'Windows',
            str_contains($agente, 'Android') => 'Android',
            str_contains($agente, 'iPhone'), str_contains($agente, 'iPad') => 'iOS',
            str_contains($agente, 'Mac OS') => 'macOS',
            str_contains($agente, 'Linux') => 'Linux',
            default => 'Sistema desconocido',
        };

        $navegador = match (true) {
            str_contains($agente, 'Edg/') => 'Edge',
            str_contains($agente, 'OPR/') => 'Opera',
            str_contains($agente, 'Chrome') => 'Chrome',
            str_contains($agente, 'Firefox') => 'Firefox',
            str_contains($agente, 'Safari') => 'Safari',
            default => 'Navegador desconocido',
        };

        return $navegador.' en '.$sistema;
    }
}
