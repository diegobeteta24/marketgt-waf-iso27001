<?php

namespace App\Services\Siem;

use App\Models\EventoSeguridad;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Throwable;

/**
 * Traduce las tres fuentes de registro del proyecto a la forma unica de eventos_seguridad.
 *
 * Correlacionar exige comparar peras con peras: mientras el WAF hable de "is_interrupted",
 * Laravel de "level" y fail2ban de "Ban", ninguna regla puede cruzar las tres capas. Esta
 * clase es el unico lugar del componente que conoce los formatos crudos.
 */
class Normalizador
{
    /**
     * Longitud maxima de la carga util que se guarda. Un cuerpo de peticion puede pesar
     * megabytes; para la evidencia basta la cabeza del ataque y evita inflar la base.
     */
    private const LIMITE_CARGA_UTIL = 4000;

    /**
     * Severidad numerica de ModSecurity a la escala del panel.
     * 0 EMERGENCY, 1 ALERT, 2 CRITICAL, 3 ERROR, 4 WARNING, 5 NOTICE, 6 INFO, 7 DEBUG.
     *
     * @var array<int, string>
     */
    private const SEVERIDAD_MODSECURITY = [
        0 => EventoSeguridad::SEVERIDAD_CRITICA,
        1 => EventoSeguridad::SEVERIDAD_CRITICA,
        2 => EventoSeguridad::SEVERIDAD_CRITICA,
        3 => EventoSeguridad::SEVERIDAD_ALTA,
        4 => EventoSeguridad::SEVERIDAD_MEDIA,
        5 => EventoSeguridad::SEVERIDAD_BAJA,
        6 => EventoSeguridad::SEVERIDAD_INFORMATIVA,
        7 => EventoSeguridad::SEVERIDAD_INFORMATIVA,
    ];

    /**
     * Puntos que el Core Rule Set suma por cada regla segun su severidad. Se usa solo como
     * respaldo cuando el registro no trae la regla 949110/980130 con el total ya calculado.
     *
     * @var array<int, int>
     */
    private const PUNTOS_CRS = [
        0 => 5,
        1 => 5,
        2 => 5,
        3 => 4,
        4 => 3,
        5 => 2,
        6 => 0,
        7 => 0,
    ];

    /**
     * Claves cuyo valor jamas debe quedar escrito en la base de evidencia. Guardar la
     * contrasena que intento un atacante convertiria el propio SIEM en una fuga de datos.
     *
     * @var array<int, string>
     */
    private const CLAVES_SENSIBLES = [
        'password',
        'password_confirmation',
        'contrasena',
        'contrasenia',
        'clave',
        'token',
        'secret',
        'authorization',
        'cookie',
        'two_factor_secret',
        'code',
    ];

