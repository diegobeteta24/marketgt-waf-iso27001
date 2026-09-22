<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\ComparacionContenido;
use App\Models\IncidenteSeo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Detección de contenido diferenciado (cloaking) por comparación de identidades.
 *
 * POR QUÉ EXISTE ESTE CONTROL Y POR QUÉ NO BASTA EL LABORATORIO DE CONTENIDO
 * ---------------------------------------------------------------------------
 * El laboratorio de contenido y la regla 15030 del WAF vigilan lo que ENTRA por el
 * formulario: una reseña, un comentario, un campo de perfil. Funcionan cuando el atacante
 * escribe desde fuera. No funcionan cuando el atacante YA tiene acceso al servidor o al
 * gestor de contenidos, porque entonces no pasa por ningún formulario: escribe el archivo
 * directamente y ninguna sanitización llega a verlo.
 *
 * Ese es el caso real que originó este control. Una empresa guatemalteca que vende cámaras
 * de seguridad tenía en Search Console, junto a sus consultas legítimas, estas otras:
 *
 *     p9bet login    45 impresiones   0 clics
 *     0016bet        13 impresiones   0 clics
 *     96n.com        11 impresiones   1 clic
 *     kmj888         10 impresiones   0 clics
 *     porh300         2 impresiones   1 clic
 *
 * Marcas de casas de apuestas asiáticas bajo un dominio de cámaras. El responsable del
 * sitio navegaba su propia página y no veía NADA raro, y tenía razón: a él no se lo
 * servían. La inyección solo se muestra a quien se identifica como el buscador.
 *
 * LA IDEA DEL CONTROL
 * ---------------------------------------------------------------------------
 * Pedir la MISMA dirección cinco veces, cambiando únicamente cómo se presenta el cliente, y
 * comparar lo que vuelve. Si la versión que recibe Googlebot lleva contenido que la versión
 * del navegador no lleva, el sitio está sirviendo contenido diferenciado, y esa es la
 * definición exacta de cloaking.
 *
 * POR QUÉ NO SE COMPARAN LOS HTML ENTEROS
 * ---------------------------------------------------------------------------
 * Porque dos respuestas de la misma página nunca son idénticas: cambian el testigo CSRF, el
 * identificador de sesión, la hora, el producto destacado del carrusel. Comparar byte a byte
 * daría alarma siempre, y una alarma que salta siempre se acaba apagando: así es como muere
 * de verdad un control de detección. Se comparan SEÑALES ESTABLES, las mismas que manipula
 * el ataque: código de respuesta, destino de las redirecciones, título, descripción,
 * canónico, meta robots, base href, anfitriones enlazados, vocabulario de los sectores de
 * abuso y longitud del texto visible.
 *
 * POR QUÉ ESTE CONTROL SE IDENTIFICA COMO GOOGLEBOT Y OTROS SERVICIOS DEL PROYECTO NO
 * ---------------------------------------------------------------------------
 * El auditor de mapa de sitio se identifica con nombre propio a propósito, porque un control
 * que se disfraza para auditar es indistinguible del ataque que persigue. Aquí es al revés:
 * si no se presenta como el rastreador, no hay nada que comparar; el disfraz ES el
 * experimento. Se compensa de dos maneras: la cabecera X-MarketGT-Auditoria viaja en las
 * cinco peticiones, de modo que el dueño del sitio puede distinguir esta herramienta en su
 * propio registro, y la pantalla exige por escrito que solo se use sobre sitios propios o
 * con autorización.
 *
 * @see VerificadorCrawler   La otra cara: verificar que quien dice ser Googlebot lo es.
 * @see DetectorSpamSeo      El vocabulario de spam se reutiliza tal cual, no se reimplementa.
 * @see ExtractorIndexable   El resumen indexable de cada respuesta sale de ahí.
 */
class DetectorContenidoDiferenciado
{
    /** A partir de aquí una persona tiene que mirar la página. */
    public const UMBRAL_SOSPECHA = 5;

    /** A partir de aquí se da por demostrado el contenido diferenciado. */
    public const UMBRAL_CLOAKING = 10;

    /** Ninguna comparación puede dejar la pantalla colgada: cinco peticiones, techo por cada una. */
    private const SEGUNDOS_ESPERA = 12;

    private const SEGUNDOS_CONEXION = 5;

    /** Redirecciones que se siguen a mano. Más que esto es un bucle, y un bucle es un hallazgo. */
    private const MAXIMO_SALTOS = 4;

    /** Techo del cuerpo descargado. Una página de 20 MB no aporta más evidencia, solo memoria. */
    private const LIMITE_CUERPO = 1_500_000;

    /** Techo del texto que se pasa a las expresiones regulares del detector de spam. */
    private const LIMITE_TEXTO_ANALIZADO = 80_000;

    /** Muestra del texto que se guarda como evidencia y se pinta en la pantalla. */
    private const LIMITE_MUESTRA = 600;

