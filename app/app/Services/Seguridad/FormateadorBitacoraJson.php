<?php

declare(strict_types=1);

namespace App\Services\Seguridad;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/**
 * Formateador de la bitácora de seguridad: un objeto JSON plano por línea.
 *
 * No se usa el JsonFormatter que trae Monolog porque ese anida todo lo nuestro
 * dentro de una clave "context" y añade "message", "level_name" y "channel" al
 * mismo nivel. El SIEM tendría entonces que saber que los campos de verdad viven
 * un piso más abajo, y ese conocimiento es justo el que se pierde cuando alguien
 * cambia el canal seis meses después.
 *
 * Contrato con el panel SIEM — cada línea lleva exactamente estas claves, en este
 * orden y al nivel superior:
 *
 *   marca_tiempo, evento, usuario_id, correo, ip, agente_usuario, detalle
 *
 * y además nivel, por comodidad del analista. Si esta lista cambia, se avisa al
 * componente del SIEM antes de desplegar.
 */
class FormateadorBitacoraJson implements FormatterInterface
{
    public function format(LogRecord $registro): string
    {
        $contexto = $registro->context;

        $linea = [
            'marca_tiempo' => $contexto['marca_tiempo'] ?? $registro->datetime->format(DATE_ATOM),
            'evento' => $contexto['evento'] ?? $registro->message,
            'usuario_id' => $contexto['usuario_id'] ?? null,
            'correo' => $contexto['correo'] ?? null,
            'ip' => $contexto['ip'] ?? null,
            'agente_usuario' => $contexto['agente_usuario'] ?? null,
            'detalle' => $contexto['detalle'] ?? [],
            'nivel' => $registro->level->getName(),
        ];

        // JSON_INVALID_UTF8_SUBSTITUTE: una carga de ataque puede traer bytes que
        // no son UTF-8 válido. Sin esta bandera, json_encode devolvería false y la
        // línea se perdería en silencio, que es exactamente la línea que interesa
        // conservar. Sin JSON_PRETTY_PRINT: el contrato es una línea, un evento.
        $json = json_encode(
            $linea,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        if ($json === false) {
            $json = json_encode([
                'marca_tiempo' => $registro->datetime->format(DATE_ATOM),
                'evento' => 'bitacora_no_serializable',
                'usuario_id' => null,
                'correo' => null,
                'ip' => null,
                'agente_usuario' => null,
                'detalle' => ['evento_original' => $registro->message],
                'nivel' => $registro->level->getName(),
            ]);
        }

        return $json."\n";
    }

    /**
     * @param  array<int, LogRecord>  $registros
     */
    public function formatBatch(array $registros): string
    {
        return implode('', array_map($this->format(...), $registros));
    }
}