    /**
     * Normaliza un objeto del registro de auditoria JSON de ModSecurity.
     *
     * @param  array<string, mixed>  $registro
     * @return array<string, mixed>|null Nulo si la linea no tiene la forma esperada.
     */
    public function desdeAuditoriaWaf(array $registro): ?array
    {
        $transaccion = $registro['transaction'] ?? null;

        if (! is_array($transaccion)) {
            return null;
        }

        $peticion = is_array($transaccion['request'] ?? null) ? $transaccion['request'] : [];
        $respuesta = is_array($transaccion['response'] ?? null) ? $transaccion['response'] : [];
        $encabezados = is_array($peticion['headers'] ?? null) ? $peticion['headers'] : [];
        $mensajes = is_array($transaccion['messages'] ?? null) ? $transaccion['messages'] : [];

        $direccionIp = $this->textoPlano($transaccion['client_ip'] ?? null);

        if ($direccionIp === null) {
            return null;
        }

        $reglas = [];
        $etiquetas = [];
        $severidades = [];
        $textos = [];

        foreach ($mensajes as $mensaje) {
            if (! is_array($mensaje)) {
                continue;
            }

            $detalles = is_array($mensaje['details'] ?? null) ? $mensaje['details'] : [];

            $identificador = $this->textoPlano($detalles['ruleId'] ?? null);

            if ($identificador !== null) {
                $reglas[] = $identificador;
            }

            if (isset($detalles['severity']) && is_numeric($detalles['severity'])) {
                $severidades[] = (int) $detalles['severity'];
            }

            foreach ((array) ($detalles['tags'] ?? []) as $etiqueta) {
                $etiquetaPlana = $this->textoPlano($etiqueta);

                if ($etiquetaPlana !== null) {
                    $etiquetas[] = $etiquetaPlana;
                }
            }

            $texto = $this->textoPlano($mensaje['message'] ?? null);

            if ($texto !== null) {
                $textos[] = $texto;
            }

            $datos = $this->textoPlano($detalles['data'] ?? null);

            if ($datos !== null) {
                $textos[] = $datos;
            }
        }

        $reglas = array_values(array_unique($reglas));
        $etiquetas = array_values(array_unique($etiquetas));

        $puntuacion = $this->extraerPuntuacionAnomalia($textos, $reglas, $severidades);
        $bloqueado = $this->esInterrumpida($transaccion, $respuesta);

        $marcaTiempo = $this->interpretarFechaWaf($this->textoPlano($transaccion['time_stamp'] ?? null));
        $ruta = $this->textoPlano($peticion['uri'] ?? null);
        $identificadorTransaccion = $this->textoPlano($transaccion['unique_id'] ?? null);

        return [
            'fuente' => EventoSeguridad::FUENTE_WAF,
            'subfuente' => 'modsecurity',
            'marca_tiempo' => $marcaTiempo,
            'direccion_ip' => Str::limit($direccionIp, 45, ''),
            'pais' => $this->paisDesdeEncabezados($encabezados),
            'metodo' => Str::upper(Str::limit((string) ($this->textoPlano($peticion['method'] ?? null) ?? ''), 10, '')) ?: null,
            'ruta' => $ruta,
            'codigo_respuesta' => is_numeric($respuesta['http_code'] ?? null) ? (int) $respuesta['http_code'] : null,
            'identificador_transaccion' => $identificadorTransaccion,
            'identificadores_regla' => $reglas,
            'puntuacion_anomalia' => $puntuacion,
            'severidad' => $this->severidadWaf($severidades, $puntuacion, $bloqueado),
            'etiquetas' => $etiquetas,
            'mensaje' => $textos === [] ? 'Transaccion registrada por el WAF' : Str::limit(implode(' | ', array_slice($textos, 0, 5)), 1000),
            'carga_util' => $this->depurarCargaUtil($this->textoPlano($peticion['body'] ?? null)),
            'fue_bloqueado' => $bloqueado,
            'usuario_id' => null,
            'agente_usuario' => $this->encabezado($encabezados, 'user-agent'),
            'es_demostracion' => false,
            'huella' => $this->huella([
                EventoSeguridad::FUENTE_WAF,
                $identificadorTransaccion ?? $direccionIp.$ruta.$marcaTiempo->toIso8601String(),
            ]),
        ];
    }

