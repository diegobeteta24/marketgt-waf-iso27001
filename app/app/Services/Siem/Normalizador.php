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
     * Normaliza una linea del registro de la aplicacion Laravel.
     *
     * Se aceptan las dos formas que el proyecto escribe de verdad, porque cual de ellas
     * llega depende de a que archivo apunte config/siem.php:
     *   - Bitacora de seguridad (storage/logs/seguridad.log), un objeto JSON plano por
     *     linea segun el contrato de App\Services\Seguridad\FormateadorBitacoraJson:
     *     {"marca_tiempo":"...","evento":"...","usuario_id":1,"ip":"...","detalle":{...}}
     *   - Canal general de Laravel con el formateador de linea de Monolog:
     *     [2026-09-21 10:00:00] local.WARNING: mensaje {"contexto":"json"}
     *
     * Entender solo una de las dos dejaba ciegas a las reglas de fuerza bruta y de segundo
     * factor: sus eventos viven en la bitacora, que es la que va en JSON.
     *
     * @return array<string, mixed>|null
     */
    public function desdeRegistroAplicacion(string $linea): ?array
    {
        $linea = trim($linea);

        if ($linea === '') {
            return null;
        }

        if (str_starts_with($linea, '{')) {
            return $this->desdeBitacoraSeguridad($linea);
        }

        $patron = '/^\[(?P<fecha>[^\]]+)\]\s+(?P<canal>[\w\-]+)\.(?P<nivel>[A-Z]+):\s+(?P<cuerpo>.*)$/s';

        if (preg_match($patron, $linea, $coincidencias) !== 1) {
            return null;
        }

        $cuerpo = trim($coincidencias['cuerpo']);
        $contexto = $this->aplanarContexto($this->extraerContextoJson($cuerpo));
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
            // La huella se calcula sobre la linea entera y no sobre fecha + IP + mensaje:
            // durante una rafaga de fuerza bruta caben varios intentos en el mismo segundo
            // con el mismo texto, y una huella mas corta los habria fundido en uno solo,
            // hundiendo justo el conteo que la regla de fuerza bruta necesita.
            'huella' => $this->huella([EventoSeguridad::FUENTE_APLICACION, 'linea', $linea]),
        ];
    }

    /**
     * Bitacora de seguridad de la aplicacion: un objeto JSON plano por linea con las claves
     * marca_tiempo, evento, usuario_id, correo, ip, agente_usuario, detalle y nivel.
     *
     * @return array<string, mixed>|null
     */
    private function desdeBitacoraSeguridad(string $linea): ?array
    {
        $registro = json_decode($linea, true);

        if (! is_array($registro)) {
            return null;
        }

        $evento = $this->textoPlano($registro['evento'] ?? null);

        // Sin nombre de evento la linea no es de la bitacora: puede ser cualquier otro JSON
        // que alguien haya volcado en el archivo, y adivinarlo produciria eventos falsos.
        if ($evento === null) {
            return null;
        }

        $contexto = $this->aplanarContexto($registro);
        $nivel = Str::upper($this->textoPlano($registro['nivel'] ?? null) ?? 'INFO');

        // Las etiquetas salen solo del nombre del evento, no del detalle. El nombre es un
        // identificador que la bitacora garantiza por contrato; el detalle lleva la ruta, y
        // un 403 de rol sobre /user/two-factor-authentication habria quedado etiquetado como
        // fallo de segundo factor, que es la regla mas grave de las siete. Una alerta critica
        // falsa cuesta mas credibilidad que un evento sin etiquetar.
        $etiquetas = $this->etiquetarMensajeAplicacion($evento, []);

        $direccionIp = $this->textoPlano($registro['ip'] ?? null) ?? '0.0.0.0';

        $fecha = $this->textoPlano($registro['marca_tiempo'] ?? null);
        $marcaTiempo = $fecha === null ? CarbonImmutable::now() : $this->interpretarFecha($fecha);

        $detalle = is_array($registro['detalle'] ?? null) ? $registro['detalle'] : [];

        return [
            'fuente' => EventoSeguridad::FUENTE_APLICACION,
            'subfuente' => 'bitacora',
            'marca_tiempo' => $marcaTiempo,
            'direccion_ip' => Str::limit($direccionIp, 45, ''),
            'pais' => null,
            'metodo' => Str::upper(Str::limit((string) ($this->textoPlano($contexto['metodo'] ?? $contexto['method'] ?? null) ?? ''), 10, '')) ?: null,
            'ruta' => $this->textoPlano($contexto['ruta'] ?? $contexto['url'] ?? $contexto['path'] ?? null),
            'codigo_respuesta' => is_numeric($contexto['codigo'] ?? null) ? (int) $contexto['codigo'] : null,
            'identificador_transaccion' => null,
            'identificadores_regla' => [],
            'puntuacion_anomalia' => 0,
            'severidad' => $this->severidadAplicacion($nivel, $etiquetas),
            'etiquetas' => $etiquetas,
            'mensaje' => Str::limit($evento, 1000),
            'carga_util' => $this->depurarCargaUtil($detalle === [] ? null : json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'fue_bloqueado' => false,
            'usuario_id' => is_numeric($registro['usuario_id'] ?? null) ? (int) $registro['usuario_id'] : null,
            'agente_usuario' => $this->textoPlano($registro['agente_usuario'] ?? null),
            'es_demostracion' => false,
            // Misma razon que en el formato de linea: la bitacora solo tiene resolucion de
            // segundo, asi que la linea completa es lo unico que distingue dos intentos.
            'huella' => $this->huella([EventoSeguridad::FUENTE_APLICACION, 'bitacora', $linea]),
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

        // EN MODO DE PUNTUACION DE ANOMALIA, LA SEVERIDAD DE UNA REGLA ES UN PESO, NO UN VEREDICTO.
        //
        // El Core Rule Set etiqueta casi todas sus reglas de ataque como CRITICAL. Esa etiqueta
        // no dice "esto es critico": dice cuanto suma la regla a la puntuacion (CRITICAL = 5).
        // El veredicto es la puntuacion total contra el umbral, y lo emite el propio WAF al
        // bloquear o no.
        //
        // Una peticion que el WAF NO corto es, por definicion, una que no alcanzo el umbral:
        // tipicamente una coincidencia de paranoia 2, que este despliegue registra a proposito
        // para tener senal sin bloquear trafico legitimo. Tomar su etiqueta como severidad la
        // convertia en un incidente critico, y cada sondeo automatizado de Internet abria su
        // propia alerta critica: cientos al dia, ninguna critica de verdad. Un panel que grita
        // "critico" cuatrocientas veces al dia entrena al equipo a no mirarlo.
        //
        // Sin bloqueo manda la puntuacion. Si esa puntuacion es alta —una carga que paso porque
        // el motor estaba en solo deteccion, por ejemplo— sigue saliendo critica por ella misma.
        if (! $bloqueado) {
            return $this->mayorSeveridad(
                $porPuntuacion,
                $severidades !== [] ? EventoSeguridad::SEVERIDAD_BAJA : EventoSeguridad::SEVERIDAD_INFORMATIVA,
            );
        }

        // Con bloqueo, la etiqueta de la regla si cuenta: el WAF confirmo que era un ataque. Y
        // una transaccion cortada nunca puede quedar como informativa en el panel.
        return $this->mayorSeveridad(
            $this->mayorSeveridad($porRegla, $porPuntuacion),
            EventoSeguridad::SEVERIDAD_MEDIA,
        );
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

        // Los separadores van como clase y no como espacio literal porque los nombres de
        // evento de la bitacora usan guion bajo ("segundo_factor_fallido"). Con un espacio
        // fijo, la regla de segundo factor -la mas grave de las siete- no se disparaba nunca
        // sobre datos reales, solo sobre los del semillero, que trae las etiquetas escritas.
        $separador = '[\s_.\-]';

        $indicaFallo = (bool) preg_match('/fall|invalid|incorrect|denegad|rechazad|failed|throttle|bloque/u', $texto);
        $indicaSegundoFactor = (bool) preg_match(
            '/segundo'.$separador.'?factor|dos'.$separador.'?factores|two.?factor|2fa|totp'
            .'|codigo'.$separador.'?(?:de'.$separador.'?)?recuperacion|recovery.?code/u',
            $texto,
        );
        $indicaSesion = (bool) preg_match(
            '/login|inicio'.$separador.'?de'.$separador.'?sesion|sesion|credencial|auth|contrasena|password|intento/u',
            $texto,
        );

        if ($indicaSegundoFactor) {
            $etiquetas[] = $indicaFallo ? 'segundo_factor.fallido' : 'segundo_factor.correcto';
        } elseif ($indicaSesion) {
            $etiquetas[] = $indicaFallo ? 'autenticacion.fallida' : 'autenticacion.correcta';
        }

        // "acceso_denegado_por_rol" es el evento que escribe el middleware de roles: se
        // etiqueta aparte de la autenticacion porque ahi el usuario ya habia iniciado sesion.
        if (preg_match('/autoriza|permiso|rol|403|forbidden|acceso'.$separador.'?denegado/u', $texto) === 1) {
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
     * Sube al primer nivel los campos que la bitacora de seguridad anida bajo "detalle".
     *
     * El middleware de roles y el auditor escriben la ruta, el metodo y la direccion dentro
     * de esa clave. Sin aplanarlo, el evento llegaria al panel sin IP y sin ruta, que son
     * justo las dos columnas por las que un analista pivota.
     *
     * @param  array<string, mixed>  $contexto
     * @return array<string, mixed>
     */
    private function aplanarContexto(array $contexto): array
    {
        foreach (['detalle', 'context', 'datos'] as $clave) {
            $anidado = $contexto[$clave] ?? null;

            if (is_array($anidado)) {
                // El nivel exterior manda: si ambos traen "ruta", la del evento es la buena.
                $contexto = array_merge($anidado, $contexto);
            }
        }

        return $contexto;
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
