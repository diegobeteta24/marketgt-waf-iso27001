<?php

declare(strict_types=1);

namespace App\Services\Demo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Lanzador de la consola de demostración de ataques en vivo (Capa 4 - 5).
 *
 * La consola pulsa un botón y este servicio dispara una petición HTTP REAL desde el
 * propio servidor contra la dirección pública del sitio, de modo que el tráfico
 * atraviesa el WAF de verdad (Nginx + ModSecurity + OWASP CRS 4.29). Después lee el
 * registro de auditoría JSON del WAF y devuelve el evento correlacionado. No hay
 * simulación en la primera mitad: lo que se ve en pantalla es lo que el WAF hizo.
 *
 * POR QUÉ EL DESTINO ESTÁ BLINDADO
 *   Una herramienta que lanza ataques y a la que se le puede cambiar el destino es,
 *   ella misma, un arma contra terceros. Por eso el destino NUNCA lo elige la interfaz:
 *   se toma de la configuración del servidor (APP_URL, o MARKETGT_DEMO_BASE_URL) y, antes
 *   de cada envío, se comprueba que el anfitrión de la URL coincide con el propio sitio.
 *   Cualquier otro anfitrión se rechaza con una excepción. No se siguen redirecciones,
 *   para que una respuesta 3xx no pueda desviar la petición fuera del dominio propio.
 *
 * POR QUÉ EXISTEN DOS RUTAS DE BLANCO
 *   El "modo comparativo" necesita el mismo vector contra el mismo control de aplicación
 *   con el motor del WAF en dos estados. No se puede apagar el motor sin reiniciar el
 *   contenedor, así que el equipo del WAF dejó previsto (regla 1006 del fichero 900) que
 *   la ruta /demo-waf se evalúe en modo SOLO DETECCIÓN cuando llega la cabecera secreta
 *   X-MarketGT-Console. De ahí las dos rutas gemelas hacia el MISMO controlador:
 *     - laboratorio/blanco/{familia}  -> WAF plenamente activo   -> 403 en el borde.
 *     - demo-waf/blanco/{familia}     -> WAF en solo detección    -> la petición llega a
 *       la aplicación, donde la consulta preparada la neutraliza igual. Defensa en
 *       profundidad demostrada con dos controles independientes para la misma amenaza.
 */
class LanzadorAtaques
{
    /** Marca de correlación que se inyecta en la URL de cada disparo. */
    public const PARAMETRO_MARCA = 'mgtx';

    /** Valor del secreto tal y como quedó escrito en la regla 1006 del fichero 900. */
    private const CLAVE_POR_DEFECTO = 'CAMBIA-ESTE-SECRETO-LARGO';

    private const RUTA_AUDITORIA_POR_DEFECTO = '/var/log/modsecurity/audit/audit.json';

    /**
     * Ejecuta un ataque del catálogo en el modo indicado y devuelve el resultado completo:
     * la petición enviada, la respuesta cruda del WAF/aplicación y el evento del registro
     * de auditoría ya correlacionado.
     *
     * @return array<string, mixed>
     */
    public function lanzar(string $idAtaque, string $modo = 'activo'): array
    {
        $ataque = $this->buscarAtaque($idAtaque);

        if ($ataque === null) {
            return [
                'ok' => false,
                'error' => 'El ataque solicitado no existe en el catálogo.',
                'id' => $idAtaque,
            ];
        }

        $modo = $modo === 'deteccion' ? 'deteccion' : 'activo';
        $marca = Str::lower(Str::random(12));

        $peticion = $this->construirPeticion($ataque, $modo, $marca);

        // Cerrojo antuSSRF: el anfitrión del destino debe ser el del propio sitio.
        // Se comprueba aquí, justo antes de enviar, y no solo al construir la base.
        $this->exigirDestinoPropio($peticion['url']);

        $envio = $this->enviar($peticion);

        // El WAF escribe el asiento de auditoría al cerrar la transacción (fase 5). Puede
        // tardar un instante en aparecer en el fichero, así que se reintenta unas pocas
        // veces con esperas cortas antes de darlo por ausente.
        $evento = $envio['ok'] ? $this->esperarEvento($marca) : $this->auditoriaNoDisponible();

        return [
            'ok' => $envio['ok'],
            'id' => $ataque['id'],
            'nombre' => $ataque['nombre'],
            'grupo' => $ataque['grupo'],
            'modo' => $modo,
            'marca' => $marca,
            'esperado' => $modo === 'activo' ? $ataque['esperado_activo'] : $ataque['esperado_deteccion'],
            'peticion' => $peticion,
            'respuesta' => $envio,
            'evento' => $evento,
            // Un disparo cuenta como "bloqueado" cuando el borde respondió 403: es la
            // señal inequívoca de que ModSecurity cortó la petición antes de la aplicación.
            'bloqueado' => ($envio['codigo'] ?? null) === 403,
        ];
    }

