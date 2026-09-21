<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\IncidenteSeo;
use App\Services\Seguridad\BitacoraSeguridad;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Punto único por el que pasa todo incidente de posicionamiento.
 *
 * Escribe en dos sitios a propósito, y no es duplicación:
 *
 *   - La tabla incidentes_seo es la que consulta el panel y la que un auditor puede pedir
 *     en una revisión. Está agregada por huella, así que una campaña de mil peticiones es
 *     una fila con contador y no mil filas iguales.
 *   - La bitácora storage/logs/seguridad.log es lo que el motor de correlación del SIEM
 *     lee línea a línea, igual que lee el registro de auditoría de ModSecurity. Es lo que
 *     permite que el evento 15021 del WAF y este evento APP-15021 aparezcan en la misma
 *     línea de tiempo, con el mismo formato, sin que el SIEM consulte otra tabla.
 *
 * Si se cae la base de datos, la bitácora sigue escribiéndose; si se rota el archivo, la
 * tabla conserva el hallazgo. Ninguna de las dos depende de la otra.
 */
class RegistroIncidentesSeo
{
    /**
     * Severidad por familia. Se decide aquí y no en cada punto de llamada porque el
     * criterio de gravedad es una política del proyecto, no una decisión del programador
     * que escribió la detección.
     *
     * @var array<string, string>
     */
    private const SEVERIDAD_POR_TIPO = [
        IncidenteSeo::TIPO_CRAWLER_FALSIFICADO => 'alta',
        IncidenteSeo::TIPO_CLOAKING => 'critica',
        IncidenteSeo::TIPO_CONTENIDO_SPAM => 'media',
        IncidenteSeo::TIPO_REDIRECCION_BLOQUEADA => 'alta',
        IncidenteSeo::TIPO_INTEGRIDAD => 'critica',
        IncidenteSeo::TIPO_SITEMAP_AJENO => 'critica',
    ];

    /**
     * Regla hermana en el WAF. El número se conserva para poder cruzar ambos registros.
     *
     * @var array<string, string>
     */
    private const REGLA_POR_TIPO = [
        IncidenteSeo::TIPO_CRAWLER_FALSIFICADO => 'APP-15021',
        IncidenteSeo::TIPO_CLOAKING => 'APP-15022',
        IncidenteSeo::TIPO_CONTENIDO_SPAM => 'APP-15031',
        IncidenteSeo::TIPO_REDIRECCION_BLOQUEADA => 'APP-15040',
        IncidenteSeo::TIPO_INTEGRIDAD => 'APP-15050',
        IncidenteSeo::TIPO_SITEMAP_AJENO => 'APP-15051',
    ];

    /** Un fragmento de evidencia largo llena el disco sin añadir información. */
    private const LIMITE_EVIDENCIA = 2000;