    /**
     * Normaliza una linea del registro de la aplicacion Laravel (canal single).
     * Formato: [2026-09-21 10:00:00] local.WARNING: mensaje {"contexto":"json"}
     *
     * @return array<string, mixed>|null
     */
    public function desdeRegistroAplicacion(string $linea): ?array
    {
        $patron = '/^\[(?P<fecha>[^\]]+)\]\s+(?P<canal>[\w\-]+)\.(?P<nivel>[A-Z]+):\s+(?P<cuerpo>.*)$/s';

        if (preg_match($patron, trim($linea), $coincidencias) !== 1) {
            return null;
        }

        $cuerpo = trim($coincidencias['cuerpo']);
        $contexto = $this->extraerContextoJson($cuerpo);
        $mensaje = trim(Str::before($cuerpo, '{'));

        if ($mensaje === '') {
            $mensaje = $cuerpo;
        }

        $nivel = Str::upper($coincidencias['nivel']);
        $etiquetas = $this->etiquetarMensajeAplicacion($mensaje, $contexto);

        $direccionIp = $this->textoPlano($contexto['ip'] ?? $contexto['direccion_ip'] ?? $contexto['client_ip'] ?? null) ?? '0.0.0.0';
        $marcaTiempo = $this->interpretarFecha($coincidencias['fecha']);

        return [
            'fuente' => EventoSeguridad::FUENTE_APLICACION,
            'subfuente' => 'laravel',
            'marca_tiempo' => $marcaTiempo,
            'direccion_ip' => Str::limit($direccionIp, 45, ''),
            'pais' => null,
            'metodo' => $this->textoPlano($contexto['metodo'] ?? $contexto['method'] ?? null),
            'ruta' => $this->textoPlano($contexto['ruta'] ?? $contexto['url'] ?? $contexto['path'] ?? null),
            'codigo_respuesta' => is_numeric($contexto['codigo'] ?? null) ? (int) $contexto['codigo'] : null,
            'identificador_transaccion' => null,
            'identificadores_regla' => [],
            'puntuacion_anomalia' => 0,
            'severidad' => $this->severidadAplicacion($nivel, $etiquetas),
            'etiquetas' => $etiquetas,
            'mensaje' => Str::limit($mensaje, 1000),
            'carga_util' => $this->depurarCargaUtil($contexto === [] ? null : json_encode($contexto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'fue_bloqueado' => false,
            'usuario_id' => is_numeric($contexto['usuario_id'] ?? $contexto['user_id'] ?? null)
                ? (int) ($contexto['usuario_id'] ?? $contexto['user_id'])
                : null,
            'agente_usuario' => $this->textoPlano($contexto['agente'] ?? $contexto['user_agent'] ?? null),
            'es_demostracion' => false,
            'huella' => $this->huella([
                EventoSeguridad::FUENTE_APLICACION,
                $marcaTiempo->toIso8601String(),
                $direccionIp,
                $mensaje,
            ]),
        ];
    }

    /**
     * Normaliza una linea del sistema operativo: fail2ban o el acceso remoto por SSH.
     *
     * @return array<string, mixed>|null
     */
    public function desdeRegistroSistema(string $linea): ?array
    {
        $linea = trim($linea);

        if ($linea === '') {
            return null;
        }

        // fail2ban: 2026-09-21 10:00:00,123 fail2ban.actions [999]: NOTICE  [sshd] Ban 203.0.113.5
        $patronFail2ban = '/^(?P<fecha>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:,\d+)?\s+fail2ban\.\S+\s+\[\d+\]:\s+(?P<nivel>[A-Z]+)\s+\[(?P<carcel>[^\]]+)\]\s+(?P<accion>\w+)\s+(?P<ip>[0-9a-fA-F\.:]+)/';

        if (preg_match($patronFail2ban, $linea, $c) === 1) {
            $accion = Str::lower($c['accion']);
            $marcaTiempo = $this->interpretarFecha($c['fecha']);

            return [
                'fuente' => EventoSeguridad::FUENTE_SISTEMA,
                'subfuente' => 'fail2ban',
                'marca_tiempo' => $marcaTiempo,
                'direccion_ip' => $c['ip'],
                'pais' => null,
                'metodo' => null,
                'ruta' => null,
                'codigo_respuesta' => null,
                'identificador_transaccion' => null,
                'identificadores_regla' => [],
                'puntuacion_anomalia' => 0,
                // Un bloqueo de fail2ban ya es una contencion automatica de la capa 2: es
                // informacion de alto valor, no un aviso mas.
                'severidad' => $accion === 'ban' ? EventoSeguridad::SEVERIDAD_ALTA : EventoSeguridad::SEVERIDAD_BAJA,
                'etiquetas' => ['sistema', 'fail2ban', 'carcel.'.Str::slug($c['carcel']), 'accion.'.$accion],
                'mensaje' => Str::limit($linea, 1000),
                'carga_util' => null,
                'fue_bloqueado' => $accion === 'ban',
                'usuario_id' => null,
                'agente_usuario' => null,
                'es_demostracion' => false,
                'huella' => $this->huella([EventoSeguridad::FUENTE_SISTEMA, 'fail2ban', $marcaTiempo->toIso8601String(), $c['ip'], $accion]),
            ];
        }

        // sshd: Sep 21 10:00:00 servidor sshd[123]: Failed password for invalid user root from 203.0.113.5 port 5234 ssh2
        $patronSsh = '/^(?P<fecha>[A-Z][a-z]{2}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2})\s+\S+\s+sshd\[\d+\]:\s+(?P<cuerpo>.*)$/';

        if (preg_match($patronSsh, $linea, $c) === 1) {
            $cuerpo = $c['cuerpo'];
            $fallo = Str::startsWith($cuerpo, ['Failed', 'Invalid user', 'error:']);

            preg_match('/from (?P<ip>[0-9a-fA-F\.:]+)/', $cuerpo, $coincidenciaIp);
            $direccionIp = $coincidenciaIp['ip'] ?? '0.0.0.0';

            // El registro de syslog no lleva anio; se asume el del reloj del servidor, que es
            // lo correcto salvo en el cruce de ano, caso que se documenta y se acepta.
            $marcaTiempo = $this->interpretarFecha($c['fecha'].' '.CarbonImmutable::now()->year);

            return [
                'fuente' => EventoSeguridad::FUENTE_SISTEMA,
                'subfuente' => 'sshd',
                'marca_tiempo' => $marcaTiempo,
                'direccion_ip' => $direccionIp,
                'pais' => null,
                'metodo' => null,
                'ruta' => null,
                'codigo_respuesta' => null,
                'identificador_transaccion' => null,
                'identificadores_regla' => [],
                'puntuacion_anomalia' => 0,
                'severidad' => $fallo ? EventoSeguridad::SEVERIDAD_MEDIA : EventoSeguridad::SEVERIDAD_INFORMATIVA,
                'etiquetas' => array_values(array_filter(['sistema', 'ssh', $fallo ? 'acceso.fallido' : 'acceso.correcto'])),
                'mensaje' => Str::limit($cuerpo, 1000),
                'carga_util' => null,
                'fue_bloqueado' => false,
                'usuario_id' => null,
                'agente_usuario' => null,
                'es_demostracion' => false,
                'huella' => $this->huella([EventoSeguridad::FUENTE_SISTEMA, 'sshd', $marcaTiempo->toIso8601String(), $direccionIp, $cuerpo]),
            ];
        }

        return null;
    }

    /**
     * La puntuacion de anomalia es el numero que decide el bloqueo con umbral 5. Se toma del
     * propio registro cuando el CRS lo publica (949110 / 980130) y solo se reconstruye
     * sumando severidades cuando no viene, para no inventar un valor distinto al del WAF.
     *
     * @param  array<int, string>  $textos
     * @param  array<int, string>  $reglas
     * @param  array<int, int>  $severidades
     */
    private function extraerPuntuacionAnomalia(array $textos, array $reglas, array $severidades): int
    {
        foreach ($textos as $texto) {
            if (preg_match('/(?:total\s+(?:inbound\s+)?score|anomaly\s+score)[^\d]{0,20}(\d{1,4})/i', $texto, $c) === 1) {
                return min((int) $c[1], 65535);
            }

            if (preg_match('/matched data:\s*(\d{1,4})\s*found within tx:anomaly_score/i', $texto, $c) === 1) {
                return min((int) $c[1], 65535);
            }
        }

        $total = 0;

        foreach ($severidades as $indice => $severidad) {
            $regla = (int) ($reglas[$indice] ?? 0);

            // Las reglas de evaluacion (9491xx) y las de registro final (980xxx) no suman
            // puntos: solo informan del total. Contarlas duplicaria la puntuacion.
            if (($regla >= 949000 && $regla < 950000) || ($regla >= 980000 && $regla < 981000)) {
                continue;
            }

            $total += self::PUNTOS_CRS[$severidad] ?? 0;
        }

        return min($total, 65535);
    }

    /**
     * @param  array<int, int>  $severidades
     */
    private function severidadWaf(array $severidades, int $puntuacion, bool $bloqueado): string
    {
        $porRegla = EventoSeguridad::SEVERIDAD_INFORMATIVA;

        if ($severidades !== []) {
            $porRegla = self::SEVERIDAD_MODSECURITY[min($severidades)] ?? EventoSeguridad::SEVERIDAD_INFORMATIVA;
        }

        // La puntuacion acumulada tambien eleva la severidad: quince puntos son tres reglas
        // criticas encadenadas, aunque cada una por separado se registre como "warning".
        $porPuntuacion = match (true) {
            $puntuacion >= 25 => EventoSeguridad::SEVERIDAD_CRITICA,
            $puntuacion >= 15 => EventoSeguridad::SEVERIDAD_ALTA,
            $puntuacion >= 5 => EventoSeguridad::SEVERIDAD_MEDIA,
            $puntuacion > 0 => EventoSeguridad::SEVERIDAD_BAJA,
            default => EventoSeguridad::SEVERIDAD_INFORMATIVA,
        };

        $resultado = $this->mayorSeveridad($porRegla, $porPuntuacion);

        // Una transaccion que el WAF corto nunca puede quedar como informativa en el panel.
        if ($bloqueado) {
            $resultado = $this->mayorSeveridad($resultado, EventoSeguridad::SEVERIDAD_MEDIA);
        }

        return $resultado;
    }

    /**
     * @param  array<int, string>  $etiquetas
     */
    private function severidadAplicacion(string $nivel, array $etiquetas): string
    {
        $base = match ($nivel) {
            'EMERGENCY', 'ALERT', 'CRITICAL' => EventoSeguridad::SEVERIDAD_CRITICA,
            'ERROR' => EventoSeguridad::SEVERIDAD_ALTA,
            'WARNING' => EventoSeguridad::SEVERIDAD_MEDIA,
            'NOTICE', 'INFO' => EventoSeguridad::SEVERIDAD_BAJA,
            default => EventoSeguridad::SEVERIDAD_INFORMATIVA,
        };

        // Un fallo de segundo factor significa que la contrasena ya era correcta: eso es
        // una cuenta comprometida a medias y merece mas atencion que un aviso cualquiera.
        if (in_array('segundo_factor.fallido', $etiquetas, true)) {
            return $this->mayorSeveridad($base, EventoSeguridad::SEVERIDAD_ALTA);
        }

        if (in_array('autenticacion.fallida', $etiquetas, true)) {
            return $this->mayorSeveridad($base, EventoSeguridad::SEVERIDAD_MEDIA);
        }

        return $base;
    }

    private function mayorSeveridad(string $primera, string $segunda): string
    {
        $posicionPrimera = array_search($primera, EventoSeguridad::ESCALA_SEVERIDAD, true);
        $posicionSegunda = array_search($segunda, EventoSeguridad::ESCALA_SEVERIDAD, true);

        if ($posicionPrimera === false) {
            return $segunda;
        }

        if ($posicionSegunda === false) {
            return $primera;
        }

        return $posicionPrimera <= $posicionSegunda ? $primera : $segunda;
    }

    /**
     * Las etiquetas son el vocabulario que usan las reglas de correlacion. Se derivan aqui
     * una sola vez para que el motor no tenga que hacer coincidencias de texto libre.
     *
     * @param  array<string, mixed>  $contexto
     * @return array<int, string>
     */
    private function etiquetarMensajeAplicacion(string $mensaje, array $contexto): array
    {
        $etiquetas = ['aplicacion'];
        $texto = Str::lower($mensaje.' '.implode(' ', array_map(
            static fn (mixed $valor): string => is_scalar($valor) ? (string) $valor : '',
            $contexto,
        )));

        $indicaFallo = (bool) preg_match('/fall|invalid|incorrect|denegad|rechazad|failed|throttle|bloque/u', $texto);
        $indicaSegundoFactor = (bool) preg_match('/segundo factor|dos factores|two.?factor|2fa|totp|codigo de recuperacion|recovery.?code/u', $texto);
        $indicaSesion = (bool) preg_match('/login|inicio de sesion|sesion|credencial|auth|contrasena|password/u', $texto);

        if ($indicaSegundoFactor) {
            $etiquetas[] = $indicaFallo ? 'segundo_factor.fallido' : 'segundo_factor.correcto';
        } elseif ($indicaSesion) {
            $etiquetas[] = $indicaFallo ? 'autenticacion.fallida' : 'autenticacion.correcta';
        }

        if (preg_match('/autoriza|permiso|403|forbidden/u', $texto) === 1) {
            $etiquetas[] = 'autorizacion.denegada';
        }

        return array_values(array_unique($etiquetas));
    }

    /**
     * @param  array<string, mixed>  $encabezados
     */
    private function paisDesdeEncabezados(array $encabezados): ?string
    {
        // Cloudflare (capa 1) inyecta CF-IPCountry. Es la unica inferencia de pais que el
        // proyecto puede sostener ante un auditor: viene del perimetro, no de una suposicion.
        $pais = $this->encabezado($encabezados, 'cf-ipcountry');

        if ($pais === null || strlen($pais) !== 2 || $pais === 'XX') {
            return null;
        }

        return Str::upper($pais);
    }

    /**
     * @param  array<string, mixed>  $encabezados
     */
    private function encabezado(array $encabezados, string $nombre): ?string
    {
        foreach ($encabezados as $clave => $valor) {
            if (is_string($clave) && Str::lower($clave) === $nombre) {
                return $this->textoPlano(is_array($valor) ? ($valor[0] ?? null) : $valor);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $transaccion
     * @param  array<string, mixed>  $respuesta
     */
    private function esInterrumpida(array $transaccion, array $respuesta): bool
    {
        if (array_key_exists('is_interrupted', $transaccion)) {
            return filter_var($transaccion['is_interrupted'], FILTER_VALIDATE_BOOLEAN);
        }

        return (int) ($respuesta['http_code'] ?? 0) === 403;
    }

    /**
     * @return array<string, mixed>
     */
    private function extraerContextoJson(string $cuerpo): array
    {
        $inicio = strpos($cuerpo, '{');

        if ($inicio === false) {
            return [];
        }

        $posible = substr($cuerpo, $inicio);
        $decodificado = json_decode($posible, true);

        return is_array($decodificado) ? $decodificado : [];
    }

    /**
     * Recorta la carga util y tacha los valores sensibles antes de persistirlos.
     */
    private function depurarCargaUtil(?string $carga): ?string
    {
        if ($carga === null || trim($carga) === '') {
            return null;
        }

        $patron = '/("|\b)('.implode('|', array_map('preg_quote', self::CLAVES_SENSIBLES)).')("?\s*[:=]\s*)("[^"]*"|[^&"\s,}]+)/i';

        $depurada = preg_replace($patron, '$1$2$3"[REDACTADO]"', $carga) ?? $carga;

        return Str::limit($depurada, self::LIMITE_CARGA_UTIL);
    }

    /**
     * @param  array<int, string|null>  $partes
     */
    private function huella(array $partes): string
    {
        return hash('sha256', implode('|', array_map(static fn (?string $parte): string => (string) $parte, $partes)));
    }

    private function interpretarFechaWaf(?string $valor): CarbonInterface
    {
        if ($valor === null) {
            return CarbonImmutable::now();
        }

        // ModSecurity escribe la fecha al estilo de Apache: "Mon 21 Sep 2026 10:00:00.123456".
        $normalizada = preg_replace('/\.(\d+)$/', '', trim($valor)) ?? $valor;

        return $this->interpretarFecha($normalizada);
    }

    private function interpretarFecha(string $valor): CarbonInterface
    {
        try {
            return CarbonImmutable::parse($valor);
        } catch (Throwable) {
            // Una fecha ilegible no puede tumbar la ingesta; se registra con la hora de proceso
            // y la linea cruda queda en el mensaje para poder reconstruirla a mano.
            return CarbonImmutable::now();
        }
    }

    private function textoPlano(mixed $valor): ?string
    {
        if ($valor === null || is_array($valor) || is_object($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