    /**
     * Las cinco identidades. La primera es la REFERENCIA: es lo que ve el dueño del sitio, y
     * todas las demás se comparan contra ella.
     *
     * Las cabeceras no son decorativas. Sec-Fetch-Site distingue una visita escrita a mano de
     * una llegada desde un resultado de búsqueda, y hay inyecciones que solo se activan con
     * la segunda; Accept-Language es lo que usa la negociación de idioma legítima, y por eso
     * se manda siempre el mismo valor: si cambiara entre perfiles, una diferencia de idioma
     * parecería un ataque.
     *
     * @var array<string, array{etiqueta: string, descripcion: string, referencia: bool, cabeceras: array<string, string>}>
     */
    public const PERFILES = [
        'navegador_escritorio' => [
            'etiqueta' => 'Navegador de escritorio',
            'descripcion' => 'Lo que ve el responsable del sitio cuando abre la página en su computadora. Es la versión de referencia.',
            'referencia' => true,
            'cabeceras' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language' => 'es-GT,es;q=0.9',
                'Sec-Fetch-Site' => 'none',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Dest' => 'document',
                'Upgrade-Insecure-Requests' => '1',
            ],
        ],
        'googlebot' => [
            'etiqueta' => 'Rastreador de Google',
            'descripcion' => 'La identidad con la que Google indexa. Si aquí aparece contenido que no aparece en el navegador, eso es lo que Google enseña del dominio.',
            'referencia' => false,
            'cabeceras' => [
                'User-Agent' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Googlebot/2.1; +http://www.google.com/bot.html) Chrome/129.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'es-GT,es;q=0.9',
                'From' => 'googlebot(at)googlebot.com',
            ],
        ],
        'bingbot' => [
            'etiqueta' => 'Rastreador de Bing',
            'descripcion' => 'Segunda opinión. Una inyección que solo reconoce a Google deja a Bing con la versión limpia, y esa asimetría es por sí misma una señal.',
            'referencia' => false,
            'cabeceras' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'es-GT,es;q=0.9',
            ],
        ],
        'desde_buscador' => [
            'etiqueta' => 'Navegador llegando desde Google',
            'descripcion' => 'Mismo navegador, pero declarando que viene de un resultado de búsqueda. Hay campañas que solo se activan con esta procedencia: así el dueño nunca la ve y la víctima sí.',
            'referencia' => false,
            'cabeceras' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language' => 'es-GT,es;q=0.9',
                'Referer' => 'https://www.google.com/',
                'Sec-Fetch-Site' => 'cross-site',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Dest' => 'document',
            ],
        ],
        'movil' => [
            'etiqueta' => 'Teléfono',
            'descripcion' => 'Navegador de teléfono. Muchas campañas de redirección solo se disparan en móvil, donde la víctima no puede ver el código fuente con facilidad.',
            'referencia' => false,
            'cabeceras' => [
                'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'es-GT,es;q=0.9',
                'Sec-Fetch-Site' => 'none',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Dest' => 'document',
            ],
        ],
    ];

    /**
     * Cabeceras que viajan en las CINCO peticiones.
     *
     * La identificación del auditor va aquí y no en el agente de usuario porque el agente es
     * justamente la variable del experimento: marcarlo delataría la prueba ante el propio
     * script del atacante, que lo primero que hace es mirar esa cadena. Una cabecera propia
     * permite que el dueño del sitio distinga esta herramienta en su registro de acceso sin
     * alterar lo que se está midiendo.
     */
    private const CABECERAS_COMUNES = [
        'X-MarketGT-Auditoria' => 'deteccion-de-contenido-diferenciado',
        'Cache-Control' => 'no-cache',
        'Pragma' => 'no-cache',
    ];

    /**
     * Qué diferencias son esperables en cada identidad, y por qué.
     *
     * Esta tabla es la mitad honesta del control. Un sitio puede servir legítimamente algo
     * distinto a un rastreador —renderizado previo, diseño adaptado, versión móvil en un
     * subdominio— y un control que no lo reconozca produce alarmas que nadie puede cerrar.
     * Las señales toleradas se siguen ENSEÑANDO, con su explicación, pero puntúan cero.
     *
     * La tolerancia se anula entera en cuanto aparece una señal concluyente: un texto más
     * largo es renderizado previo mientras lo que se añade no sea vocabulario de apuestas.
     *
     * @var array<string, array<string, string>>
     */
    private const TOLERANCIAS = [
        'googlebot' => [
            'longitud_distinta' => 'renderizado_previo',
            'enlaces_diferencia' => 'renderizado_previo',
        ],
        'bingbot' => [
            'longitud_distinta' => 'renderizado_previo',
            'enlaces_diferencia' => 'renderizado_previo',
        ],
        'movil' => [
            'longitud_distinta' => 'diseno_adaptado',
            'enlaces_diferencia' => 'diseno_adaptado',
            'descripcion_distinta' => 'diseno_adaptado',
            'redireccion_exclusiva' => 'subdominio_movil',
        ],
        'desde_buscador' => [],
    ];

    /**
     * @var array<string, string>
     */
    private const EXPLICACION_TOLERANCIA = [
        'renderizado_previo' => 'Compatible con renderizado previo para buscadores: la versión del rastreador trae más contenido y nada de ese contenido pertenece a un sector de abuso.',
        'diseno_adaptado' => 'Compatible con un diseño adaptado al teléfono, que muestra menos bloques y menos enlaces que la versión de escritorio.',
        'subdominio_movil' => 'Redirección hacia el mismo dominio, que es lo que hace una versión móvil alojada en un subdominio.',
    ];

    /**
     * Marcas de casas de apuestas observadas en campañas de inyección, incluidas las cinco
     * del caso real de la empresa de cámaras.
     *
     * Es una lista de INDICADORES, no un diccionario cerrado: mañana la campaña usa otros
     * nombres. Por eso no es la única señal y por eso el control no depende de ella. Su
     * valor está en que reconoce de inmediato lo que ya se vio, igual que cualquier lista de
     * indicadores de compromiso.
     *
     * El vocabulario clásico de spam (casino, tragamonedas, apuestas deportivas, farmacia,
     * piratería) NO se repite aquí: lo aporta DetectorSpamSeo y duplicarlo haría que las dos
     * listas se separaran con el tiempo.
     *
     * @var array<int, string>
     */
    private const MARCAS_APUESTAS = [
        'p9bet', '0016bet', '96n', 'kmj888', 'porh300',
        '1xbet', 'fun88', '188bet', '12bet', 'w88', 'm88', 'bj88', 'shbet', 'f8bet',
        '789bet', 'vn88', 'fb88', 'rikvip', 'qh88', 'okvip', 'nohu', 'jilibet',
        'sbobet', 'mega888', '918kiss', 'slot88', 'pgsoft', 'togel', 'situs', 'judi',
    ];

    /**
     * Forma de marca de apuestas: alfanumérico pegado a una raíz del sector.
     *
     * Reconoce p9bet, 0016bet, 789bet, slot88 y sbobet sin conocerlos de antes. Exige al
     * menos un carácter DELANTE de la raíz a propósito: sin eso, "bet" y "win" sueltos
     * harían saltar la señal con cualquier palabra en inglés de una página normal.
     */
    private const PATRON_MARCA_PEGADA = '/\b[a-z0-9]{1,8}(?:bet|bets|slot|slots|casino|lotto|win)[a-z0-9]{0,6}\b/iu';

    /** Letras seguidas de una cifra de la suerte: kmj888, mega888, taya777. */
    private const PATRON_MARCA_CIFRA = '/\b[a-z]{2,8}(?:888|999|777|666|168|1688|918)\b/iu';

    /**
     * Dominios de segundo nivel que obligan a mirar tres etiquetas para saber de quién es el
     * dominio: en www.tienda.com.gt el dominio registrable es tienda.com.gt, no com.gt.
     *
     * @var array<int, string>
     */
    private const SUFIJOS_COMPUESTOS = [
        'com.gt', 'net.gt', 'org.gt', 'edu.gt', 'gob.gt', 'ind.gt', 'mil.gt',
        'com.mx', 'com.ar', 'com.br', 'com.co', 'com.pe', 'com.sv', 'com.hn', 'com.ni',
        'co.uk', 'org.uk', 'ac.uk', 'co.jp', 'com.cn', 'com.au', 'co.nz', 'com.es',
    ];

    public function __construct(
        private readonly ExtractorIndexable $extractor,
        private readonly DetectorSpamSeo $detectorSpam,
        private readonly RegistroIncidentesSeo $registro,
    ) {}

    /**
     * Aviso que la pantalla tiene que enseñar en grande. Vive aquí y no solo en la vista
     * porque el mismo texto se guarda en el resultado: si la comparación acaba en un informe
     * o en un incidente, la condición de uso viaja con él.
     */
    public function avisoDeUso(): string
    {
        return 'Esta herramienta descarga cinco veces la dirección indicada desde el servidor de MarketGT, '
            .'identificándose como navegador y como rastreador. Úsela únicamente sobre sitios propios o sobre '
            .'sitios para los que tenga autorización escrita del responsable. Pedir páginas ajenas de forma '
            .'repetida es, como mínimo, una molestia para su dueño, y según el caso puede ser una infracción.';
    }

    /**
     * Pide la dirección con las cinco identidades y compara lo que vuelve.
     *
     * @param  array{nota?: string|null}  $opciones
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException si la dirección no es pública o no es válida
     */
    public function comparar(string $url, array $opciones = []): array
    {
        $normalizada = $this->normalizarUrl($url);
        $inicio = microtime(true);

        $brutos = [];

        foreach (self::PERFILES as $clave => $perfil) {
            $brutos[$clave] = $this->pedirConPerfil($normalizada, $perfil);
        }

        $resultado = $this->compararCapturas($normalizada, $brutos);

        $resultado['url_escrita'] = trim($url);
        $resultado['duracion_ms'] = (int) round((microtime(true) - $inicio) * 1000);
        $resultado['nota'] = $opciones['nota'] ?? null;

        return $resultado;
    }

    /**
     * El motor de comparación, separado de la red.
     *
     * Está separado por dos razones que no son de estilo: permite probar la lógica con
     * respuestas guardadas —que es como se comprueba que un detector detecta— y permite que
     * la demostración del caso real funcione sin pedirle nada a ningún sitio ajeno.
     *
     * @param  array<string, array{codigo: int|null, url_final: string, cadena: array<int, array<string, mixed>>, cuerpo: string, tipo_contenido: string, error: string|null, ms: int}>  $brutos
     * @return array<string, mixed>
     */
    public function compararCapturas(string $url, array $brutos): array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        $perfiles = [];

        foreach (self::PERFILES as $clave => $definicion) {
            $perfiles[$clave] = $this->analizarCaptura($clave, $definicion, $brutos[$clave] ?? $this->capturaVacia(), $host, $url);
        }

        $referencia = $perfiles['navegador_escritorio'];

        $comparaciones = [];

        foreach ($perfiles as $clave => $perfil) {
            if ($clave === 'navegador_escritorio') {
                continue;
            }

            $comparaciones[$clave] = $this->compararContraReferencia($referencia, $perfil);
        }

        return [
            'url' => $url,
            'url_escrita' => $url,
            'dominio' => $host,
            'generada_en' => Carbon::now()->toIso8601String(),
            'duracion_ms' => array_sum(array_map(static fn (array $b): int => (int) ($b['ms'] ?? 0), $brutos)),
            'perfiles' => $perfiles,
            'comparaciones' => $comparaciones,
            'resumen' => $this->resumir($referencia, $perfiles, $comparaciones),
            'aviso' => $this->avisoDeUso(),
            'simulada' => false,
            'nota' => null,
        ];
    }

    /**
     * El caso real, reproducido con respuestas guardadas.
     *
     * NO pide nada a ningún sitio: las cinco respuestas están escritas aquí, modeladas sobre
     * lo que Search Console reveló en el dominio de la empresa de cámaras. Sirve para tres
     * cosas: demostrar el control el sábado aunque la red falle, no apuntar la herramienta a
     * un dominio ajeno delante de un aula, y comprobar que el detector detecta —que es la
     * única prueba que vale— con los datos del caso y no con un ejemplo inventado.
     *
     * @return array<string, mixed>
     */
    public function demostracionCasoReal(): array
    {
        $url = 'https://camaras-ejemplo.gt/';

        $resultado = $this->compararCapturas($url, $this->capturasDelCasoReal($url));

        $resultado['simulada'] = true;
        $resultado['nota'] = 'Respuestas guardadas, modeladas sobre el caso real de la empresa de cámaras de '
            .'seguridad. No se hizo ninguna petición de red. El dominio camaras-ejemplo.gt es un marcador de '
            .'posición: el dominio real no se publica.';

        return $resultado;
    }

    /**
     * Guarda la comparación y, si el veredicto es concluyente, abre el incidente.
     *
     * Guardar es un acto deliberado y tiene su propio botón, igual que en el resto del
     * capítulo: hay que poder probar una dirección, corregirla y volver a probar sin llenar
     * el historial de tanteos.
     *
     * @param  array<string, mixed>  $resultado
     * @param  array{ip?: string|null, agente_usuario?: string|null, ruta?: string|null, usuario_id?: int|null, notas?: string|null}  $contexto
     */
    public function registrar(array $resultado, array $contexto = []): ?ComparacionContenido
    {
        $resumen = $resultado['resumen'] ?? [];
        $veredicto = (string) ($resumen['veredicto'] ?? ComparacionContenido::VEREDICTO_INCOMPLETO);
        $url = (string) ($resultado['url'] ?? '');
        $dominio = (string) ($resultado['dominio'] ?? '');

        $incidente = null;

        // El incidente se abre ANTES de guardar la fila, no después: si la tabla nueva
        // fallara, el hallazgo tiene que llegar igual al panel y a la bitácora del SIEM.
        // Un control que solo avisa cuando todo lo demás funciona no avisa.
        if (in_array($veredicto, [ComparacionContenido::VEREDICTO_CLOAKING, ComparacionContenido::VEREDICTO_SOSPECHOSO], true)) {
            $incidente = $this->registro->registrar(
                IncidenteSeo::TIPO_CLOAKING,
                $this->resumenParaIncidente($resultado),
                [
                    'severidad' => ComparacionContenido::SEVERIDAD_VEREDICTO[$veredicto] ?? 'alta',
                    'ip' => $contexto['ip'] ?? null,
                    'agente_usuario' => $contexto['agente_usuario'] ?? null,
                    'ruta' => $contexto['ruta'] ?? null,
                    'metodo' => 'GET',
                    'usuario_id' => $contexto['usuario_id'] ?? null,
                    // Se agrupa por dirección: repetir la comprobación diez veces sobre la
                    // misma página es UN incidente con contador, no diez incidentes.
                    'agrupar_por' => 'cloaking:'.$url,
                    'detalle' => [
                        'url' => $url,
                        'puntuacion' => $resumen['puntuacion'] ?? 0,
                        'veredicto' => $veredicto,
                        'concluyentes' => $resumen['concluyentes'] ?? [],
                        'perfiles_divergentes' => $resumen['perfiles_divergentes'] ?? [],
                        'simulada' => (bool) ($resultado['simulada'] ?? false),
                    ],
                ],
            );
        }

        try {
            return ComparacionContenido::query()->create([
                'url' => Str::limit((string) ($resultado['url_escrita'] ?? $url), 490, ''),
                'url_normalizada' => Str::limit($url, 490, ''),
                'dominio' => Str::limit($dominio, 250, ''),
                'puntuacion' => (int) ($resumen['puntuacion'] ?? 0),
                'veredicto' => $veredicto,
                'perfiles' => $this->aligerarPerfiles($resultado['perfiles'] ?? []),
                'comparaciones' => $resultado['comparaciones'] ?? [],
                'resumen' => $resumen,
                'perfiles_alcanzados' => (int) ($resumen['perfiles_alcanzados'] ?? 0),
                'perfiles_fallidos' => (int) ($resumen['perfiles_fallidos'] ?? 0),
                'duracion_ms' => (int) ($resultado['duracion_ms'] ?? 0),
                'huella' => hash('sha256', $url),
                'incidente_seo_id' => $incidente?->id,
                'ejecutada_por' => $contexto['usuario_id'] ?? null,
                'notas' => $contexto['notas'] ?? ($resultado['nota'] ?? null),
            ]);
        } catch (Throwable) {
            // La fila es el historial; el incidente y la bitácora ya salieron. Se devuelve
            // null y la pantalla avisa, en vez de fingir que se guardó.
            return null;
        }
    }

    /**
     * Comparaciones anteriores de la misma dirección, para ver la evolución.
     *
     * @return array<int, ComparacionContenido>
     */
    public function historial(string $url, int $limite = 8): array
    {
        try {
            return ComparacionContenido::query()
                ->deHuella(hash('sha256', $url))
                ->limit(max(1, $limite))
                ->get()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    // -------------------------------------------------------------------------
    // Petición
    // -------------------------------------------------------------------------

    /**
     * Una petición con una identidad, siguiendo las redirecciones A MANO.
     *
     * Se siguen a mano y no con allow_redirects porque hace falta el código y el destino de
     * CADA salto: una página que acaba en 200 después de haber rebotado por un dominio de
     * apuestas se vería sana mirando solo el código final. Y porque cada salto hay que
     * revalidarlo contra la red interna: una redirección a 127.0.0.1 convertiría esta
     * pantalla en un escáner de la red del servidor.
     *
     * @param  array{cabeceras: array<string, string>}  $perfil
     * @return array{codigo: int|null, url_final: string, cadena: array<int, array<string, mixed>>, cuerpo: string, tipo_contenido: string, error: string|null, ms: int}
     */
    private function pedirConPerfil(string $url, array $perfil): array
    {
        $inicio = microtime(true);

        $actual = $url;
        $cadena = [];
        $codigo = null;
        $cuerpo = '';
        $tipoContenido = '';
        $error = null;

        for ($salto = 0; $salto <= self::MAXIMO_SALTOS; $salto++) {
            try {
                $respuesta = Http::withHeaders(array_merge(self::CABECERAS_COMUNES, $perfil['cabeceras']))
                    ->timeout(self::SEGUNDOS_ESPERA)
                    ->connectTimeout(self::SEGUNDOS_CONEXION)
                    ->withoutRedirecting()
                    ->get($actual);
            } catch (Throwable $fallo) {
                // Que un sitio no responda no es un fallo del control: es un dato, y se
                // informa como tal en la columna de ese perfil.
                $error = Str::limit($fallo->getMessage(), 200);

                break;
            }

            $codigo = $respuesta->status();
            $tipoContenido = strtolower((string) $respuesta->header('Content-Type'));
            $destino = trim((string) $respuesta->header('Location'));

            if ($respuesta->redirect() && $destino !== '') {
                $absoluto = $this->resolverRelativa($actual, $destino);

                if ($absoluto === null) {
                    $error = 'Redirección a un esquema que no es http ni https: '.Str::limit($destino, 120);

                    break;
                }

                $anfitrion = strtolower((string) parse_url($absoluto, PHP_URL_HOST));

                if (! $this->anfitrionPublico($anfitrion)) {
                    // Se registra el salto y se para. Que el destino sea interno no se
                    // oculta: es exactamente el tipo de redirección que hay que enseñar.
                    $cadena[] = ['codigo' => $codigo, 'desde' => $actual, 'hacia' => $absoluto];
                    $error = 'La redirección apunta a una dirección interna y no se siguió.';

                    break;
                }

                $cadena[] = ['codigo' => $codigo, 'desde' => $actual, 'hacia' => $absoluto];
                $actual = $absoluto;

                continue;
            }

            $cuerpo = $this->normalizarCodificacion(
                substr($respuesta->body(), 0, self::LIMITE_CUERPO),
                $tipoContenido,
            );

            break;
        }

        if ($cadena !== [] && count($cadena) > self::MAXIMO_SALTOS) {
            $error = 'Bucle de redirecciones: más de '.self::MAXIMO_SALTOS.' saltos.';
        }

        return [
            'codigo' => $codigo,
            'url_final' => $actual,
            'cadena' => $cadena,
            'cuerpo' => $cuerpo,
            'tipo_contenido' => $tipoContenido,
            'error' => $error,
            'ms' => (int) round((microtime(true) - $inicio) * 1000),
        ];
    }

    /**
     * Normaliza lo que escribió el operador y cierra la puerta al servidor interno.
     *
     * El campo lo rellena una persona con sesión de administrador, pero un campo que pide una
     * dirección y la descarga desde el servidor es una petición falsificada del lado del
     * servidor de manual: sin este filtro, escribir http://127.0.0.1:8200 convertiría el
     * panel en un escáner de la red interna.
     */
    public function normalizarUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            throw new InvalidArgumentException('Indique la dirección que se va a comparar.');
        }

        if (preg_match('#^https?://#i', $url) !== 1) {
            if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1) {
                throw new InvalidArgumentException('Solo se comparan direcciones http o https.');
            }

            $url = 'https://'.$url;
        }

        $partes = parse_url($url);

        if ($partes === false || ! isset($partes['host']) || $partes['host'] === '') {
            throw new InvalidArgumentException('La dirección no tiene un anfitrión válido.');
        }

        $host = strtolower((string) $partes['host']);

        if (! $this->anfitrionPublico($host)) {
            throw new InvalidArgumentException('Solo se comparan anfitriones públicos: '.$host.' resuelve a una dirección interna.');
        }

        $esquema = strtolower((string) ($partes['scheme'] ?? 'https'));
        $puerto = isset($partes['port']) ? ':'.((int) $partes['port']) : '';
        $ruta = (string) ($partes['path'] ?? '/');
        $consulta = isset($partes['query']) ? '?'.$partes['query'] : '';

        return $esquema.'://'.$host.$puerto.($ruta === '' ? '/' : $ruta).$consulta;
    }

    private function anfitrionPublico(string $host): bool
    {
        if ($host === '') {
            return false;
        }

        $propio = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        // El propio sitio se admite aunque resuelva a una dirección privada: la demostración
        // del sábado corre en la máquina del aula y el control tiene que poder apuntarse a sí
        // mismo, que es además el único uso sin ninguna duda ética.
        if ($propio !== '' && $host === $propio) {
            return true;
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : gethostbyname($host);

        if ($ip === $host && filter_var($host, FILTER_VALIDATE_IP) === false) {
            // No resolvió. Se deja pasar: el error real lo dará la petición, y rechazar aquí
            // confundiría un DNS lento con un destino prohibido.
            return true;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /** Convierte un Location relativo en absoluto. Devuelve null si no es http ni https. */
    private function resolverRelativa(string $base, string $destino): ?string
    {
        if (preg_match('#^https?://#i', $destino) === 1) {
            return $destino;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $destino) === 1) {
            return null;
        }

        $partes = parse_url($base);
        $esquema = (string) ($partes['scheme'] ?? 'https');
        $host = (string) ($partes['host'] ?? '');
        $puerto = isset($partes['port']) ? ':'.((int) $partes['port']) : '';

        if ($host === '') {
            return null;
        }

        if (str_starts_with($destino, '//')) {
            return $esquema.':'.$destino;
        }

        if (str_starts_with($destino, '/')) {
            return $esquema.'://'.$host.$puerto.$destino;
        }

        $directorio = rtrim((string) preg_replace('#/[^/]*$#', '/', (string) ($partes['path'] ?? '/')), '');

        return $esquema.'://'.$host.$puerto.($directorio === '' ? '/' : $directorio).$destino;
    }

    /**
     * Pasa el cuerpo a UTF-8 usando el juego que declara la cabecera.
     *
     * Sin esto, una página en latin-1 llegaría con los acentos rotos y la comparación de
     * títulos marcaría como distinta una página que no cambió: un falso positivo nacido de
     * la codificación, no del sitio.
     */
    private function normalizarCodificacion(string $cuerpo, string $tipoContenido): string
    {
        if (preg_match('/charset\s*=\s*"?([a-z0-9_-]+)/i', $tipoContenido, $coincidencia) === 1) {
            $juego = strtolower($coincidencia[1]);

            if (! in_array($juego, ['utf-8', 'utf8'], true) && in_array($juego, array_map('strtolower', mb_list_encodings()), true)) {
                $convertido = @mb_convert_encoding($cuerpo, 'UTF-8', $juego);

                if (is_string($convertido)) {
                    return $convertido;
                }
            }
        }

        return $this->detectorSpam->sanearUtf8($cuerpo);
    }

    // -------------------------------------------------------------------------
    // Análisis de una respuesta
    // -------------------------------------------------------------------------

    /**
     * @return array{codigo: int|null, url_final: string, cadena: array<int, array<string, mixed>>, cuerpo: string, tipo_contenido: string, error: string|null, ms: int}
     */
    private function capturaVacia(): array
    {
        return [
            'codigo' => null,
            'url_final' => '',
            'cadena' => [],
            'cuerpo' => '',
            'tipo_contenido' => '',
            'error' => 'No se ejecutó esta identidad.',
            'ms' => 0,
        ];
    }

    /**
     * @param  array{etiqueta: string, descripcion: string, referencia: bool, cabeceras: array<string, string>}  $definicion
     * @param  array{codigo: int|null, url_final: string, cadena: array<int, array<string, mixed>>, cuerpo: string, tipo_contenido: string, error: string|null, ms: int}  $bruto
     * @return array<string, mixed>
     */
    private function analizarCaptura(string $clave, array $definicion, array $bruto, string $host, string $urlSolicitada): array
    {
        $cuerpo = (string) ($bruto['cuerpo'] ?? '');
        $resumen = $cuerpo === ''
            ? $this->resumenVacio()
            : $this->extractor->resumen($cuerpo, $host);

        $textoVisible = $cuerpo === '' ? '' : $this->textoVisible($cuerpo);
        $spam = $this->detectorSpam->analizar($this->materialParaSpam($textoVisible, $resumen));
        $urlFinal = (string) ($bruto['url_final'] ?? $urlSolicitada);
        $hostFinal = strtolower((string) parse_url($urlFinal, PHP_URL_HOST));

        return [
            'clave' => $clave,
            'etiqueta' => $definicion['etiqueta'],
            'descripcion' => $definicion['descripcion'],
            'referencia' => $definicion['referencia'],
            'agente_usuario' => $definicion['cabeceras']['User-Agent'] ?? '',
            'procedencia' => $definicion['cabeceras']['Referer'] ?? null,
            'alcanzado' => $bruto['error'] === null && $bruto['codigo'] !== null,
            'error' => $bruto['error'] ?? null,
            'codigo' => $bruto['codigo'] ?? null,
            'ms' => (int) ($bruto['ms'] ?? 0),
            'tipo_contenido' => (string) ($bruto['tipo_contenido'] ?? ''),
            'cadena' => array_values($bruto['cadena'] ?? []),
            'url_final' => $urlFinal,
            'host_final' => $hostFinal,
            'salio_del_dominio' => $hostFinal !== '' && ! $this->mismoDominioRegistrable($hostFinal, $host),
            'titulo' => (string) $resumen['titulo'],
            'descripcion_meta' => (string) $resumen['meta_descripcion'],
            'canonico' => (string) $resumen['canonico'],
            'meta_robots' => (string) $resumen['meta_robots'],
            'base_href' => (string) $resumen['base_href'],
            'encabezados' => array_values($resumen['encabezados']),
            'hosts_enlazados' => array_values($resumen['hosts_enlazados']),
            'enlaces_externos' => (int) $resumen['enlaces_externos'],
            'enlaces_sin_nofollow' => (int) $resumen['enlaces_sin_nofollow'],
            'longitud_visible' => mb_strlen($textoVisible),
            'muestra_texto' => Str::limit($textoVisible, self::LIMITE_MUESTRA),
            'spam' => [
                'puntuacion' => $spam['puntuacion'],
                'motivos' => $spam['motivos'],
            ],
            'marcas' => $this->marcasDeApuestas($textoVisible, $resumen),
        ];
    }

    /**
     * @return array{titulo: string, canonico: string, meta_robots: string, meta_descripcion: string, encabezados: array<int, string>, hosts_enlazados: array<int, string>, enlaces_externos: int, enlaces_sin_nofollow: int, longitud_texto: int, base_href: string}
     */
    private function resumenVacio(): array
    {
        return [
            'titulo' => '',
            'canonico' => '',
            'meta_robots' => '',
            'meta_descripcion' => '',
            'encabezados' => [],
            'hosts_enlazados' => [],
            'enlaces_externos' => 0,
            'enlaces_sin_nofollow' => 0,
            'longitud_texto' => 0,
            'base_href' => '',
        ];
    }

    /**
     * Texto que de verdad se lee en la página.
     *
     * ExtractorIndexable ya da una longitud de texto, pero la calcula sobre textContent del
     * documento entero, que incluye el cuerpo de los <script>. Para comparar dos versiones
     * eso no sirve: una página que solo se diferencia en un script de analítica de 40 KB
     * aparecería como una diferencia enorme de contenido. Aquí se quita lo que no se lee.
     */
    private function textoVisible(string $html): string
    {
        $limpio = (string) preg_replace(
            '#<(script|style|noscript|template|svg|iframe|head)\b[^>]*>.*?</\1>#is',
            ' ',
            $html,
        );

        $limpio = (string) preg_replace('#<!--.*?-->#s', ' ', $limpio);
        $limpio = (string) preg_replace('#<br\s*/?>|</(p|div|li|h[1-6]|tr)>#i', "\n", $limpio);
        $limpio = strip_tags($limpio);
        $limpio = html_entity_decode($limpio, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $limpio = $this->detectorSpam->sanearUtf8($limpio);

        return trim((string) preg_replace('/\s+/u', ' ', $limpio));
    }

    /**
     * Lo que se le pasa al detector de spam: el texto visible más los anfitriones enlazados
     * escritos como direcciones, para que sus reglas de acortadores y de dominios desechables
     * puedan opinar sobre ellos. Sin esta segunda parte, una página cuyo único contenido
     * inyectado son tres enlaces no puntuaría nada.
     *
     * @param  array<string, mixed>  $resumen
     */
    private function materialParaSpam(string $textoVisible, array $resumen): string
    {
        $hosts = array_map(
            static fn (string $host): string => 'https://'.$host.'/',
            array_slice((array) $resumen['hosts_enlazados'], 0, 40),
        );

        return mb_substr($textoVisible, 0, self::LIMITE_TEXTO_ANALIZADO)
            ."\n".implode(' ', $hosts)
            ."\n".(string) $resumen['titulo']
            ."\n".(string) $resumen['meta_descripcion'];
    }

    /**
     * Marcas de apuestas encontradas, con dónde estaban.
     *
     * El sitio donde aparece la marca cambia el peso: en el título o en un anfitrión enlazado
     * es contenido inyectado; suelta en mitad del texto puede ser una palabra desafortunada.
     *
     * @param  array<string, mixed>  $resumen
     * @return array<int, array{marca: string, donde: string, fuerte: bool}>
     */
    private function marcasDeApuestas(string $textoVisible, array $resumen): array
    {
        $hallazgos = [];
        $vistas = [];

        $zonas = [
            'titulo' => (string) $resumen['titulo'],
            'descripcion' => (string) $resumen['meta_descripcion'],
            'encabezados' => implode(' ', (array) $resumen['encabezados']),
            'texto' => mb_substr($textoVisible, 0, self::LIMITE_TEXTO_ANALIZADO),
        ];

        foreach ($zonas as $donde => $contenido) {
            if ($contenido === '') {
                continue;
            }

            foreach ($this->marcasEn($contenido) as $marca) {
                if (isset($vistas[$marca])) {
                    continue;
                }

                $vistas[$marca] = true;
                $hallazgos[] = [
                    'marca' => $marca,
                    'donde' => $donde,
                    'fuerte' => in_array($donde, ['titulo', 'descripcion', 'encabezados'], true),
                ];
            }
        }

        foreach ((array) $resumen['hosts_enlazados'] as $host) {
            $nombre = $this->nombreRegistrable((string) $host);

            foreach ($this->marcasEn($nombre) as $marca) {
                if (isset($vistas[$marca])) {
                    continue;
                }

                $vistas[$marca] = true;
                $hallazgos[] = ['marca' => $marca, 'donde' => 'enlace a '.$host, 'fuerte' => true];
            }

            // Anfitrión con nombre corto que mezcla letras y cifras: 96n.com, p9bet.com. No
            // es prueba de nada por sí solo —123rf.com es una empresa legítima—, por eso solo
            // cuenta cuando además es EXCLUSIVO de una de las dos versiones.
            if (! isset($vistas[$nombre]) && preg_match('/^(?=.{2,10}$)(?=[a-z0-9-]*\d)(?=[a-z0-9-]*[a-z])[a-z0-9-]+$/i', $nombre) === 1) {
                $vistas[$nombre] = true;
                $hallazgos[] = ['marca' => $nombre, 'donde' => 'enlace a '.$host, 'fuerte' => false];
            }
        }

        return $hallazgos;
    }

    /**
     * @return array<int, string>
     */
    private function marcasEn(string $texto): array
    {
        $texto = mb_strtolower($texto);
        $encontradas = [];

        foreach (self::MARCAS_APUESTAS as $marca) {
            if (preg_match('/\b'.preg_quote($marca, '/').'\b/u', $texto) === 1) {
                $encontradas[] = $marca;
            }
        }

        foreach ([self::PATRON_MARCA_PEGADA, self::PATRON_MARCA_CIFRA] as $patron) {
            if (preg_match_all($patron, $texto, $coincidencias) > 0) {
                foreach (array_slice($coincidencias[0], 0, 8) as $coincidencia) {
                    $encontradas[] = (string) $coincidencia;
                }
            }
        }

        return array_values(array_unique($encontradas));
    }

    // -------------------------------------------------------------------------
    // Comparación
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $otro
     * @return array<string, mixed>
     */
    private function compararContraReferencia(array $base, array $otro): array
    {
        $clave = (string) $otro['clave'];

        if (! $base['alcanzado'] || ! $otro['alcanzado']) {
            return [
                'perfil' => $clave,
                'etiqueta' => (string) $otro['etiqueta'],
                'puntuacion' => 0,
                'veredicto' => ComparacionContenido::VEREDICTO_INCOMPLETO,
                'concluyente' => false,
                'senales' => [],
                'motivo' => ! $base['alcanzado']
                    ? 'No hay con qué comparar: la versión de navegador no respondió.'
                    : 'Esta identidad no obtuvo respuesta: '.((string) ($otro['error'] ?? 'sin detalle')),
            ];
        }

        $senales = array_values(array_filter([
            $this->senalRedireccion($base, $otro),
            $this->senalCodigo($base, $otro),
            $this->senalSpamExclusivo($base, $otro),
            $this->senalMarcasExclusivas($base, $otro),
            $this->senalHostsExclusivos($base, $otro),
            $this->senalTitulo($base, $otro),
            $this->senalDescripcion($base, $otro),
            $this->senalCanonico($base, $otro),
            $this->senalMetaRobots($base, $otro),
            $this->senalBaseHref($base, $otro),
            $this->senalEnlaces($base, $otro),
            $this->senalLongitud($base, $otro),
            $this->senalEncabezados($base, $otro),
        ]));

        $hayConcluyente = array_reduce(
            $senales,
            static fn (bool $acumulado, array $senal): bool => $acumulado || $senal['concluyente'],
            false,
        );

        $senales = $this->moderar($senales, $clave, $hayConcluyente, $base, $otro);

        $puntuacion = array_sum(array_column($senales, 'puntos'));

        if ($hayConcluyente) {
            // Una señal concluyente no se diluye con la suma. Una redirección a un dominio de
            // apuestas que solo ocurre con el rastreador es cloaking aunque el resto de la
            // página sea idéntica, y el número tiene que decir lo mismo que el veredicto.
            $puntuacion = max($puntuacion, self::UMBRAL_CLOAKING);
        }

        return [
            'perfil' => $clave,
            'etiqueta' => (string) $otro['etiqueta'],
            'puntuacion' => (int) $puntuacion,
            'veredicto' => $this->veredictoDe((int) $puntuacion),
            'concluyente' => $hayConcluyente,
            'senales' => $senales,
            'motivo' => $this->motivoDe($senales, (int) $puntuacion),
        ];
    }

    /**
     * Aplica la tabla de tolerancias.
     *
     * @param  array<int, array<string, mixed>>  $senales
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $otro
     * @return array<int, array<string, mixed>>
     */
    private function moderar(array $senales, string $perfil, bool $hayConcluyente, array $base, array $otro): array
    {
        $tolerancias = self::TOLERANCIAS[$perfil] ?? [];

        foreach ($senales as $indice => $senal) {
            $clave = (string) $senal['clave'];

            if ($hayConcluyente || ! isset($tolerancias[$clave])) {
                continue;
            }

            $motivo = $tolerancias[$clave];

            // El renderizado previo AÑADE contenido. Si la versión del rastreador tiene
            // MENOS texto que la del navegador, no es renderizado previo, y entonces la
            // tolerancia no aplica: esconderle contenido al buscador también es cloaking.
            if ($motivo === 'renderizado_previo' && $otro['longitud_visible'] < $base['longitud_visible']) {
                continue;
            }

            // La versión móvil en un subdominio es esperable; saltar a otro dominio no.
            if ($motivo === 'subdominio_movil' && $otro['salio_del_dominio']) {
                continue;
            }

            $senales[$indice]['puntos'] = 0;
            $senales[$indice]['esperable'] = true;
            $senales[$indice]['explicacion'] = self::EXPLICACION_TOLERANCIA[$motivo] ?? '';
        }

        return $senales;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $otro
     * @return array<string, mixed>|null
     */
    private function senalRedireccion(array $base, array $otro): ?array
    {
        $baseSalio = (bool) $base['salio_del_dominio'];
        $otroSalio = (bool) $otro['salio_del_dominio'];

        if ($otroSalio && ! $baseSalio) {
            return $this->senal(
                'redireccion_ajena',
                'Redirección a otro dominio solo con esta identidad',
                10,
                'Se queda en '.($base['host_final'] ?: 'el mismo dominio'),
                'Termina en '.$otro['host_final'],
                concluyente: true,
                explicacion: 'Es la prueba más clara que existe de contenido diferenciado: la misma dirección manda a la persona a un sitio y al rastreador a otro.',
            );
        }

        $cadenaBase = count((array) $base['cadena']);
        $cadenaOtro = count((array) $otro['cadena']);

        if ($cadenaOtro > 0 && $cadenaBase === 0) {
            return $this->senal(
                'redireccion_exclusiva',
                'Redirige solo con esta identidad',
                4,
                'Responde directamente, sin redirección',
                $cadenaOtro.' salto(s) hasta '.$otro['url_final'],
            );
        }

        if ($cadenaOtro > 0 && $cadenaBase > 0 && $base['url_final'] !== $otro['url_final']) {
            return $this->senal(
                'destino_distinto',
                'Las redirecciones acaban en direcciones distintas',
                3,
                (string) $base['url_final'],
                (string) $otro['url_final'],
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $otro
     * @return array<string, mixed>|null
     */
    private function senalCodigo(array $base, array $otro): ?array
    {
        if ($base['codigo'] === $otro['codigo']) {
            return null;
        }

        return $this->senal(
            'codigo_distinto',
            'Código de respuesta distinto',
            4,
            (string) $base['codigo'],
            (string) $otro['codigo'],
            explicacion: 'Servir un 404 a la persona y un 200 al rastreador, o al revés, es la forma más barata de esconder una página inyectada.',
        );
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $otro
     * @return array<string, mixed>|null
     */
    private function senalSpamExclusivo(array $base, array $otro): ?array
    {
        // Estas dos reglas hablan de la ESTRUCTURA de la página, no del contenido inyectado, y
        // ya tienen su propia señal de diferencia de enlaces. Contarlas aquí sería contar lo
        // mismo dos veces.
        $ignoradas = ['exceso_enlaces', 'marcado_de_enlace'];

        $reglasBase = array_column((array) $base['spam']['motivos'], 'regla');
        $exclusivas = [];

        foreach ((array) $otro['spam']['motivos'] as $motivo) {
            $regla = (string) $motivo['regla'];

            if (in_array($regla, $ignoradas, true) || in_array($regla, $reglasBase, true)) {
                continue;
            }

            $exclusivas[] = $motivo;
        }

        if ($exclusivas === []) {
            return null;
        }

        $puntos = min(10, (int) array_sum(array_column($exclusivas, 'puntos')) + 2);
        $descripciones = array_map(
            static fn (array $motivo): string => (string) $motivo['descripcion'].' ('.$motivo['evidencia'].')',
            $exclusivas,
        );

        return $this->senal(
            'spam_exclusivo',
            'Vocabulario de sector de abuso presente solo en esta versión',
            $puntos,
            'Ninguna de estas señales',
            implode(' · ', $descripciones),
            // Cinco puntos es el umbral que usa el detector de spam del proyecto y el que usa
            // el WAF. Se mantiene el mismo criterio para que las tres capas no se contradigan.
            concluyente: $puntos >= DetectorSpamSeo::UMBRAL,
            explicacion: 'El detector de spam del proyecto reconoce estas señales en la versión servida a esta identidad y no en la del navegador.',
        );
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $otro
     * @return array<string, mixed>|null
     */
    private function senalMarcasExclusivas(array $base, array $otro): ?array
    {
        $enBase = array_column((array) $base['marcas'], 'marca');
        $exclusivas = array_values(array_filter(
            (array) $otro['marcas'],
            static fn (array $marca): bool => ! in_array($marca['marca'], $enBase, true),
        ));

        if ($exclusivas === []) {
            return null;
        }

        $fuertes = array_values(array_filter($exclusivas, static fn (array $marca): bool => (bool) $marca['fuerte']));
        $puntos = $fuertes !== [] ? 8 : 3;

        $descripciones = array_map(
            static fn (array $marca): string => $marca['marca'].' (en '.$marca['donde'].')',
            array_slice($exclusivas, 0, 6),
        );

        return $this->senal(
            'marca_apuestas_exclusiva',
            'Marca de casa de apuestas solo en esta versión',
            $puntos,
            'Ninguna',
            implode(' · ', $descripciones),
            concluyente: $fuertes !== [],
            explicacion: $fuertes !== []
                ? 'Aparece en el título, en un encabezado o como dominio enlazado. Es exactamente el patrón del caso real: p9bet, 0016bet, 96n.com, kmj888 y porh300 bajo un dominio de cámaras de seguridad.'
                : 'Aparece suelta en el texto. Por sí sola no prueba nada, por eso suma poco y no decide el veredicto.',
        );
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $otro
     * @return array<string, mixed>|null
     */
    private function senalHostsExclusivos(array $base, array $otro): ?array
    {
        $exclusivos = array_values(array_diff((array) $otro['hosts_enlazados'], (array) $base['hosts_enlazados']));

        if ($exclusivos === []) {
            return null;
        }

        return $this->senal(
            'hosts_exclusivos',
            'Enlaza a dominios que la versión de navegador no enlaza',
            min(6, count($exclusivos) * 2),
            count((array) $base['hosts_enlazados']).' dominios externos',
            implode(', ', array_slice($exclusivos, 0, 8)).(count($exclusivos) > 8 ? ' y '.(count($exclusivos) - 8).' más' : ''),
            explicacion: 'Ceder autoridad de enlace hacia fuera es el premio que busca quien inyecta contenido; si esos enlaces solo existen para el rastreador, el premio se cobra sin que nadie lo vea.',
        );
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $otro
     * @return array<string, mixed>|null
     */
    private function senalTitulo(array $base, array $otro): ?array
    {
        $antes = (string) $base['titulo'];
        $ahora = (string) $otro['titulo'];

        if ($antes === $ahora) {
            return null;
        }

        $parecido = $this->parecido($antes, $ahora);

        if ($parecido >= 90.0) {
            return null;
        }

        return $this->senal(
            'titulo_distinto',
            'Título distinto',
            $parecido < 60.0 ? 4 : 2,
            $antes !== '' ? $antes : '(sin título)',
            $ahora !== '' ? $ahora : '(sin título)',
            explicacion: 'El título es lo que el buscador enseña en sus resultados. Cambiarlo solo para el rastreador es cambiar lo que el dominio parece ser.',
        );
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $otro
     * @return array<string, mixed>|null
     */
    private function senalDescripcion(array $base, array $otro): ?array
    {
        $antes = (string) $base['descripcion_meta'];
        $ahora = (string) $otro['descripcion_meta'];

        if ($antes === $ahora || $this->parecido($antes, $ahora) >= 90.0) {
            return null;
        }

        return $this->senal(
            'descripcion_distinta',
            'Descripción distinta',
            2,
            $antes !== '' ? Str::limit($antes, 160) : '(sin descripción)',
            $ahora !== '' ? Str::limit($ahora, 160) : '(sin descripción)',
        );
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $otro
     * @return array<string, mixed>|null
     */
    private function senalCanonico(array $base, array $otro): ?array
    {
        $antes = (string) $base['canonico'];
        $ahora = (string) $otro['canonico'];

        if ($antes === $ahora) {
            return null;
        }

        $hostCanonico = strtolower((string) parse_url($ahora, PHP_URL_HOST));
        $seFuga = $hostCanonico !== '' && ! $this->mismoDominioRegistrable($hostCanonico, (string) $base['host_final']);

        return $this->senal(
            'canonico_distinto',
            $seFuga ? 'Canónico apuntando a otro dominio solo en esta versión' : 'Etiqueta canónica distinta',
            $seFuga ? 7 : 3,
            $antes !== '' ? $antes : '(sin canónico)',
            $ahora !== '' ? $ahora : '(sin canónico)',
            explicacion: $seFuga
                ? 'Un canónico hacia fuera le dice al buscador que el contenido original vive en el otro dominio: es la forma de transferirle la autoridad del sitio al atacante.'
                : '',
        );
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $otro
     * @return array<string, mixed>|null
     */
    private function senalMetaRobots(array $base, array $otro): ?array
    {
        $antes = (string) $base['meta_robots'];
        $ahora = (string) $otro['meta_robots'];

        if ($antes === $ahora) {
            return null;
        }

        return $this->senal(
            'meta_robots_distinta',
            'Directiva meta robots distinta',
            4,
            $antes !== '' ? $antes : '(sin meta robots)',
            $ahora !== '' ? $ahora : '(sin meta robots)',
            explicacion: 'Marcar noindex para la persona e index para el rastreador deja la página fuera de cualquier revisión manual y dentro del índice.',
        );
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $otro
     * @return array<string, mixed>|null
     */
    private function senalBaseHref(array $base, array $otro): ?array
    {
        $antes = (string) $base['base_href'];
        $ahora = (string) $otro['base_href'];

        if ($antes === $ahora || $ahora === '') {
            return null;
        }

        return $this->senal(
            'base_href_exclusiva',
            'Etiqueta base inyectada solo en esta versión',
            6,
            $antes !== '' ? $antes : '(sin base href)',
            $ahora,
            explicacion: 'Un base href reescribe TODAS las rutas relativas de la página hacia otro servidor sin tocar ni un solo enlace a la vista.',
        );
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $otro
     * @return array<string, mixed>|null
     */
    private function senalEnlaces(array $base, array $otro): ?array
    {
        $antes = (int) $base['enlaces_externos'];
        $ahora = (int) $otro['enlaces_externos'];
        $diferencia = abs($antes - $ahora);

        if ($diferencia === 0) {
            return null;
        }

        $porcentaje = $this->porcentajeDeDiferencia($antes, $ahora);

        if ($porcentaje < 30.0 && $diferencia < 5) {
            return null;
        }

        return $this->senal(
            'enlaces_diferencia',
            'Cantidad de enlaces externos distinta',
            ($porcentaje >= 100.0 || $diferencia >= 10) ? 3 : 1,
            $antes.' enlaces externos',
            $ahora.' enlaces externos ('.round($porcentaje).' % de diferencia)',
        );
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $otro
     * @return array<string, mixed>|null
     */
    private function senalLongitud(array $base, array $otro): ?array
    {
        $antes = (int) $base['longitud_visible'];
        $ahora = (int) $otro['longitud_visible'];
        $porcentaje = $this->porcentajeDeDiferencia($antes, $ahora);

        // Por debajo del diez por ciento no se informa siquiera: es el ruido normal de
        // cualquier página con una fecha, un contador o un producto destacado rotatorio.
        if ($porcentaje < 10.0) {
            return null;
        }

        $puntos = match (true) {
            $porcentaje >= 60.0 => 3,
            $porcentaje >= 30.0 => 2,
            default => 1,
        };

        return $this->senal(
            'longitud_distinta',
            'Longitud del texto visible distinta',
            $puntos,
            $antes.' caracteres',
            $ahora.' caracteres ('.round($porcentaje).' % de diferencia)',
            explicacion: 'Por sí sola una diferencia de longitud no prueba nada: hay mil razones honestas para que una página mida distinto dos veces seguidas.',
        );
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $otro
     * @return array<string, mixed>|null
     */
    private function senalEncabezados(array $base, array $otro): ?array
    {
        $exclusivos = array_values(array_diff((array) $otro['encabezados'], (array) $base['encabezados']));

        if ($exclusivos === []) {
            return null;
        }

        return $this->senal(
            'encabezados_exclusivos',
            'Encabezados que la versión de navegador no tiene',
            2,
            implode(' · ', array_slice((array) $base['encabezados'], 0, 3)) ?: '(sin encabezados)',
            implode(' · ', array_slice($exclusivos, 0, 3)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function senal(
        string $clave,
        string $titulo,
        int $puntos,
        string $navegador,
        string $otro,
        bool $concluyente = false,
        string $explicacion = '',
    ): array {
        return [
            'clave' => $clave,
            'titulo' => $titulo,
            'puntos' => $puntos,
            'peso' => $puntos,
            'concluyente' => $concluyente,
            'esperable' => false,
            'explicacion' => $explicacion,
            'navegador' => Str::limit($navegador, 300),
            'otro' => Str::limit($otro, 300),
        ];
    }

    // -------------------------------------------------------------------------
    // Veredicto
    // -------------------------------------------------------------------------

    private function veredictoDe(int $puntuacion): string
    {
        return match (true) {
            $puntuacion >= self::UMBRAL_CLOAKING => ComparacionContenido::VEREDICTO_CLOAKING,
            $puntuacion >= self::UMBRAL_SOSPECHA => ComparacionContenido::VEREDICTO_SOSPECHOSO,
            $puntuacion > 0 => ComparacionContenido::VEREDICTO_ESPERABLE,
            default => ComparacionContenido::VEREDICTO_SIN_DIFERENCIAS,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $senales
     */
    private function motivoDe(array $senales, int $puntuacion): string
    {
        $concluyentes = array_values(array_filter($senales, static fn (array $senal): bool => (bool) $senal['concluyente']));

        if ($concluyentes !== []) {
            return implode('. ', array_map(static fn (array $senal): string => (string) $senal['titulo'], $concluyentes)).'.';
        }

        if ($senales === []) {
            return 'Las señales estables coinciden con la versión de navegador.';
        }

        $puntuadas = array_values(array_filter($senales, static fn (array $senal): bool => $senal['puntos'] > 0));

        if ($puntuadas === []) {
            return 'Hay diferencias, pero todas encajan con una explicación legítima.';
        }

        return $puntuacion.' puntos: '.implode(', ', array_map(
            static fn (array $senal): string => mb_strtolower((string) $senal['titulo']),
            array_slice($puntuadas, 0, 4),
        )).'.';
    }

    /**
     * @param  array<string, mixed>  $referencia
     * @param  array<string, array<string, mixed>>  $perfiles
     * @param  array<string, array<string, mixed>>  $comparaciones
     * @return array<string, mixed>
     */
    private function resumir(array $referencia, array $perfiles, array $comparaciones): array
    {
        $alcanzados = count(array_filter($perfiles, static fn (array $perfil): bool => (bool) $perfil['alcanzado']));
        $fallidos = count($perfiles) - $alcanzados;

        $puntuacion = 0;
        $concluyentes = [];
        $divergentes = [];

        foreach ($comparaciones as $clave => $comparacion) {
            $puntuacion = max($puntuacion, (int) $comparacion['puntuacion']);

            if ((int) $comparacion['puntuacion'] >= self::UMBRAL_SOSPECHA) {
                $divergentes[] = (string) $clave;
            }

            foreach ((array) $comparacion['senales'] as $senal) {
                if ($senal['concluyente']) {
                    $concluyentes[] = $perfiles[$clave]['etiqueta'].': '.$senal['titulo'];
                }
            }
        }

        $veredicto = ! $referencia['alcanzado']
            ? ComparacionContenido::VEREDICTO_INCOMPLETO
            : $this->veredictoDe($puntuacion);

        // Contenido de sector de abuso en TODAS las versiones no es cloaking: es una
        // inyección que ni siquiera se molesta en esconderse. Se informa aparte porque el
        // hallazgo es igual de grave y el control no debe callarlo solo porque no hay
        // diferencia entre versiones.
        $spamEnTodas = $alcanzados > 0 && ! array_filter(
            $perfiles,
            static fn (array $perfil): bool => $perfil['alcanzado'] && (int) $perfil['spam']['puntuacion'] < DetectorSpamSeo::UMBRAL,
        );

        return [
            'puntuacion' => $puntuacion,
            'veredicto' => $veredicto,
            'etiqueta_veredicto' => ComparacionContenido::ETIQUETAS_VEREDICTO[$veredicto] ?? $veredicto,
            'concluyentes' => array_values(array_unique($concluyentes)),
            'perfiles_divergentes' => $divergentes,
            'perfiles_alcanzados' => $alcanzados,
            'perfiles_fallidos' => $fallidos,
            'spam_en_todas_las_versiones' => $spamEnTodas,
            'referencia_alcanzada' => (bool) $referencia['alcanzado'],
            'explicacion' => $this->explicacionGlobal($veredicto, $fallidos, $spamEnTodas),
        ];
    }

    private function explicacionGlobal(string $veredicto, int $fallidos, bool $spamEnTodas): string
    {
        $base = match ($veredicto) {
            ComparacionContenido::VEREDICTO_CLOAKING => 'El sitio sirve contenido distinto según quién lo pida. Lo que el buscador indexa de este dominio no es lo que la página enseña a las personas.',
            ComparacionContenido::VEREDICTO_SOSPECHOSO => 'Hay diferencias que no se explican solas. Hace falta que una persona abra la página con las dos identidades y confirme.',
            ComparacionContenido::VEREDICTO_ESPERABLE => 'Hay diferencias, pero todas caen dentro de lo que un sitio honesto produce.',
            ComparacionContenido::VEREDICTO_SIN_DIFERENCIAS => 'Las señales estables coinciden en las cinco identidades.',
            default => 'No se pudo completar la comparación.',
        };

        if ($spamEnTodas) {
            $base .= ' Además, el vocabulario de sectores de abuso aparece en TODAS las versiones: si hay contenido inyectado, no está escondido, está a la vista de cualquiera.';
        }

        if ($fallidos > 0) {
            $base .= ' Atención: '.$fallidos.' de las cinco identidades no obtuvieron respuesta, de modo que este veredicto es parcial.';
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $resultado
     */
    private function resumenParaIncidente(array $resultado): string
    {
        $resumen = $resultado['resumen'] ?? [];
        $concluyentes = (array) ($resumen['concluyentes'] ?? []);

        return 'Contenido diferenciado en '.((string) ($resultado['url'] ?? '')).': '
            .($resumen['puntuacion'] ?? 0).' puntos de divergencia. '
            .($concluyentes !== [] ? implode('; ', array_slice($concluyentes, 0, 3)) : ((string) ($resumen['explicacion'] ?? '')));
    }

    /**
     * El cuerpo de cada perfil se recorta antes de guardarlo: la muestra de texto ya está
     * limitada, pero los motivos de spam y la lista de anfitriones pueden crecer mucho en una
     * página inyectada, y la columna JSON no es un almacén de páginas.
     *
     * @param  array<string, array<string, mixed>>  $perfiles
     * @return array<string, array<string, mixed>>
     */
    private function aligerarPerfiles(array $perfiles): array
    {
        foreach ($perfiles as $clave => $perfil) {
            $perfiles[$clave]['hosts_enlazados'] = array_slice((array) $perfil['hosts_enlazados'], 0, 30);
            $perfiles[$clave]['encabezados'] = array_slice((array) $perfil['encabezados'], 0, 10);
            $perfiles[$clave]['marcas'] = array_slice((array) $perfil['marcas'], 0, 15);
            $perfiles[$clave]['cadena'] = array_slice((array) $perfil['cadena'], 0, 6);
        }

        return $perfiles;
    }

    // -------------------------------------------------------------------------
    // Utilidades
    // -------------------------------------------------------------------------

    /** Porcentaje de diferencia relativo al mayor de los dos valores. */
    private function porcentajeDeDiferencia(int $antes, int $ahora): float
    {
        $mayor = max($antes, $ahora);

        return $mayor === 0 ? 0.0 : abs($antes - $ahora) / $mayor * 100;
    }

    /**
     * Parecido entre dos cadenas cortas, en porcentaje.
     *
     * Se usa para no dar la alarma cuando el título solo cambia en el contador del carrito o
     * en una coma. Se aplica únicamente a cadenas cortas porque similar_text es cúbico en el
     * peor caso y un título de 50 KB colgaría la comparación.
     */
    private function parecido(string $primera, string $segunda): float
    {
        if ($primera === '' && $segunda === '') {
            return 100.0;
        }

        if ($primera === '' || $segunda === '') {
            return 0.0;
        }

        if (mb_strlen($primera) > 500 || mb_strlen($segunda) > 500) {
            return $primera === $segunda ? 100.0 : 0.0;
        }

        $porcentaje = 0.0;
        similar_text(mb_strtolower($primera), mb_strtolower($segunda), $porcentaje);

        return $porcentaje;
    }

    /**
     * ¿Los dos anfitriones pertenecen al mismo dominio registrable?
     *
     * www.tienda.com.gt y tienda.com.gt son el mismo sitio; tienda.com.gt y 96n.com no. Sin
     * la lista de sufijos compuestos, comparar las dos últimas etiquetas diría que
     * tienda.com.gt y atacante.com.gt son el mismo dominio, que es justo el error que
     * dejaría pasar la redirección importante.
     */
    private function mismoDominioRegistrable(string $primero, string $segundo): bool
    {
        if ($primero === '' || $segundo === '') {
            return true;
        }

        return $this->dominioRegistrable($primero) === $this->dominioRegistrable($segundo);
    }

    private function dominioRegistrable(string $host): string
    {
        $host = strtolower(trim($host, '.'));
        $etiquetas = explode('.', $host);
        $total = count($etiquetas);

        if ($total <= 2) {
            return $host;
        }

        $dosUltimas = implode('.', array_slice($etiquetas, -2));

        return in_array($dosUltimas, self::SUFIJOS_COMPUESTOS, true)
            ? implode('.', array_slice($etiquetas, -3))
            : $dosUltimas;
    }

    /** La etiqueta que identifica al dueño del dominio: de www.96n.com devuelve "96n". */
    private function nombreRegistrable(string $host): string
    {
        $registrable = $this->dominioRegistrable($host);
        $etiquetas = explode('.', $registrable);

        return $etiquetas[0] ?? $registrable;
    }

    // -------------------------------------------------------------------------
    // Caso real reproducido
    // -------------------------------------------------------------------------

    /**
     * Las cinco respuestas del caso real.
     *
     * El reparto no es caprichoso, es el del ataque observado: la versión limpia para el
     * navegador de escritorio y para Bing —la inyección solo reconoce a Google—, la página de
     * apuestas para Googlebot y para quien llega desde un resultado de búsqueda, y la
     * redirección al dominio del atacante solo en teléfono.
     *
     * @return array<string, array{codigo: int|null, url_final: string, cadena: array<int, array<string, mixed>>, cuerpo: string, tipo_contenido: string, error: string|null, ms: int}>
     */
    private function capturasDelCasoReal(string $url): array
    {
        $limpia = <<<'HTML'
            <!doctype html>
            <html lang="es">
            <head>
                <meta charset="utf-8">
                <title>Cámaras de seguridad en Guatemala | Venta, instalación y mantenimiento</title>
                <meta name="description" content="Venta e instalación de cámaras de seguridad CCTV en la ciudad de Guatemala. Mantenimiento preventivo y correctivo para empresas y residencias.">
                <link rel="canonical" href="https://camaras-ejemplo.gt/">
            </head>
            <body>
                <h1>Cámaras de seguridad para su empresa</h1>
                <h2>Mantenimiento de cámaras de seguridad</h2>
                <p>Instalamos y damos mantenimiento a sistemas de videovigilancia en la ciudad de Guatemala
                y el interior del país. Trabajamos con cámaras domo, bala y PTZ, grabadores DVR y NVR,
                almacenamiento en disco y respaldo en la nube. Atendemos empresas, condominios y comercios.</p>
                <h2>Cobertura y garantía</h2>
                <p>Visita técnica sin costo dentro del perímetro urbano. Garantía de un año en equipo y de
                seis meses en la instalación. Contratos de mantenimiento preventivo trimestral.</p>
                <p>Escríbanos por <a href="https://www.facebook.com/empresa-ejemplo">Facebook</a> o llame a
                nuestras oficinas de lunes a viernes.</p>
            </body>
            </html>
            HTML;

        $inyectada = <<<'HTML'
            <!doctype html>
            <html lang="zh">
            <head>
                <meta charset="utf-8">
                <title>p9bet login | 0016bet 官方网站 - kmj888 娱乐城</title>
                <meta name="description" content="p9bet login, 0016bet y kmj888: casino en línea, tragamonedas y apuestas deportivas con bono de bienvenida.">
                <link rel="canonical" href="https://96n.com/p9bet/">
                <base href="https://96n.com/">
                <meta name="robots" content="index, follow">
            </head>
            <body>
                <h1>p9bet login - 0016bet</h1>
                <h2>kmj888 娱乐城 官方网站</h2>
                <p>Casino en línea con tragamonedas, ruleta en línea y apuestas deportivas las 24 horas.
                Registro en p9bet login, bono de bienvenida para nuevos jugadores, retiros en minutos.
                博彩平台 提供最好的老虎机和体育投注服务。</p>
                <h2>porh300 - agente oficial</h2>
                <p>Enlaces de acceso: <a href="https://96n.com/register">96n</a>,
                <a href="https://kmj888.top/">kmj888</a>,
                <a href="https://porh300.xyz/entrar">porh300</a>,
                <a href="https://0016bet.click/">0016bet</a>.</p>
            </body>
            </html>
            HTML;

        return [
            'navegador_escritorio' => $this->bruto(200, $url, $limpia, 312),
            // Bing recibe la versión limpia: el script del atacante solo reconoce a Google.
            // Esa asimetría es real y es lo que hace útil pedir con dos rastreadores.
            'bingbot' => $this->bruto(200, $url, $limpia, 298),
            'googlebot' => $this->bruto(200, $url, $inyectada, 341),
            'desde_buscador' => $this->bruto(200, $url, $inyectada, 356),
            'movil' => [
                'codigo' => 302,
                'url_final' => 'https://96n.com/p9bet/?s=mobile',
                'cadena' => [
                    ['codigo' => 302, 'desde' => $url, 'hacia' => 'https://96n.com/p9bet/?s=mobile'],
                ],
                'cuerpo' => '<!doctype html><html lang="zh"><head><title>96n | p9bet</title></head><body><h1>p9bet</h1></body></html>',
                'tipo_contenido' => 'text/html; charset=utf-8',
                'error' => null,
                'ms' => 402,
            ],
        ];
    }

    /**
     * @return array{codigo: int|null, url_final: string, cadena: array<int, array<string, mixed>>, cuerpo: string, tipo_contenido: string, error: string|null, ms: int}
     */
    private function bruto(int $codigo, string $url, string $cuerpo, int $ms): array
    {
        return [
            'codigo' => $codigo,
            'url_final' => $url,
            'cadena' => [],
            'cuerpo' => $cuerpo,
            'tipo_contenido' => 'text/html; charset=utf-8',
            'error' => null,
            'ms' => $ms,
        ];
    }
}
