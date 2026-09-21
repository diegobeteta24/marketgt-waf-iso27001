<?php

declare(strict_types=1);

namespace App\Services\Seguridad;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bitácora de seguridad de la aplicación.
 *
 * Escribe un objeto JSON por línea en storage/logs/seguridad.log a través del
 * canal de registro "seguridad". El panel SIEM lee ese archivo con la misma
 * rutina con la que lee el registro de auditoría de ModSecurity, así que el
 * contrato de campos que hay aquí abajo no se cambia sin avisar al SIEM.
 *
 * Campos garantizados en cada línea:
 *   marca_tiempo, evento, usuario_id, correo, ip, agente_usuario, detalle
 */
class BitacoraSeguridad
{
    /**
     * @param  array{usuario_id?: int|null, correo?: string|null, ip?: string|null, agente_usuario?: string|null, detalle?: array<string, mixed>, nivel?: string}  $datos
     */
    public function registrar(string $evento, array $datos = []): void
    {
        $linea = [
            // ISO-8601 con zona: el SIEM cruza esta línea con la del WAF, que está
            // en UTC. Sin zona explícita, una diferencia de seis horas convierte
            // dos eventos del mismo ataque en dos incidentes distintos.
            'marca_tiempo' => now()->toIso8601String(),
            'evento' => $evento,
            'usuario_id' => $datos['usuario_id'] ?? $this->usuarioActual(),
            'correo' => $datos['correo'] ?? $this->correoActual(),
            'ip' => $datos['ip'] ?? $this->ipActual(),
            'agente_usuario' => $this->recortar($datos['agente_usuario'] ?? $this->agenteActual()),
            'detalle' => $datos['detalle'] ?? [],
        ];

        $nivel = $datos['nivel'] ?? 'info';

        try {
            // El mensaje va vacío y todo el contenido viaja en el contexto: con el
            // formateador JSON de Monolog, el contexto es un objeto anidado y no
            // una cadena escapada, que es lo que el SIEM puede leer sin adivinar.
            Log::channel($this->canal())->log($nivel, $evento, $linea);
        } catch (Throwable $error) {
            // Que no se pueda escribir la bitácora no puede tumbar un inicio de
            // sesión: el control de disponibilidad manda sobre el de trazabilidad.
            // El fallo se deja en el registro general para que no pase inadvertido:
            // una bitácora silenciosamente muerta es peor que no tenerla, porque se
            // sigue confiando en ella.
            Log::error('No se pudo escribir en la bitácora de seguridad', [
                'evento' => $evento,
                'excepcion' => $error->getMessage(),
            ]);
        }
    }

    /**
     * Canal de destino, con red de seguridad.
     *
     * Si el canal todavía no está declarado en config/logging.php —es uno de los
     * registros que el integrador tiene que hacer— se define aquí en memoria con
     * exactamente la misma definición. La bitácora de seguridad no puede quedarse
     * muda por un paso de integración olvidado: un control que falla en silencio es
     * peor que no tenerlo, porque se sigue confiando en él. Cuando el canal sí
     * exista en la configuración, manda esa y esto no se ejecuta.
     */
    private function canal(): string
    {
        $canal = (string) config('seguridad.bitacora.canal', 'seguridad');

        if (config('logging.channels.'.$canal) === null) {
            config(['logging.channels.'.$canal => [
                'driver' => 'single',
                'path' => storage_path('logs/seguridad.log'),
                'level' => 'debug',
                'formatter' => FormateadorBitacoraJson::class,
                // La bitácora lleva correos, direcciones IP y agentes de usuario.
                // Con los permisos por omisión la lee cualquier cuenta del sistema,
                // y eso es un hallazgo de auditoría por sí solo.
                'permission' => 0640,
            ]]);
        }

        return $canal;
    }

    private function usuarioActual(): ?int
    {
        $usuario = auth()->user();

        return $usuario?->getAuthIdentifier() === null ? null : (int) $usuario->getAuthIdentifier();
    }

    private function correoActual(): ?string
    {
        $usuario = auth()->user();

        return $usuario?->getAttribute('email');
    }

    /**
     * La IP real depende de que los proxies de confianza estén configurados en
     * bootstrap/app.php. Sin eso, aquí siempre saldría la IP del contenedor de
     * Nginx y la bitácora entera quedaría inservible para investigar.
     */
    private function ipActual(): ?string
    {
        return request()->ip();
    }

    private function agenteActual(): ?string
    {
        return request()->userAgent();
    }

    private function recortar(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        // Un agente de usuario de 40 KB es una técnica conocida para inflar los
        // registros hasta llenar el disco y detener el servicio.
        return Str::limit($valor, (int) config('seguridad.bitacora.limite_agente_usuario', 512), '');
    }
}
