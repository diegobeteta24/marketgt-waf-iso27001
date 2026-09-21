<?php

namespace Database\Seeders;

use App\Models\AlertaSeguridad;
use App\Models\EventoSeguridad;
use App\Services\Siem\MotorCorrelacion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Semillero de demostracion del centro de monitoreo.
 *
 * Existe por una razon practica: el sabado de la presentacion el WAF llevara dos minutos
 * encendido y un tablero vacio no demuestra nada. Genera siete dias de actividad realista
 * y deja que el motor de correlacion real la procese, de modo que las alertas que se ven
 * en pantalla las produjo el mismo codigo que corre en produccion, no un INSERT a mano.
 *
 * Todo lo que crea queda marcado con es_demostracion = true y se puede distinguir y
 * borrar sin tocar un solo evento real. Las direcciones IP pertenecen a los rangos
 * reservados para documentacion (RFC 5737) y las personas son de fantasia.
 */
class EventosDemoSeeder extends Seeder
{
    /**
     * Semilla fija del generador: la demostracion debe salir igual en el ensayo y en el aula.
     */
    private const SEMILLA = 20260926;

    private const DIAS = 7;

    /**
     * Rangos reservados para documentacion. Ninguna de estas direcciones pertenece a nadie.
     */
    private const ATACANTES = [
        ['ip' => '203.0.113.17', 'pais' => 'RU'],
        ['ip' => '203.0.113.42', 'pais' => 'CN'],
        ['ip' => '198.51.100.23', 'pais' => 'US'],
        ['ip' => '198.51.100.77', 'pais' => 'NL'],
        ['ip' => '192.0.2.55', 'pais' => 'BR'],
        ['ip' => '192.0.2.130', 'pais' => 'DE'],
    ];

    private const CLIENTES = [
        ['ip' => '203.0.113.201', 'pais' => 'GT'],
        ['ip' => '203.0.113.202', 'pais' => 'GT'],
        ['ip' => '203.0.113.203', 'pais' => 'GT'],
        ['ip' => '198.51.100.150', 'pais' => 'SV'],
        ['ip' => '198.51.100.151', 'pais' => 'HN'],
        ['ip' => '192.0.2.200', 'pais' => 'MX'],
    ];

    private const RUTAS_CATALOGO = [
        '/catalogo',
        '/catalogo/electronica',
        '/catalogo/hogar',
        '/categoria/textiles-tipicos',
        '/producto/mochila-huipil-1204',
        '/producto/cafe-huehuetenango-500g',
        '/producto/sandalias-cuero-antigua',
        '/buscar?q=jade',
        '/carrito',
    ];