    /**
     * Modo comparativo: el mismo vector con el WAF activo y con el WAF en solo detección.
     * Es el argumento central de la presentación, así que devuelve las dos mitades juntas.
     *
     * @return array<string, mixed>
     */
    public function comparar(string $idAtaque): array
    {
        return [
            'id' => $idAtaque,
            'activo' => $this->lanzar($idAtaque, 'activo'),
            'deteccion' => $this->lanzar($idAtaque, 'deteccion'),
        ];
    }

    /**
     * Catálogo de ataques agrupado por familia. Cada entrada declara la amenaza, la
     * categoría del OWASP Top 10:2025, la regla del Core Rule Set (o la regla propia
     * 150xx) que debería activarse y el resultado esperado en cada modo.
     *
     * Los identificadores de regla y las cargas están alineados con las reglas realmente
     * desplegadas en infra/modsecurity, para que el número que se ve en pantalla sea el
     * mismo que queda en el registro de auditoría.
     *
     * @return array<int, array{clave: string, titulo: string, descripcion: string, ataques: array<int, array<string, mixed>>}>
     */
    public function catalogo(): array
    {
        return [
            [
                'clave' => 'sqli',
                'titulo' => 'Inyección SQL',
                'descripcion' => 'Manipular la consulta para leer o alterar datos que no corresponden. La detiene el detector libinjection (942100) y, en la aplicación, la consulta preparada.',
                'ataques' => [
                    [
                        'id' => 'sqli_tautologia',
                        'nombre' => 'Tautología "\' OR 1=1 --"',
                        'amenaza' => 'Anula la cláusula WHERE del buscador o del inicio de sesión para devolver todas las filas.',
                        'owasp' => 'A05:2025 Inyección',
                        'regla' => '942100',
                        'regla_nombre' => 'SQL Injection detectado por libinjection',
                        'familia' => 'sqli',
                        'metodo' => 'GET',
                        'consulta' => ['q' => "aretes' OR 1=1 -- -"],
                    ],
                    [
                        'id' => 'sqli_union',
                        'nombre' => 'UNION SELECT (extracción de credenciales)',
                        'amenaza' => 'Adosa una segunda consulta para volcar usuarios y contraseñas en la página de resultados.',
                        'owasp' => 'A05:2025 Inyección',
                        'regla' => '942100',
                        'regla_nombre' => 'SQL Injection detectado por libinjection',
                        'familia' => 'sqli',
                        'metodo' => 'GET',
                        'consulta' => ['q' => '-1 UNION SELECT email, password FROM users -- -'],
                    ],
                    [
                        'id' => 'sqli_cuerpo',
                        'nombre' => 'Inyección en el cuerpo (POST)',
                        'amenaza' => 'La carga viaja en el cuerpo del formulario, no en la URL, para esquivar filtros ingenuos.',
                        'owasp' => 'A05:2025 Inyección',
                        'regla' => '942100 / 942440',
                        'regla_nombre' => 'SQLi y secuencia de comentario SQL',
                        'familia' => 'sqli',
                        'metodo' => 'POST',
                        'cuerpo' => ['buscar' => "administrador'-- -"],
                    ],
                ],
            ],
            [
                'clave' => 'xss',
                'titulo' => 'Secuencias de comandos en sitios cruzados (XSS)',
                'descripcion' => 'Inyectar guiones que se ejecutan en el navegador de otra víctima. Los corta libinjection XSS (941100) y, en la aplicación, el escapado automático de Blade.',
                'ataques' => [
                    [
                        'id' => 'xss_script',
                        'nombre' => 'Etiqueta <script> clásica',
                        'amenaza' => 'Ejecuta JavaScript arbitrario para robar la sesión de quien vea el contenido reflejado.',
                        'owasp' => 'A05:2025 Inyección',
                        'regla' => '941100',
                        'regla_nombre' => 'XSS detectado por libinjection',
                        'familia' => 'xss',
                        'metodo' => 'GET',
                        'consulta' => ['q' => '<script>alert(document.cookie)</script>'],
                    ],
                    [
                        'id' => 'xss_img_onerror',
                        'nombre' => 'Atributo onerror en <img>',
                        'amenaza' => 'Evita la etiqueta <script> usando un manejador de evento sobre una imagen rota.',
                        'owasp' => 'A05:2025 Inyección',
                        'regla' => '941100 / 941160',
                        'regla_nombre' => 'XSS y etiqueta HTML no permitida',
                        'familia' => 'xss',
                        'metodo' => 'GET',
                        'consulta' => ['q' => '<img src=x onerror=alert(1)>'],
                    ],
                    [
                        'id' => 'xss_svg_onload',
                        'nombre' => 'Vector <svg onload>',
                        'amenaza' => 'Aprovecha que muchos saneadores olvidan SVG para ejecutar en la carga del elemento.',
                        'owasp' => 'A05:2025 Inyección',
                        'regla' => '941110 / 941160',
                        'regla_nombre' => 'XSS en etiqueta y atributo de evento',
                        'familia' => 'xss',
                        'metodo' => 'GET',
                        'consulta' => ['q' => '<svg/onload=alert(1)>'],
                    ],
                ],
            ],
            [
                'clave' => 'recorrido',
                'titulo' => 'Recorrido de rutas / LFI',
                'descripcion' => 'Salir del directorio previsto para leer ficheros del sistema. Lo detecta 930110/930120 y, en la aplicación, la normalización con lista blanca.',
                'ataques' => [
                    [
                        'id' => 'lfi_passwd',
                        'nombre' => '../../../../etc/passwd',
                        'amenaza' => 'Lee un fichero del sistema operativo saliendo del directorio de descargas.',
                        'owasp' => 'A01:2025 Pérdida de control de acceso',
                        'regla' => '930110 / 930120',
                        'regla_nombre' => 'Recorrido de rutas y fichero del sistema',
                        'familia' => 'recorrido',
                        'metodo' => 'GET',
                        'consulta' => ['archivo' => '../../../../etc/passwd'],
                    ],
                    [
                        'id' => 'lfi_codificado',
                        'nombre' => 'Recorrido codificado (%2e%2e%2f)',
                        'amenaza' => 'Codifica los puntos y las barras para esquivar filtros que solo miran "../".',
                        'owasp' => 'A01:2025 Pérdida de control de acceso',
                        'regla' => '930100 / 930110',
                        'regla_nombre' => 'Recorrido de rutas codificado',
                        'familia' => 'recorrido',
                        'metodo' => 'GET',
                        'consulta' => ['archivo' => '%2e%2e%2f%2e%2e%2f%2e%2e%2fetc%2fpasswd'],
                    ],
                ],
            ],
            [
                'clave' => 'comandos',
                'titulo' => 'Ejecución de comandos',
                'descripcion' => 'Colar órdenes del sistema operativo a través de un parámetro. Las corta la familia 932xxx; la aplicación no pasa entrada a ninguna shell.',
                'ataques' => [
                    [
                        'id' => 'rce_cat_passwd',
                        'nombre' => 'Encadenado ";cat /etc/passwd"',
                        'amenaza' => 'Añade una orden del sistema tras un separador de comandos.',
                        'owasp' => 'A05:2025 Inyección',
                        'regla' => '932160',
                        'regla_nombre' => 'Ejecución remota de comandos (Unix)',
                        'familia' => 'comandos',
                        'metodo' => 'GET',
                        'consulta' => ['id' => '1;cat /etc/passwd'],
                    ],
                    [
                        'id' => 'rce_tuberia',
                        'nombre' => 'Tubería "| id"',
                        'amenaza' => 'Redirige la salida de un proceso a otra orden del sistema.',
                        'owasp' => 'A05:2025 Inyección',
                        'regla' => '932160 / 932100',
                        'regla_nombre' => 'Inyección de comando del sistema',
                        'familia' => 'comandos',
                        'metodo' => 'GET',
                        'consulta' => ['id' => '1 | id'],
                    ],
                ],
            ],
            [
                'clave' => 'escaneres',
                'titulo' => 'Escáneres conocidos',
                'descripcion' => 'Herramientas automáticas de sondeo. No son un ataque en sí: son el reconocimiento que precede a A01–A10. La regla 913100 las corta por su User-Agent.',
                'ataques' => [
                    [
                        'id' => 'escaner_sqlmap',
                        'nombre' => 'User-Agent de sqlmap',
                        'amenaza' => 'Automatiza el descubrimiento y la explotación de inyección SQL a gran escala.',
                        'owasp' => 'Reconocimiento (habilita A01–A10)',
                        'regla' => '913100',
                        'regla_nombre' => 'User-Agent de herramienta de seguridad',
                        'familia' => 'escaner',
                        'metodo' => 'GET',
                        'cabeceras' => ['User-Agent' => 'sqlmap/1.9#stable (https://sqlmap.org)'],
                    ],
                    [
                        'id' => 'escaner_nikto',
                        'nombre' => 'User-Agent de Nikto',
                        'amenaza' => 'Rastrea miles de rutas y ficheros sensibles buscando configuraciones débiles.',
                        'owasp' => 'Reconocimiento (habilita A01–A10)',
                        'regla' => '913100',
                        'regla_nombre' => 'User-Agent de herramienta de seguridad',
                        'familia' => 'escaner',
                        'metodo' => 'GET',
                        'cabeceras' => ['User-Agent' => 'Mozilla/5.00 (Nikto/2.5.0)'],
                    ],
                ],
            ],
            [
                'clave' => 'metodos',
                'titulo' => 'Métodos no permitidos',
                'descripcion' => 'Verbos HTTP que la aplicación no usa y que delatan sondeo o mala configuración. La regla 911100 los rechaza; el enrutador de Laravel responde 405.',
                'ataques' => [
                    [
                        'id' => 'metodo_trace',
                        'nombre' => 'TRACE (Cross-Site Tracing)',
                        'amenaza' => 'Refleja las cabeceras de la petición y puede exponer cookies en configuraciones antiguas.',
                        'owasp' => 'A02:2025 Configuración de seguridad incorrecta',
                        'regla' => '911100',
                        'regla_nombre' => 'Método HTTP no permitido por la política',
                        'familia' => 'metodo',
                        'metodo' => 'TRACE',
                    ],
                ],
            ],
            [
                'clave' => 'posicionamiento',
                'titulo' => 'Ataques de posicionamiento (reglas propias 15000–15099)',
                'descripcion' => 'El atacante no quiere el servidor: quiere el PageRank del dominio. Reglas escritas por el equipo, porque el Core Rule Set no cubre el SEO.',
                'ataques' => [
                    [
                        'id' => 'seo_spam',
                        'nombre' => 'Inyección de enlaces en contenido',
                        'amenaza' => 'Cuela un enlace a una tienda de réplicas en un comentario para que Googlebot lo indexe desde tu dominio.',
                        'owasp' => 'A05:2025 Inyección',
                        'regla' => '15030',
                        'regla_nombre' => 'SEO Spam Injection (enlace en campo publicable)',
                        'familia' => 'seo',
                        'metodo' => 'POST',
                        'cuerpo' => ['comentario' => 'Excelente, visita <a href="http://pastillas-baratas.ru">mi tienda</a>'],
                    ],
                    [
                        'id' => 'seo_poisoning',
                        'nombre' => 'Envenenamiento de palabras clave',
                        'amenaza' => 'Rellena la descripción con vocabulario de spam farmacéutico para posicionar por esos términos.',
                        'owasp' => 'A05:2025 Inyección',
                        'regla' => '15031',
                        'regla_nombre' => 'SEO Poisoning (pharma hack)',
                        'familia' => 'seo',
                        'metodo' => 'POST',
                        'cuerpo' => ['descripcion' => 'buy cheap viagra online casino bonus'],
                    ],
                    [
                        'id' => 'seo_cloaking_ua',
                        'nombre' => 'Rastreador falsificado (Googlebot falso)',
                        'amenaza' => 'Dice ser Googlebot desde una IP que no es de Google para comparar la versión que se sirve al buscador.',
                        'owasp' => 'A02:2025 Configuración de seguridad incorrecta',
                        'regla' => '15021',
                        'regla_nombre' => 'Cloaking / Crawler falsificado',
                        'familia' => 'seo',
                        'metodo' => 'GET',
                        'cabeceras' => ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
                    ],
                    [
                        'id' => 'seo_cloaking_param',
                        'nombre' => 'Cloaking por parámetro (_escaped_fragment_)',
                        'amenaza' => 'Fuerza la "vista de buscador" para comparar el contenido servido al bot y al usuario.',
                        'owasp' => 'A06:2025 Diseño inseguro',
                        'regla' => '15023',
                        'regla_nombre' => 'Cloaking por parámetro de renderizado',
                        'familia' => 'seo',
                        'metodo' => 'GET',
                        'consulta' => ['_escaped_fragment_' => '1'],
                        // WARNING (+3): por sí sola no alcanza el umbral 5. Se conserva a
                        // propósito para enseñar la diferencia entre "detectado" y "bloqueado".
                        'esperado_activo' => 'detectado',
                    ],
                    [
                        'id' => 'seo_open_redirect',
                        'nombre' => 'Redirección abierta / doorway',
                        'amenaza' => 'Un enlace con tu dominio que aterriza en una granja de enlaces ajena; hereda tu reputación.',
                        'owasp' => 'A01:2025 Pérdida de control de acceso',
                        'regla' => '15040',
                        'regla_nombre' => 'Open Redirect fuera del dominio propio',
                        'familia' => 'seo',
                        'metodo' => 'GET',
                        'consulta' => ['redirect' => 'https://granja-de-enlaces.xyz/'],
                    ],
                    [
                        'id' => 'seo_scraping',
                        'nombre' => 'Extracción masiva (clonado del catálogo)',
                        'amenaza' => 'Herramienta de clonado que copia el catálogo entero para republicarlo y competir con tu propio contenido.',
                        'owasp' => 'A06:2025 Diseño inseguro',
                        'regla' => '15120',
                        'regla_nombre' => 'Herramienta de clonado declarada en el User-Agent',
                        'familia' => 'seo',
                        'metodo' => 'GET',
                        'cabeceras' => ['User-Agent' => 'HTTrack/3.49-2 (offline browser)'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Diagnóstico de configuración para pintar la advertencia y ayudar a depurar sin
     * revelar el secreto: solo dice si sigue siendo el valor de fábrica.
     *
     * @return array<string, mixed>
     */
    public function diagnostico(): array
    {
        $ruta = $this->rutaAuditoria();

        return [
            'base' => $this->baseUrl(),
            'hosts_permitidos' => $this->hostsPermitidos(),
            'ruta_auditoria' => $ruta,
            'auditoria_legible' => is_readable($ruta),
            'clave_por_defecto' => $this->clave() === self::CLAVE_POR_DEFECTO,
        ];
    }

    /**
     * Aplana el catálogo y busca un ataque por su identificador, ya normalizado con los
     * valores esperados por modo.
     *
     * @return array<string, mixed>|null
     */
    private function buscarAtaque(string $id): ?array
    {
        foreach ($this->catalogo() as $grupo) {
            foreach ($grupo['ataques'] as $ataque) {
                if ($ataque['id'] === $id) {
                    return $this->normalizarAtaque($ataque, $grupo['titulo']);
                }
            }
        }

        return null;
    }

    /**
     * Rellena los valores por defecto de una entrada del catálogo.
     *
     * @param  array<string, mixed>  $ataque
     * @return array<string, mixed>
     */
    private function normalizarAtaque(array $ataque, string $grupo): array
    {
        return array_merge([
            'grupo' => $grupo,
            'consulta' => [],
            'cuerpo' => [],
            'cabeceras' => [],
            'esperado_activo' => 'bloqueado',
            'esperado_deteccion' => 'llega_a_la_app',
        ], $ataque);
    }

    /**
     * Construye la descripción de la petición que se va a enviar. La ruta de destino
     * depende del modo: /demo-waf para solo detección, /laboratorio para el WAF activo.
     *
     * @param  array<string, mixed>  $ataque
     * @return array<string, mixed>
     */
    private function construirPeticion(array $ataque, string $modo, string $marca): array
    {
        $prefijo = $modo === 'deteccion' ? 'demo-waf/blanco' : 'laboratorio/blanco';
        $ruta = $prefijo.'/'.$ataque['familia'];

        /** @var array<string, string> $consulta */
        $consulta = $ataque['consulta'];
        $consulta[self::PARAMETRO_MARCA] = $marca;

        $url = rtrim($this->baseUrl(), '/').'/'.$ruta.'?'.http_build_query($consulta);

        $cabeceras = $ataque['cabeceras'];

        // La cabecera secreta solo se manda en solo detección: es lo que hace que el WAF
        // ponga el motor en DetectionOnly sobre /demo-waf (regla 1006) y deje pasar la
        // petición hasta la aplicación en vez de cortarla en el borde.
        if ($modo === 'deteccion') {
            $cabeceras['X-MarketGT-Console'] = $this->clave();
        }

        return [
            'modo' => $modo,
            'metodo' => $ataque['metodo'],
            'url' => $url,
            'ruta' => '/'.$ruta,
            'consulta' => $consulta,
            'cuerpo' => $ataque['cuerpo'],
            'cabeceras' => $cabeceras,
        ];
    }

    /**
     * Envía la petición real. Tiempos de espera cortos, sin seguir redirecciones y sin
     * verificar el certificado (el WAF usa uno autofirmado en el laboratorio). Devuelve
     * el código, las cabeceras y el cuerpo, o el motivo del fallo de conexión.
     *
     * @param  array<string, mixed>  $peticion
     * @return array<string, mixed>
     */
    private function enviar(array $peticion): array
    {
        $cliente = Http::withOptions(['allow_redirects' => false])
            ->connectTimeout(3)
            ->timeout(6)
            ->withoutVerifying()
            ->withHeaders($peticion['cabeceras']);

        try {
            if ($peticion['cuerpo'] !== []) {
                $respuesta = $cliente->asForm()->send($peticion['metodo'], $peticion['url'], [
                    'form_params' => $peticion['cuerpo'],
                ]);
            } else {
                $respuesta = $cliente->send($peticion['metodo'], $peticion['url']);
            }
        } catch (ConnectionException $e) {
            return [
                'ok' => false,
                'codigo' => null,
                'error' => 'No se pudo contactar el destino ('.$e->getMessage().'). '
                    .'Verifique que el WAF está en pie y que el servidor alcanza '.$this->baseUrl().'.',
                'cabeceras' => [],
                'cuerpo' => '',
            ];
        }

        $cuerpo = $respuesta->body();

        return [
            'ok' => true,
            'codigo' => $respuesta->status(),
            'motivo' => $respuesta->reason(),
            // Las respuestas del WAF y del blanco son pequeñas; se recorta por si acaso
            // para no volcar una página entera en el panel.
            'cuerpo' => Str::limit($cuerpo, 6000),
            'cuerpo_recortado' => Str::length($cuerpo) > 6000,
            'cabeceras' => $this->aplanarCabeceras($respuesta->headers()),
        ];
    }

    /**
     * @param  array<string, array<int, string>>  $cabeceras
     * @return array<string, string>
     */
    private function aplanarCabeceras(array $cabeceras): array
    {
        $planas = [];

        foreach ($cabeceras as $nombre => $valores) {
            $planas[$nombre] = implode(', ', $valores);
        }

        return $planas;
    }

    /**
     * Reintenta la lectura del registro de auditoría un puñado de veces: el asiento se
     * escribe al cerrar la transacción y puede llegar unos milisegundos después de que
     * la respuesta HTTP ya esté en nuestras manos.
     *
     * @return array<string, mixed>
     */
    private function esperarEvento(string $marca): array
    {
        for ($intento = 0; $intento < 6; $intento++) {
            $evento = $this->buscarEvento($marca);

            if ($evento['encontrado']) {
                return $evento;
            }

            usleep(250_000);
        }

        return $this->buscarEvento($marca);
    }

    /**
     * Lee la cola del registro de auditoría JSON del WAF y localiza el asiento cuya URL
     * contiene la marca de correlación de este disparo.
     *
     * @return array<string, mixed>
     */
    public function buscarEvento(string $marca): array
    {
        $ruta = $this->rutaAuditoria();

        if (! is_readable($ruta)) {
            return $this->auditoriaNoDisponible();
        }

        foreach ($this->colaDeLineas($ruta) as $linea) {
            $objeto = json_decode($linea, true);

            if (! is_array($objeto)) {
                continue;
            }

            $uri = $objeto['transaction']['request']['uri'] ?? '';

            if (is_string($uri) && str_contains($uri, self::PARAMETRO_MARCA.'='.$marca)) {
                return $this->normalizarEvento($objeto);
            }
        }

        return [
            'disponible' => true,
            'encontrado' => false,
            'ruta' => $ruta,
        ];
    }

    /**
     * Devuelve las últimas líneas del fichero, de la más reciente a la más antigua, sin
     * cargar el fichero entero: se lee solo la cola.
     *
     * @return array<int, string>
     */
    private function colaDeLineas(string $ruta, int $bytes = 262_144): array
    {
        $tamano = filesize($ruta);

        if ($tamano === false) {
            return [];
        }

        $manejador = fopen($ruta, 'rb');

        if ($manejador === false) {
            return [];
        }

        $inicio = max(0, $tamano - $bytes);
        fseek($manejador, $inicio);
        $contenido = stream_get_contents($manejador);
        fclose($manejador);

        if ($contenido === false) {
            return [];
        }

        $lineas = preg_split('/\r?\n/', trim($contenido)) ?: [];

        // Si no se leyó desde el principio, la primera línea puede estar cortada.
        if ($inicio > 0 && count($lineas) > 1) {
            array_shift($lineas);
        }

        return array_reverse($lineas);
    }

    /**
     * Traduce el JSON de ModSecurity a la forma que consume la interfaz: identificador de
     * transacción, reglas activadas, puntuación de anomalía acumulada y si se interrumpió.
     *
     * @param  array<string, mixed>  $objeto
     * @return array<string, mixed>
     */
    private function normalizarEvento(array $objeto): array
    {
        $transaccion = $objeto['transaction'] ?? [];
        $mensajes = $transaccion['messages'] ?? [];

        $reglas = [];
        $puntuacion = null;
        $puntuacionSeo = null;

        foreach ($mensajes as $mensaje) {
            $detalle = $mensaje['details'] ?? [];
            $id = (string) ($detalle['ruleId'] ?? '');
            $texto = (string) ($mensaje['message'] ?? '');

            $reglas[] = [
                'id' => $id,
                'mensaje' => $texto,
                'dato' => $detalle['data'] ?? null,
                'severidad' => $detalle['severity'] ?? null,
                'etiquetas' => $detalle['tags'] ?? [],
            ];

            // La puntuación de anomalía no es un campo propio: vive en el texto de ciertas
            // reglas de reporte. Se prefiere el desglose propio 15091, luego el 949110.
            if ($id === '15091' && preg_match('/TOTAL=(\d+)/', $texto, $m) === 1) {
                $puntuacion = (int) $m[1];
            } elseif ($id === '949110' && $puntuacion === null && preg_match('/Total Score:\s*(\d+)/i', $texto, $m) === 1) {
                $puntuacion = (int) $m[1];
            } elseif ($id === '15090' && preg_match('/score=(\d+)/', $texto, $m) === 1) {
                $puntuacionSeo = (int) $m[1];
            }
        }

        return [
            'disponible' => true,
            'encontrado' => true,
            'id_transaccion' => $transaccion['unique_id'] ?? null,
            'ip' => $transaccion['client_ip'] ?? null,
            'hora' => $transaccion['time_stamp'] ?? null,
            'codigo' => isset($transaccion['response']['http_code']) ? (int) $transaccion['response']['http_code'] : null,
            'interrumpido' => (bool) ($transaccion['is_interrupted'] ?? false),
            'puntuacion' => $puntuacion,
            'puntuacion_seo' => $puntuacionSeo,
            'reglas' => $reglas,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditoriaNoDisponible(): array
    {
        return [
            'disponible' => false,
            'encontrado' => false,
            'ruta' => $this->rutaAuditoria(),
        ];
    }

    /**
     * Comprueba que el destino apunta al propio sitio. Cualquier otro anfitrión, o un
     * esquema que no sea HTTP(S), aborta el envío. Esta es la barrera que impide convertir
     * la consola en un arma contra un tercero.
     */
    private function exigirDestinoPropio(string $url): void
    {
        $partes = parse_url($url);
        $esquema = strtolower($partes['scheme'] ?? '');
        $host = strtolower($partes['host'] ?? '');

        if (! in_array($esquema, ['http', 'https'], true)) {
            throw new \DomainException('La consola solo admite destinos HTTP(S).');
        }

        if ($host === '' || ! in_array($host, $this->hostsPermitidos(), true)) {
            throw new \DomainException(
                'Destino rechazado: "'.$host.'" no es la dirección configurada del sitio. '
                .'La consola solo puede atacar su propia infraestructura.'
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function hostsPermitidos(): array
    {
        $hosts = [];

        foreach ([$this->baseUrl(), (string) config('app.url')] as $candidato) {
            $host = parse_url($candidato, PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                $hosts[strtolower($host)] = true;
            }
        }

        return array_keys($hosts);
    }

    /**
     * Dirección base del sitio. Solo del servidor: nunca de la interfaz. Por defecto,
     * APP_URL; el equipo puede fijar MARKETGT_DEMO_BASE_URL para apuntar al WAF interno
     * (por ejemplo http://waf:8080), que sigue atravesando ModSecurity sin salir a Internet.
     */
    private function baseUrl(): string
    {
        $base = config('demo.base_url') ?? env('MARKETGT_DEMO_BASE_URL') ?? config('app.url');

        return (string) $base;
    }

    private function clave(): string
    {
        return (string) (config('demo.clave_consola') ?? env('MARKETGT_DEMO_KEY') ?? self::CLAVE_POR_DEFECTO);
    }

    private function rutaAuditoria(): string
    {
        return (string) (config('demo.ruta_auditoria') ?? env('MARKETGT_WAF_AUDIT_LOG') ?? self::RUTA_AUDITORIA_POR_DEFECTO);
    }
}