    /**
     * @param  array{severidad?: string, regla?: string, detalle?: array<string, mixed>, ip?: string|null, agente_usuario?: string|null, ruta?: string|null, metodo?: string|null, usuario_id?: int|string|null, agrupar_por?: string|null}  $opciones
     */
    public function registrar(string $tipo, string $resumen, array $opciones = []): ?IncidenteSeo
    {
        $ahora = Carbon::now();

        $severidad = $opciones['severidad'] ?? self::SEVERIDAD_POR_TIPO[$tipo] ?? 'media';
        $regla = $opciones['regla'] ?? self::REGLA_POR_TIPO[$tipo] ?? null;
        $detalle = $this->recortarEvidencia($opciones['detalle'] ?? []);

        // La bitácora se escribe SIEMPRE y primero. Si la base de datos está caída, el
        // ataque tiene que quedar registrado igual: el SIEM lee el archivo, no la tabla.
        $this->emitirEnBitacora($tipo, $regla, $severidad, $resumen, $detalle, $opciones);

        // La huella agrupa por día: el mismo atacante repitiendo el mismo ataque es un
        // incidente con contador, no un incidente nuevo cada segundo.
        $huella = hash('sha256', implode('|', [
            $tipo,
            $opciones['agrupar_por'] ?? ($opciones['ip'] ?? 'sin-origen'),
            $ahora->toDateString(),
        ]));

        try {
            return $this->persistir($tipo, $severidad, $regla, $resumen, $huella, $ahora, $detalle, $opciones);
        } catch (Throwable $error) {
            // Un fallo al persistir no puede tumbar la petición que se estaba sirviendo.
            // El hallazgo ya está en la bitácora; aquí solo se deja constancia del fallo.
            Log::error('No se pudo registrar el incidente de posicionamiento en la base de datos', [
                'tipo' => $tipo,
                'excepcion' => $error->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $detalle
     * @param  array<string, mixed>  $opciones
     */
    private function persistir(
        string $tipo,
        string $severidad,
        ?string $regla,
        string $resumen,
        string $huella,
        Carbon $ahora,
        array $detalle,
        array $opciones,
    ): IncidenteSeo {
        $incidente = IncidenteSeo::query()->where('huella', $huella)->first();

        if ($incidente instanceof IncidenteSeo) {
            $incidente->repeticiones++;
            $incidente->ultima_vez_en = $ahora;
            $incidente->resumen = Str::limit($resumen, 250);

            // La evidencia más reciente manda: durante una campaña interesa la última carga,
            // no la primera, porque el atacante va variando la carga hasta que algo pasa.
            $incidente->detalle = array_merge($incidente->detalle ?? [], $detalle);

            // Un incidente cerrado que vuelve a ocurrir se reabre. Cerrar algo que sigue
            // pasando es exactamente como se pierde un ataque de vista.
            if ($incidente->estado === IncidenteSeo::ESTADO_CERRADO) {
                $incidente->estado = IncidenteSeo::ESTADO_NUEVO;
            }

            $incidente->save();

            return $incidente;
        }

        return IncidenteSeo::query()->create([
            'tipo' => $tipo,
            'severidad' => $severidad,
            'regla' => $regla,
            'resumen' => Str::limit($resumen, 250),
            'direccion_ip' => $opciones['ip'] ?? null,
            'agente_usuario' => $this->recortarTexto($opciones['agente_usuario'] ?? null, 512),
            'metodo' => $opciones['metodo'] ?? null,
            'ruta' => $this->recortarTexto($opciones['ruta'] ?? null, 500),
            'usuario_id' => $opciones['usuario_id'] ?? null,
            'detalle' => $detalle,
            'estado' => IncidenteSeo::ESTADO_NUEVO,
            'repeticiones' => 1,
            'primera_vez_en' => $ahora,
            'ultima_vez_en' => $ahora,
            'huella' => $huella,
        ]);
    }

    /**
     * @param  array<string, mixed>  $detalle
     * @param  array<string, mixed>  $opciones
     */
    private function emitirEnBitacora(
        string $tipo,
        ?string $regla,
        string $severidad,
        string $resumen,
        array $detalle,
        array $opciones,
    ): void {
        $carga = [
            'usuario_id' => $opciones['usuario_id'] ?? null,
            'ip' => $opciones['ip'] ?? null,
            'agente_usuario' => $opciones['agente_usuario'] ?? null,
            'nivel' => $severidad === 'critica' ? 'critical' : 'warning',
            'detalle' => array_merge([
                'familia' => 'posicionamiento',
                'tipo' => $tipo,
                'severidad' => $severidad,
                'regla' => $regla,
                'ruta' => $opciones['ruta'] ?? null,
                'metodo' => $opciones['metodo'] ?? null,
                'resumen' => $resumen,
                // El auditor pide el control por su número, no por su nombre: A.8.16 es
                // seguimiento de actividades y A.8.9, gestión de configuraciones.
                'iso27001' => $tipo === IncidenteSeo::TIPO_INTEGRIDAD ? 'A.8.9,A.8.16,A.8.32' : 'A.8.16',
            ], $detalle),
        ];

        // El evento lleva el prefijo de la familia para que el motor de correlación agrupe
        // todo el capítulo con un solo patrón: seo_*.
        $evento = 'seo_'.$tipo;

        try {
            if (class_exists(BitacoraSeguridad::class)) {
                app(BitacoraSeguridad::class)->registrar($evento, $carga);

                return;
            }
        } catch (Throwable) {
            // Cae al respaldo de abajo: la bitácora no puede quedarse muda.
        }

        $this->emitirSinBitacora($evento, $carga);
    }

    /**
     * Respaldo si el servicio compartido de bitácora no está disponible.
     *
     * Escribe exactamente el mismo contrato de campos (marca_tiempo, evento, ip, detalle)
     * porque el motor de correlación no debe notar la diferencia. Un control que funciona
     * solo cuando todo lo demás funciona no es un control.
     *
     * @param  array<string, mixed>  $carga
     */
    private function emitirSinBitacora(string $evento, array $carga): void
    {
        $linea = json_encode([
            'marca_tiempo' => Carbon::now()->toIso8601String(),
            'evento' => $evento,
            'usuario_id' => $carga['usuario_id'] ?? null,
            'correo' => null,
            'ip' => $carga['ip'] ?? null,
            'agente_usuario' => $carga['agente_usuario'] ?? null,
            'detalle' => $carga['detalle'] ?? [],
            'nivel' => strtoupper((string) ($carga['nivel'] ?? 'warning')),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($linea === false) {
            return;
        }

        // LOCK_EX: varias peticiones concurrentes escribiendo a la vez producirían líneas
        // entrelazadas, y una línea partida por la mitad es una línea que el SIEM descarta.
        @file_put_contents(storage_path('logs/seguridad.log'), $linea."\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @param  array<string, mixed>  $detalle
     * @return array<string, mixed>
     */
    private function recortarEvidencia(array $detalle): array
    {
        foreach ($detalle as $clave => $valor) {
            if (is_string($valor) && mb_strlen($valor) > self::LIMITE_EVIDENCIA) {
                $detalle[$clave] = mb_substr($valor, 0, self::LIMITE_EVIDENCIA).'[recortado]';
            }
        }

        return $detalle;
    }

    private function recortarTexto(?string $valor, int $maximo): ?string
    {
        return $valor === null ? null : mb_substr($valor, 0, $maximo);
    }
}