    private const NAVEGADORES = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 18_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.2 Mobile Safari/604.1',
        'Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0',
    ];

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $pendientes = [];

    public function run(): void
    {
        /** @var MotorCorrelacion $motor */
        $motor = app(MotorCorrelacion::class);

        $motor->sembrarReglas();
        $this->limpiarDemostracion();

        mt_srand(self::SEMILLA);

        $ahora = CarbonImmutable::now();
        $usuarioId = $this->usuarioDemostracion();

        $this->generarTraficoNormal($ahora);
        $this->volcar();

        // Cada episodio se inserta y a continuacion se corre el motor fijando el "presente"
        // unos minutos despues del ultimo evento, que es lo que haria el planificador real.
        // Asi el tiempo de deteccion que muestran las metricas es una resta de verdad.
        $episodios = 0;

        foreach ($this->episodiosSondeoWaf($ahora) as $instante) {
            $motor->ejecutar($instante, marcarDemostracion: true);
            $episodios++;
        }

        foreach ($this->episodiosEscalada($ahora) as $instante) {
            $motor->ejecutar($instante, marcarDemostracion: true);
            $episodios++;
        }

        foreach ($this->episodiosFuerzaBruta($ahora) as $instante) {
            $motor->ejecutar($instante, marcarDemostracion: true);
            $episodios++;
        }

        foreach ($this->episodiosSegundoFactor($ahora, $usuarioId) as $instante) {
            $motor->ejecutar($instante, marcarDemostracion: true);
            $episodios++;
        }

        foreach ($this->episodiosRastreadorFalsificado($ahora) as $instante) {
            $motor->ejecutar($instante, marcarDemostracion: true);
            $episodios++;
        }

        foreach ($this->episodiosEventoCritico($ahora) as $instante) {
            $motor->ejecutar($instante, marcarDemostracion: true);
            $episodios++;
        }

        foreach ($this->episodiosExtraccionMasiva($ahora) as $instante) {
            $motor->ejecutar($instante, marcarDemostracion: true);
            $episodios++;
        }

        foreach ($this->episodiosRecientes($ahora) as $instante) {
            $motor->ejecutar($instante, marcarDemostracion: true);
            $episodios++;
        }

        $this->triarAlertas($ahora);

        $this->command?->info(sprintf(
            'Demostracion lista: %d eventos, %d alertas, %d episodios reproducidos por el motor.',
            EventoSeguridad::query()->where('es_demostracion', true)->count(),
            AlertaSeguridad::query()->where('es_demostracion', true)->count(),
            $episodios,
        ));
    }

    /**
     * Reejecutar el semillero no debe duplicar nada. Se borran primero las alertas para que
     * la tabla puente caiga con ellas y despues los eventos.
     */
    private function limpiarDemostracion(): void
    {
        AlertaSeguridad::query()->where('es_demostracion', true)->delete();
        EventoSeguridad::query()->where('es_demostracion', true)->delete();
    }

    /**
     * Trafico de fondo: en su mayoria clientes reales navegando el catalogo, con el goteo
     * normal de 403 sueltos, inicios de sesion y actividad del sistema operativo. Sin este
     * fondo, cualquier ataque destacaria solo porque es lo unico que hay en pantalla.
     */
    private function generarTraficoNormal(CarbonInterface $ahora): void
    {
        $inicio = $ahora->copy()->subDays(self::DIAS);

        for ($hora = 0; $hora < self::DIAS * 24; $hora++) {
            $momento = $inicio->copy()->addHours($hora);

            // La tienda tiene horario guatemalteco: de madrugada apenas hay clientes.
            $intensidad = match (true) {
                $momento->hour >= 9 && $momento->hour <= 21 => mt_rand(2, 5),
                $momento->hour >= 6 && $momento->hour <= 23 => mt_rand(1, 3),
                default => mt_rand(0, 1),
            };

            for ($i = 0; $i < $intensidad; $i++) {
                $cliente = self::CLIENTES[mt_rand(0, count(self::CLIENTES) - 1)];

                $this->encolar([
                    'fuente' => EventoSeguridad::FUENTE_WAF,
                    'subfuente' => 'modsecurity',
                    'marca_tiempo' => $momento->copy()->addMinutes(mt_rand(0, 59))->addSeconds(mt_rand(0, 59)),
                    'direccion_ip' => $cliente['ip'],
                    'pais' => $cliente['pais'],
                    'metodo' => 'GET',
                    'ruta' => self::RUTAS_CATALOGO[mt_rand(0, count(self::RUTAS_CATALOGO) - 1)],
                    'codigo_respuesta' => 200,
                    'identificadores_regla' => [],
                    'puntuacion_anomalia' => 0,
                    'severidad' => EventoSeguridad::SEVERIDAD_INFORMATIVA,
                    'etiquetas' => ['trafico.normal'],
                    'mensaje' => 'Transaccion sin coincidencias del Core Rule Set',
                    'fue_bloqueado' => false,
                    'agente_usuario' => self::NAVEGADORES[mt_rand(0, count(self::NAVEGADORES) - 1)],
                ]);
            }

            // Un 403 aislado cada tantas horas: es el ruido con el que convive cualquier WAF
            // y lo que justifica que el umbral de la regla 1 sea doce y no dos.
            if (mt_rand(1, 6) === 1) {
                $cliente = self::CLIENTES[mt_rand(0, count(self::CLIENTES) - 1)];

                $this->encolar([
                    'fuente' => EventoSeguridad::FUENTE_WAF,
                    'subfuente' => 'modsecurity',
                    'marca_tiempo' => $momento->copy()->addMinutes(mt_rand(0, 59)),
                    'direccion_ip' => $cliente['ip'],
                    'pais' => $cliente['pais'],
                    'metodo' => 'POST',
                    'ruta' => '/buscar',
                    'codigo_respuesta' => 403,
                    'identificadores_regla' => ['942432', '949110'],
                    'puntuacion_anomalia' => 5,
                    'severidad' => EventoSeguridad::SEVERIDAD_MEDIA,
                    'etiquetas' => ['attack-sqli', 'paranoia-level/1'],
                    'mensaje' => 'Restricted SQL Character Anomaly Detection (falso positivo tipico de una busqueda con comillas)',
                    'fue_bloqueado' => true,
                    'agente_usuario' => self::NAVEGADORES[mt_rand(0, count(self::NAVEGADORES) - 1)],
                ]);
            }

            if (mt_rand(1, 4) === 1) {
                $cliente = self::CLIENTES[mt_rand(0, count(self::CLIENTES) - 1)];

                $this->encolar([
                    'fuente' => EventoSeguridad::FUENTE_APLICACION,
                    'subfuente' => 'laravel',
                    'marca_tiempo' => $momento->copy()->addMinutes(mt_rand(0, 59)),
                    'direccion_ip' => $cliente['ip'],
                    'pais' => $cliente['pais'],
                    'metodo' => 'POST',
                    'ruta' => '/login',
                    'codigo_respuesta' => 302,
                    'identificadores_regla' => [],
                    'puntuacion_anomalia' => 0,
                    'severidad' => EventoSeguridad::SEVERIDAD_BAJA,
                    'etiquetas' => ['aplicacion', 'autenticacion.correcta'],
                    'mensaje' => 'Inicio de sesion correcto',
                    'fue_bloqueado' => false,
                ]);
            }

            if (mt_rand(1, 10) === 1) {
                $atacante = self::ATACANTES[mt_rand(0, count(self::ATACANTES) - 1)];

                $this->encolar([
                    'fuente' => EventoSeguridad::FUENTE_SISTEMA,
                    'subfuente' => 'sshd',
                    'marca_tiempo' => $momento->copy()->addMinutes(mt_rand(0, 59)),
                    'direccion_ip' => $atacante['ip'],
                    'pais' => null,
                    'metodo' => null,
                    'ruta' => null,
                    'codigo_respuesta' => null,
                    'identificadores_regla' => [],
                    'puntuacion_anomalia' => 0,
                    'severidad' => EventoSeguridad::SEVERIDAD_MEDIA,
                    'etiquetas' => ['sistema', 'ssh', 'acceso.fallido'],
                    'mensaje' => 'Failed password for invalid user admin from '.$atacante['ip'].' port '.mt_rand(30000, 60000).' ssh2',
                    'fue_bloqueado' => false,
                ]);
            }
        }
    }

    /**
     * Sondeo del Core Rule Set: rafagas de peticiones con cargas utiles distintas que el WAF
     * corta una tras otra. Dispara la regla de 403 repetidos.
     *
     * @return array<int, CarbonInterface>
     */
    private function episodiosSondeoWaf(CarbonInterface $ahora): array
    {
        $instantes = [];

        foreach ([6, 5, 4, 3, 2, 1] as $indice => $diasAtras) {
            $atacante = self::ATACANTES[$indice % count(self::ATACANTES)];
            $inicio = $ahora->copy()->subDays($diasAtras)->setTime(mt_rand(1, 22), mt_rand(0, 50));

            $instantes[] = $this->rafagaSondeoWaf($inicio, $atacante);
        }

        return $instantes;
    }

    /**
     * Una rafaga de sondeo y el instante en que el planificador la habria visto.
     *
     * @param  array{ip: string, pais: string}  $atacante
     */
    private function rafagaSondeoWaf(CarbonInterface $inicio, array $atacante): CarbonInterface
    {
        $cargas = [
            ["/producto/1204?id=1' OR '1'='1", ['942100', '949110'], 15, 'SQL Injection Attack Detected via libinjection'],
            ['/buscar?q=%3Cscript%3Ealert(1)%3C/script%3E', ['941100', '949110'], 15, 'XSS Attack Detected via libinjection'],
            ['/catalogo/../../../../etc/passwd', ['930110', '949110'], 10, 'Path Traversal Attack (/../)'],
            ['/producto/1204?cmd=;cat%20/etc/shadow', ['932160', '949110'], 15, 'Remote Command Execution: Unix Shell Snippet Found'],
            ['/carrito?id=1%20UNION%20SELECT%20NULL,NULL', ['942190', '949110'], 15, 'Detects MSSQL code execution and information gathering attempts'],
        ];

        $desplazamiento = 0;

        for ($i = 0; $i < 16; $i++) {
            $carga = $cargas[$i % count($cargas)];
            $desplazamiento += mt_rand(8, 12);

            $this->encolar([
                'fuente' => EventoSeguridad::FUENTE_WAF,
                'subfuente' => 'modsecurity',
                'marca_tiempo' => $inicio->copy()->addSeconds($desplazamiento),
                'direccion_ip' => $atacante['ip'],
                'pais' => $atacante['pais'],
                'metodo' => $i % 3 === 0 ? 'POST' : 'GET',
                'ruta' => $carga[0],
                'codigo_respuesta' => 403,
                'identificadores_regla' => $carga[1],
                'puntuacion_anomalia' => $carga[2],
                'severidad' => EventoSeguridad::SEVERIDAD_ALTA,
                'etiquetas' => ['attack-injection', 'OWASP_CRS', 'paranoia-level/1'],
                'mensaje' => $carga[3],
                'carga_util' => $i % 3 === 0 ? 'usuario=admin&password=[REDACTADO]' : null,
                'fue_bloqueado' => true,
                'agente_usuario' => 'Mozilla/5.0 (compatible; Nikto/2.5.0)',
            ]);
        }

        $this->volcar();

        // El planificador corre cada minuto; la pasada cae dentro de la ventana de cinco
        // minutos de la regla, que es lo que ocurre en produccion con un cron de un minuto.
        return $inicio->copy()->addMinutes(mt_rand(4, 5));
    }

    /**
     * Escalada: la misma direccion empieza con sondas que apenas puntuan y va subiendo.
     *
     * @return array<int, CarbonInterface>
     */
    private function episodiosEscalada(CarbonInterface $ahora): array
    {
        $instantes = [];

        foreach ([5, 3, 1] as $indice => $diasAtras) {
            $atacante = self::ATACANTES[($indice + 2) % count(self::ATACANTES)];
            $inicio = $ahora->copy()->subDays($diasAtras)->setTime(mt_rand(8, 20), 5);

            // Linea base: cuarenta y cinco minutos de sondas de puntuacion baja.
            for ($i = 0; $i < 12; $i++) {
                $this->encolar([
                    'fuente' => EventoSeguridad::FUENTE_WAF,
                    'subfuente' => 'modsecurity',
                    'marca_tiempo' => $inicio->copy()->addMinutes($i * 3),
                    'direccion_ip' => $atacante['ip'],
                    'pais' => $atacante['pais'],
                    'metodo' => 'GET',
                    'ruta' => '/producto/'.mt_rand(1000, 1400).'?orden='.mt_rand(1, 9),
                    'codigo_respuesta' => 200,
                    'identificadores_regla' => ['920420'],
                    'puntuacion_anomalia' => mt_rand(2, 3),
                    'severidad' => EventoSeguridad::SEVERIDAD_BAJA,
                    'etiquetas' => ['OWASP_CRS', 'paranoia-level/1'],
                    'mensaje' => 'Request content type is not allowed by policy',
                    'fue_bloqueado' => false,
                    'agente_usuario' => 'python-requests/2.32.3',
                ]);
            }

            // Ventana reciente: quince minutos con la puntuacion multiplicada.
            for ($i = 0; $i < 8; $i++) {
                $this->encolar([
                    'fuente' => EventoSeguridad::FUENTE_WAF,
                    'subfuente' => 'modsecurity',
                    'marca_tiempo' => $inicio->copy()->addMinutes(37 + $i),
                    'direccion_ip' => $atacante['ip'],
                    'pais' => $atacante['pais'],
                    'metodo' => 'POST',
                    'ruta' => '/producto/1204/opinion',
                    'codigo_respuesta' => $i >= 5 ? 403 : 200,
                    'identificadores_regla' => ['941160', '942150', '949110'],
                    'puntuacion_anomalia' => 8 + $i * 2,
                    'severidad' => $i >= 5 ? EventoSeguridad::SEVERIDAD_ALTA : EventoSeguridad::SEVERIDAD_MEDIA,
                    'etiquetas' => ['attack-xss', 'OWASP_CRS', 'paranoia-level/1'],
                    'mensaje' => 'NoScript XSS InjectionChecker: HTML Injection',
                    'fue_bloqueado' => $i >= 5,
                    'agente_usuario' => 'python-requests/2.32.3',
                ]);
            }

            $this->volcar();

            $instantes[] = $inicio->copy()->addMinutes(45 + mt_rand(1, 6));
        }

        return $instantes;
    }

    /**
     * @return array<int, CarbonInterface>
     */
    private function episodiosFuerzaBruta(CarbonInterface $ahora): array
    {
        $instantes = [];

        foreach ([6, 4, 3, 2, 1] as $indice => $diasAtras) {
            $atacante = self::ATACANTES[($indice + 1) % count(self::ATACANTES)];
            $inicio = $ahora->copy()->subDays($diasAtras)->setTime(mt_rand(0, 23), mt_rand(0, 45));

            $instantes[] = $this->rafagaFuerzaBruta($inicio, $atacante);
        }

        return $instantes;
    }

    /**
     * @param  array{ip: string, pais: string}  $atacante
     */
    private function rafagaFuerzaBruta(CarbonInterface $inicio, array $atacante): CarbonInterface
    {
        $desplazamiento = 0;

        for ($i = 0; $i < 11; $i++) {
            $desplazamiento += mt_rand(15, 25);

            $this->encolar([
                'fuente' => EventoSeguridad::FUENTE_APLICACION,
                'subfuente' => 'laravel',
                'marca_tiempo' => $inicio->copy()->addSeconds($desplazamiento),
                'direccion_ip' => $atacante['ip'],
                'pais' => $atacante['pais'],
                'metodo' => 'POST',
                'ruta' => '/login',
                'codigo_respuesta' => 422,
                'identificadores_regla' => [],
                'puntuacion_anomalia' => 0,
                'severidad' => EventoSeguridad::SEVERIDAD_MEDIA,
                'etiquetas' => ['aplicacion', 'autenticacion.fallida'],
                'mensaje' => 'Credenciales invalidas para la cuenta administracion@marketgt.test',
                'carga_util' => '{"email":"administracion@marketgt.test","password":"[REDACTADO]"}',
                'fue_bloqueado' => false,
            ]);
        }

        // La capa 2 reacciona sola: fail2ban banea la direccion. El SIEM lo ve y lo
        // correlaciona con los fallos de la aplicacion, que es el objetivo del ejercicio.
        $this->encolar([
            'fuente' => EventoSeguridad::FUENTE_SISTEMA,
            'subfuente' => 'fail2ban',
            'marca_tiempo' => $inicio->copy()->addMinutes(5),
            'direccion_ip' => $atacante['ip'],
            'pais' => null,
            'metodo' => null,
            'ruta' => null,
            'codigo_respuesta' => null,
            'identificadores_regla' => [],
            'puntuacion_anomalia' => 0,
            'severidad' => EventoSeguridad::SEVERIDAD_ALTA,
            'etiquetas' => ['sistema', 'fail2ban', 'carcel.marketgt-login', 'accion.ban'],
            'mensaje' => 'NOTICE [marketgt-login] Ban '.$atacante['ip'],
            'fue_bloqueado' => true,
        ]);

        $this->volcar();

        return $inicio->copy()->addMinutes(mt_rand(6, 9));
    }

    /**
     * @return array<int, CarbonInterface>
     */
    private function episodiosSegundoFactor(CarbonInterface $ahora, ?int $usuarioId): array
    {
        if ($usuarioId === null) {
            return [];
        }

        $instantes = [];

        foreach ([5, 3, 1] as $indice => $diasAtras) {
            $atacante = self::ATACANTES[($indice + 3) % count(self::ATACANTES)];
            $inicio = $ahora->copy()->subDays($diasAtras)->setTime(mt_rand(1, 5), mt_rand(0, 40));

            for ($i = 0; $i < 6; $i++) {
                $this->encolar([
                    'fuente' => EventoSeguridad::FUENTE_APLICACION,
                    'subfuente' => 'laravel',
                    'marca_tiempo' => $inicio->copy()->addSeconds($i * 90),
                    'direccion_ip' => $atacante['ip'],
                    'pais' => $atacante['pais'],
                    'metodo' => 'POST',
                    'ruta' => '/two-factor-challenge',
                    'codigo_respuesta' => 422,
                    'identificadores_regla' => [],
                    'puntuacion_anomalia' => 0,
                    'severidad' => EventoSeguridad::SEVERIDAD_ALTA,
                    'etiquetas' => ['aplicacion', 'segundo_factor.fallido'],
                    'mensaje' => 'Codigo TOTP invalido tras contrasena correcta',
                    'usuario_id' => $usuarioId,
                    'fue_bloqueado' => false,
                ]);
            }

            $this->volcar();

            $instantes[] = $inicio->copy()->addMinutes(mt_rand(9, 12));
        }

        return $instantes;
    }

    /**
     * Rastreador falsificado: se hace pasar por Googlebot sin venir de sus rangos. Lo marcan
     * las reglas propias 15020-15029 del proyecto.
     *
     * @return array<int, CarbonInterface>
     */
    private function episodiosRastreadorFalsificado(CarbonInterface $ahora): array
    {
        $instantes = [];

        for ($episodio = 0; $episodio < 24; $episodio++) {
            $atacante = self::ATACANTES[$episodio % count(self::ATACANTES)];
            $inicio = $this->momentoPasado($ahora, mt_rand(0, self::DIAS - 1), mt_rand(0, 23), mt_rand(0, 55));

            $instantes[] = $this->rafagaRastreador($inicio, $atacante);
        }

        return $instantes;
    }

    /**
     * @param  array{ip: string, pais: string}  $atacante
     */
    private function rafagaRastreador(CarbonInterface $inicio, array $atacante): CarbonInterface
    {
        for ($i = 0; $i < 2; $i++) {
            $this->encolar([
                'fuente' => EventoSeguridad::FUENTE_WAF,
                'subfuente' => 'modsecurity',
                'marca_tiempo' => $inicio->copy()->addMinutes($i),
                'direccion_ip' => $atacante['ip'],
                'pais' => $atacante['pais'],
                'metodo' => 'GET',
                'ruta' => self::RUTAS_CATALOGO[mt_rand(0, count(self::RUTAS_CATALOGO) - 1)],
                'codigo_respuesta' => 403,
                'identificadores_regla' => ['15021'],
                'puntuacion_anomalia' => 8,
                'severidad' => EventoSeguridad::SEVERIDAD_MEDIA,
                'etiquetas' => ['marketgt/posicionamiento', 'rastreador-falsificado'],
                'mensaje' => 'Agente declara Googlebot pero la resolucion inversa no pertenece a Google',
                'fue_bloqueado' => true,
                'agente_usuario' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            ]);
        }

        $this->volcar();

        return $inicio->copy()->addMinutes(3 + mt_rand(1, 6));
    }

    /**
     * @return array<int, CarbonInterface>
     */
    private function episodiosEventoCritico(CarbonInterface $ahora): array
    {
        $criticos = [
            ['/admin/exportar?archivo=../../.env', ['930120', '15031', '949110'], 'OS File Access Attempt sobre el archivo de configuracion'],
            ['/api/pedidos?id=1;DROP%20TABLE%20pedidos', ['942360', '949110'], 'Detects concatenated basic SQL injection and SQLLFI attempts'],
            ['/producto/1204', ['932115', '949110'], 'Remote Command Execution: Unix Shell Expression Found'],
            ['/carrito/aplicar-cupon', ['933160', '949110'], 'PHP Injection Attack: High-Risk PHP Function Call Found'],
            ['/perfil/avatar', ['933110', '949110'], 'PHP Injection Attack: PHP Script File Upload Found'],
            ['/buscar', ['941140', '949110'], 'XSS Filter - Category 4: Javascript URI Vector'],
            ['/admin', ['913120', '15011', '949110'], 'Found request filename/argument associated with security scanner'],
            ['/api/clientes', ['942440', '949110'], 'SQL Comment Sequence Detected'],
        ];

        $instantes = [];

        foreach ($criticos as $indice => $critico) {
            $atacante = self::ATACANTES[$indice % count(self::ATACANTES)];
            $momento = $this->momentoPasado($ahora, mt_rand(0, self::DIAS - 1), mt_rand(0, 23), mt_rand(0, 55));

            $instantes[] = $this->eventoCritico($momento, $atacante, $critico, $indice % 2 === 0);
        }

        return $instantes;
    }

    /**
     * @param  array{ip: string, pais: string}  $atacante
     * @param  array{0: string, 1: array<int, string>, 2: string}  $critico
     */
    private function eventoCritico(CarbonInterface $momento, array $atacante, array $critico, bool $esPost): CarbonInterface
    {
        $this->encolar([
            'fuente' => EventoSeguridad::FUENTE_WAF,
            'subfuente' => 'modsecurity',
            'marca_tiempo' => $momento,
            'direccion_ip' => $atacante['ip'],
            'pais' => $atacante['pais'],
            'metodo' => $esPost ? 'POST' : 'GET',
            'ruta' => $critico[0],
            'codigo_respuesta' => 403,
            'identificadores_regla' => $critico[1],
            'puntuacion_anomalia' => mt_rand(25, 40),
            'severidad' => EventoSeguridad::SEVERIDAD_CRITICA,
            'etiquetas' => ['attack-injection', 'OWASP_CRS', 'severidad/critica'],
            'mensaje' => $critico[2],
            'fue_bloqueado' => true,
            'agente_usuario' => 'sqlmap/1.8.9#stable (https://sqlmap.org)',
        ]);

        $this->volcar();

        return $momento->copy()->addMinutes(mt_rand(1, 8));
    }

    /**
     * Extraccion masiva: doscientas sesenta peticiones al catalogo en diez minutos. Es el
     * episodio mas caro en filas del semillero, pero sin el no se puede demostrar que la
     * regla de volumen funciona con su umbral real de doscientos cincuenta.
     *
     * @return array<int, CarbonInterface>
     */
    private function episodiosExtraccionMasiva(CarbonInterface $ahora): array
    {
        $atacante = self::ATACANTES[3];
        $inicio = $ahora->copy()->subDays(2)->setTime(3, 12);

        for ($i = 0; $i < 262; $i++) {
            $this->encolar([
                'fuente' => EventoSeguridad::FUENTE_WAF,
                'subfuente' => 'modsecurity',
                'marca_tiempo' => $inicio->copy()->addSeconds((int) round($i * 1.8)),
                'direccion_ip' => $atacante['ip'],
                'pais' => $atacante['pais'],
                'metodo' => 'GET',
                'ruta' => '/producto/'.(1000 + $i),
                'codigo_respuesta' => 200,
                'identificadores_regla' => ['15042'],
                'puntuacion_anomalia' => 3,
                'severidad' => EventoSeguridad::SEVERIDAD_BAJA,
                'etiquetas' => ['marketgt/posicionamiento', 'extraccion-masiva'],
                'mensaje' => 'Ritmo de peticiones al catalogo por encima del perfil de un cliente',
                'fue_bloqueado' => false,
                'agente_usuario' => 'Scrapy/2.12 (+https://scrapy.org)',
            ]);
        }

        $this->volcar();

        // La rafaga ocupa menos de ocho minutos; la pasada a los nueve la abarca entera
        // dentro de la ventana de diez minutos que declara la regla.
        return [$inicio->copy()->addMinutes(9)];
    }

    /**
     * Reparte estados de triaje sobre las alertas ya creadas por el motor.
     *
     * Se escriben las marcas de tiempo a mano, y no con cambiarEstado(), porque ese metodo
     * sella la hora actual: aqui hace falta un historico repartido en los siete dias. Es la
     * unica licencia del semillero y queda acotada a los registros de demostracion.
     */
    private function triarAlertas(CarbonInterface $ahora): void
    {
        $alertas = AlertaSeguridad::query()
            ->where('es_demostracion', true)
            ->orderBy('detectada_en')
            ->get();

        $analistaId = $this->usuarioDemostracion();
        $falsoPositivoAsignado = false;

        foreach ($alertas as $indice => $alerta) {
            $antiguedadHoras = $alerta->detectada_en->diffInHours($ahora);

            // Las alertas de las ultimas horas se dejan sin triar a proposito: el panel debe
            // mostrar trabajo pendiente, y en la presentacion se triaja una en vivo.
            if ($antiguedadHoras < 10) {
                continue;
            }

            $confirmada = $alerta->detectada_en->copy()->addMinutes(mt_rand(4, 38));

            // Un unico falso positivo en toda la ventana: un comparador de precios declarado
            // que la regla del rastreador marco de mas. Es el caso que hace que la tasa no
            // sea cero, y una tasa de cero seria sospechosa en si misma.
            if (! $falsoPositivoAsignado && $alerta->clave_regla === 'rastreador_falsificado' && $indice > 6) {
                $alerta->estado = AlertaSeguridad::ESTADO_FALSO_POSITIVO;
                $alerta->confirmada_en = $confirmada;
                $alerta->cerrada_en = $confirmada->copy()->addMinutes(mt_rand(5, 25));
                $alerta->atendida_por = $analistaId;
                $alerta->notas_triaje = 'Resolucion inversa verificada: la direccion pertenece a un comparador de precios '
                    .'declarado por el area comercial. Se anade a la lista de excepciones del WAF.';
                $alerta->save();

                $falsoPositivoAsignado = true;

                continue;
            }

            $contenida = $confirmada->copy()->addMinutes(mt_rand(12, 95));

            $alerta->confirmada_en = $confirmada;
            $alerta->contenida_en = $contenida;
            $alerta->atendida_por = $analistaId;
            $alerta->notas_triaje = $this->notaDeTriaje($alerta->clave_regla);

            // Las mas antiguas ya se cerraron; las de ayer siguen contenidas a la espera del
            // informe. Ese reparto es el que se ve en un turno real.
            if ($antiguedadHoras > 48) {
                $alerta->estado = AlertaSeguridad::ESTADO_CERRADA;
                $alerta->cerrada_en = $contenida->copy()->addMinutes(mt_rand(20, 180));
            } elseif ($antiguedadHoras > 24) {
                $alerta->estado = AlertaSeguridad::ESTADO_CONTENIDA;
            } else {
                $alerta->estado = AlertaSeguridad::ESTADO_EN_TRIAJE;
                $alerta->contenida_en = null;
            }

            $alerta->save();
        }
    }

    private function notaDeTriaje(string $claveRegla): string
    {
        return match ($claveRegla) {
            'waf_403_repetido' => 'Confirmado en el registro de auditoria del WAF por identificador de transaccion. '
                .'Direccion bloqueada en Cloudflare y anadida a la carcel de fail2ban.',
            'escalada_anomalia' => 'La progresion se detiene en cuatro puntos por debajo del umbral de bloqueo. Se eleva '
                .'a la revision de reglas: el atacante localizo el borde del Core Rule Set.',
            'fuerza_bruta_sesion' => 'Ninguna de las cuentas atacadas llego a autenticarse. Direccion contenida por '
                .'fail2ban; se notifico a las cuentas afectadas.',
            'segundo_factor_fallido' => 'Contrasena tratada como comprometida: cambio forzado, sesiones invalidadas y '
                .'codigos de recuperacion regenerados. Titular contactado.',
            'rastreador_falsificado' => 'Resolucion inversa no pertenece al buscador declarado. Direccion bloqueada y '
                .'fichas de producto revisadas sin hallar enlaces inyectados.',
            'extraccion_masiva' => 'Limitacion de tasa aplicada en Cloudflare en vez de bloqueo total, a la espera de '
                .'que el area comercial confirme si es un comparador con acuerdo.',
            'evento_critico_unico' => 'Peticion interrumpida por el WAF. Revisados el registro de la aplicacion y el de '
                .'MariaDB del mismo minuto sin rastro de ejecucion.',
            default => 'Revisado por el turno de guardia.',
        };
    }

    /**
     * Ataques de las ultimas horas. Existen para que el panel tenga trabajo pendiente de
     * verdad: el triaje no se siembra sobre ellos, asi que llegan a la presentacion en
     * estado "nueva" y la cobertura de triaje aparece por debajo del cien por ciento.
     *
     * Es a proposito. La brecha que senala el anexo del proyecto no es que falte deteccion,
     * es que nadie tiene asignado revisar las alertas; un panel donde todo esta ya cerrado
     * no permitiria ensenar el triaje en vivo ni sostener que la metrica se mide sola.
     *
     * @return array<int, CarbonInterface>
     */
    private function episodiosRecientes(CarbonInterface $ahora): array
    {
        $critico = ['/admin/configuracion?plantilla=php://input', ['933100', '949110'], 'PHP Injection Attack: PHP Open Tag Found'];

        return [
            $this->rafagaSondeoWaf($ahora->copy()->subHours(6)->startOfHour()->addMinutes(11), self::ATACANTES[0]),
            $this->rafagaFuerzaBruta($ahora->copy()->subHours(4)->startOfHour()->addMinutes(23), self::ATACANTES[4]),
            $this->rafagaRastreador($ahora->copy()->subHours(2)->startOfHour()->addMinutes(7), self::ATACANTES[2]),
            $this->eventoCritico($ahora->copy()->subMinutes(52), self::ATACANTES[1], $critico, true),
        ];
    }

    /**
     * Devuelve el momento pedido garantizando que cae en el pasado. Un evento con fecha
     * futura descuadraria el tiempo de deteccion y dejaria barras vacias al final de la
     * grafica, que es justo lo que no puede pasar delante del tribunal.
     */
    private function momentoPasado(CarbonInterface $ahora, int $diasAtras, int $hora, int $minuto): CarbonInterface
    {
        $momento = $ahora->copy()->subDays($diasAtras)->setTime($hora, $minuto);

        return $momento->greaterThan($ahora->copy()->subMinutes(30))
            ? $momento->subDay()
            : $momento;
    }

    /**
     * La cuenta que figura como analista de las alertas de demostracion. Si el proyecto ya
     * tiene usuarios se reutiliza el primero; si no, se crea uno de fantasia.
     */
    private function usuarioDemostracion(): ?int
    {
        $existente = DB::table('users')->orderBy('id')->value('id');

        if ($existente !== null) {
            return (int) $existente;
        }

        return (int) DB::table('users')->insertGetId([
            'name' => 'Ixchel Mendoza (analista de demostracion)',
            'email' => 'analista.demo@marketgt.test',
            'email_verified_at' => CarbonImmutable::now(),
            'password' => bcrypt('demostracion-marketgt'),
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function encolar(array $datos): void
    {
        $ahora = CarbonImmutable::now();

        $this->pendientes[] = [
            'fuente' => $datos['fuente'],
            'subfuente' => $datos['subfuente'] ?? null,
            'marca_tiempo' => $datos['marca_tiempo']->toDateTimeString(),
            'direccion_ip' => $datos['direccion_ip'],
            'pais' => $datos['pais'] ?? null,
            'metodo' => $datos['metodo'] ?? null,
            'ruta' => $datos['ruta'] ?? null,
            'codigo_respuesta' => $datos['codigo_respuesta'] ?? null,
            'identificador_transaccion' => bin2hex(random_bytes(8)),
            'identificadores_regla' => json_encode($datos['identificadores_regla'] ?? [], JSON_UNESCAPED_UNICODE),
            'puntuacion_anomalia' => $datos['puntuacion_anomalia'] ?? 0,
            'severidad' => $datos['severidad'],
            'etiquetas' => json_encode($datos['etiquetas'] ?? [], JSON_UNESCAPED_UNICODE),
            'mensaje' => $datos['mensaje'] ?? null,
            'carga_util' => $datos['carga_util'] ?? null,
            'fue_bloqueado' => $datos['fue_bloqueado'] ?? false,
            'usuario_id' => $datos['usuario_id'] ?? null,
            'agente_usuario' => $datos['agente_usuario'] ?? null,
            'es_demostracion' => true,
            'huella' => hash('sha256', 'demo|'.uniqid('', true).'|'.mt_rand()),
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ];

        if (count($this->pendientes) >= 500) {
            $this->volcar();
        }
    }

    private function volcar(): void
    {
        if ($this->pendientes === []) {
            return;
        }

        EventoSeguridad::query()->insert($this->pendientes);

        $this->pendientes = [];
    }
}
