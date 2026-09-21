<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Seguridad\Auditor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deja un asiento de auditoría por cada petición que cambia estado.
 *
 * Se registra DESPUÉS de ejecutar la petición porque el código de respuesta es
 * parte del hecho auditado: un POST que terminó en 422 es un intento fallido y un
 * POST que terminó en 302 es un cambio consumado. Sin el código, la tabla no
 * distingue entre lo que alguien intentó y lo que alguien logró.
 *
 * Se aplica por ruta, no al grupo web entero: auditarlo todo produce miles de
 * asientos de Livewire por sesión y entierra lo que importa.
 */
class RegistrarAuditoria
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * El primer parámetro opcional permite nombrar la acción desde la ruta:
     *   ->middleware('auditoria:pedido_confirmado')
     */
    public function handle(Request $peticion, Closure $siguiente, ?string $accion = null): Response
    {
        /** @var Response $respuesta */
        $respuesta = $siguiente($peticion);

        if (! $this->debeAuditar($peticion)) {
            return $respuesta;
        }

        $this->auditor->registrar($accion ?? 'peticion_sensible', [
            'metodo' => $peticion->method(),
            'ruta' => $peticion->path(),
            'codigo_respuesta' => $respuesta->getStatusCode(),
            'resultado' => $respuesta->isSuccessful() || $respuesta->isRedirection() ? 'exito' : 'fallo',
            'tipo_recurso' => 'ruta',
            'identificador_recurso' => $peticion->route()?->getName(),

            // El Auditor censura las contraseñas y demás secretos antes de
            // escribir. Se le pasan solo las claves, nunca los valores: para
            // auditar basta saber qué campos se enviaron; guardar su contenido
            // convertiría esta tabla en una copia en claro de todo el formulario.
            'detalle' => ['campos' => array_keys($peticion->except(['_token'])) ],
        ]);

        return $respuesta;
    }

    private function debeAuditar(Request $peticion): bool
    {
        /** @var array<int, string> $metodos */
        $metodos = config('seguridad.auditoria.metodos', ['POST', 'PUT', 'PATCH', 'DELETE']);

        if (! in_array($peticion->method(), $metodos, true)) {
            return false;
        }

        /** @var array<int, string> $ignoradas */
        $ignoradas = config('seguridad.auditoria.rutas_ignoradas', []);

        return ! $peticion->is(...$ignoradas);
    }
}
