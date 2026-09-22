<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\HallazgoMapaSitio;
use App\Models\IncidenteSeo;
use App\Models\LineaBaseSeo;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use SimpleXMLElement;
use Throwable;

/**
 * Auditoría del mapa del sitio y del archivo de exclusión de rastreadores.
 *
 * POR QUÉ EXISTE ESTE CONTROL, Y POR QUÉ LOS OTROS CUATRO NO BASTAN
 * ---------------------------------------------------------------------------
 * El laboratorio de contenido demuestra la sanitización de lo que escriben los usuarios, y
 * sirve cuando el ataque entra por una reseña. No sirve cuando el atacante YA TIENE el
 * servidor o el gestor de contenidos: entonces no publica una reseña, publica páginas
 * enteras, y además las esconde del menú para que el dueño del sitio no las vea nunca.
 *
 * El caso que originó esta clase es real. Una empresa guatemalteca que vende cámaras de
 * seguridad tenía en su panel de Search Console, junto a "camaras de seguridad guatemala",
 * estas consultas:
 *
 *     p9bet login   45 impresiones   0 clics
 *     0016bet       13 impresiones   0 clics
 *     96n.com       11 impresiones   1 clic
 *     kmj888        10 impresiones   0 clics
 *     porh300        2 impresiones   1 clic
 *
 * Marcas de casas de apuestas asiáticas. Nadie que busca "p9bet login" quiere una empresa
 * de cámaras: Google mostraba ese dominio por contenido que no le pertenecía. La víctima no
 * vio nada raro navegando por su propio sitio, porque las páginas inyectadas no están en el
 * menú. Solo existen para el rastreador, y el rastreador llega a ellas por UNA puerta: el
 * mapa del sitio. Por eso auditarlo detecta la inyección aunque las páginas estén ocultas
 * para quien navega.
 *
 * QUÉ REUTILIZA Y QUÉ APORTA
 * ---------------------------------------------------------------------------
 * El vocabulario general de spam (farmacia, casino en español, préstamos, dominios
 * desechables, caracteres CJK) ya lo puntúa DetectorSpamSeo y aquí se le delega: duplicarlo
 * sería tener dos criterios que con el tiempo se contradicen. Lo que esta clase añade es lo
 * que aquel detector no puede ver, porque está pensado para texto y no para direcciones:
 *
 *   - La marca de apuestas asiática, que no es una palabra de diccionario sino una FORMA:
 *     mezcla de letras y dígitos terminada en una raíz de juego (p9bet, 0016bet, slot88).
 *   - El dominio ajeno declarado como si fuera propio.
 *   - La desviación respecto del resto del mapa: profundidad, forma del segmento y
 *     vocabulario. Una tienda de cámaras habla de cámaras en todas sus direcciones; la
 *     dirección inyectada no comparte una sola palabra con las demás, y eso es medible.
 *
 * Se mantiene el umbral 5 de DetectorSpamSeo y del WAF a propósito: tres criterios distintos
 * en el mismo sistema obligan al analista a traducir de cabeza durante un incidente real.
 */
class AuditorMapaSitio
{
    /** Mismo umbral que DetectorSpamSeo y que el Core Rule Set del WAF. */
    public const UMBRAL_ANOMALA = 5;

    /** Por debajo del umbral pero con señal: se muestra, no se alerta. */
    public const UMBRAL_SOSPECHA = 2;

    public const VEREDICTO_LIMPIA = 'limpia';

    public const VEREDICTO_SOSPECHOSA = 'sospechosa';

    public const VEREDICTO_ANOMALA = 'anomala';

    /** Anómala que además responde 200: la página inyectada existe y está viva. */
    public const VEREDICTO_INYECTADA = 'inyectada';

    /** Anómala que responde 404: basura declarada al buscador, resto de una campaña. */
    public const VEREDICTO_FANTASMA = 'fantasma';

    /**
     * Tope de direcciones que se analizan. Un mapa envenenado puede traer cien mil
     * entradas, y el objetivo aquí es detectar la inyección, no inventariarla: con las
     * primeras cinco mil ya se ve el patrón, y sin tope una auditoría tumba el proceso
     * de PHP que la ejecuta.
     */
    public const MAXIMO_DIRECCIONES = 5000;

    /** Un índice de mapas que se apunta a sí mismo sería un bucle infinito. */
    private const PROFUNDIDAD_MAXIMA_INDICE = 3;

    private const MAXIMO_MAPAS = 25;

    /** Muestra que se comprueba por HTTP: más parecería un escáner y tardaría eternamente. */
    public const MUESTRA_COMPROBACION = 8;

    /**
     * Filas de evidencia que se guardan por corrida.
     *
     * Una campaña puede inyectar miles de direcciones y escribirlas todas convertiría cada
     * auditoría en una descarga masiva sobre la base de datos. Se guardan las peores, que
     * están ordenadas primero: quinientas evidencias sostienen cualquier hallazgo, y el
     * recuento completo sigue estando en el incidente y en la bitácora.
     */
    private const MAXIMO_EVIDENCIAS = 500;

    private const TIMEOUT_DESCARGA = 8;

    private const TIMEOUT_COMPROBACION = 6;

    /**
     * Tope de bytes que se aceptan de un mapa comprimido.
     *
     * gzip amplifica: un archivo de un kilobyte puede descomprimirse en gigabytes, y
     * gzdecode() sin segundo argumento lo intenta hasta agotar la memoria del proceso. El
     * sitio que se audita puede estar en manos del atacante, así que la bomba de
     * descompresión no es un supuesto teórico: es la forma más barata que tiene de apagar
     * el control que lo está mirando.
     */
    private const MAXIMO_BYTES_MAPA = 33554432;

    /**
     * Marca de casa de apuestas asiática.
     *
     * No es vocabulario, es FORMA, y por eso ninguna lista de palabras la caza: "p9bet" no
     * está en ningún diccionario y mañana será "p11bet". Lo estable es el patrón —dígitos
     * pegados a una raíz de juego— y eso sí se puede describir.
     *
     * Las dos alternativas están escritas con cuidado para no morder un código de producto:
     *   A) la raíz va AL FINAL del token y antes hay un dígito .... p9bet, 0016bet, 88slot
     *   B) la raíz va al principio y detrás SOLO hay dígitos ...... bet365, slot88
     * "beta2" no encaja en ninguna: en A no termina en raíz y en B tras "bet" hay una letra.
     * Sin esa precisión, un firmware llamado "beta2" abriría un incidente crítico.
     */
    private const PATRON_MARCA_APUESTAS = '/\b(?:[a-z0-9]{0,10}\d[a-z0-9]{0,4}(?:bet|slot|toto|togel|judi|gacor|casino|poker|sbobet)|(?:bet|slot|toto|togel|judi|gacor|casino|poker|sbobet)\d{2,6})\b/i';

    /**
     * Números de la suerte del mercado asiático de apuestas pegados a una sigla: kmj888.
     * El 8 es prosperidad y el 4 desgracia, y por eso las marcas se llaman 888 y nunca 444.
     * Es una convención cultural, no un capricho, y se repite campaña tras campaña.
     */
    private const PATRON_NUMERO_SUERTE = '/\b[a-z]{2,8}(?:1688|8899|888|168|777|88|99|77|4d)\b/i';

    /**
     * Palabras que acompañan a la marca en la página puente. "login" y "daftar" (registrarse
     * en indonesio) aparecían literalmente en la consulta del caso real: "p9bet login".
     *
     * "gacor" y "judi" se añadieron después de medir: sin ellas, una campaña de "slot gacor"
     * —que es LA forma que toma este ataque en la práctica— se quedaba en tres puntos y no
     * cruzaba el umbral. Ninguna de las dos es palabra española ni término del sector de la
     * videovigilancia: "gacor" es jerga indonesia de apuestas y "judi" significa juego de
     * azar. El riesgo de que aparezcan en el catálogo honesto de una tienda guatemalteca de
     * cámaras es cero, y sin ellas el control ve el 60 % de la campaña y cree que ve todo.
     */
    private const PATRON_PUERTA_ENTRADA = '/\b(login|daftar|masuk|link\s*alternatif|rtp|maxwin|gacor|judi|deposit|depo|pulsa|situs|bandar|agen|terpercaya|gampang|anti\s*rungkad)\b/iu';

    /**
     * Alfabetos que no corresponden a un sitio guatemalteco.
     *
     * Los rangos CJK NO están aquí a propósito: los puntúa DetectorSpamSeo y sumarlos otra
     * vez sería contar dos veces la misma evidencia, que es como un control honesto se
     * convierte en uno que exagera.
     */
    private const PATRON_ALFABETO_AJENO = '/[\x{0400}-\x{04ff}\x{0600}-\x{06ff}\x{0590}-\x{05ff}\x{0e00}-\x{0e7f}\x{0900}-\x{097f}]/u';

    /**
     * Siglas técnicas que mezclan letras y dígitos con toda legitimidad en una tienda de
     * electrónica. Sin esta lista, "win10", "cat6" o "usb3" puntuarían como jerga inventada
     * y el control gritaría en el catálogo honesto, que es la forma más rápida de que nadie
     * vuelva a mirarlo.
     *
     * @var array<int, string>
     */
    private const SIGLAS_TECNICAS = [
        'win', 'cat', 'usb', 'ip', 'ds', 'ipc', 'hd', 'uhd', 'wifi', 'mp', 'gb', 'tb', 'mb',
        'ver', 'rev', 'mod', 'ref', 'art', 'cod', 'sku', 'hik', 'dah', 'tvi', 'ahd', 'cvi',
        'poe', 'lte', 'sim', 'cam', 'pro', 'max', 'plus', 'serie', 'modelo', 'tipo', 'nivel',
        'pagina', 'page', 'lote', 'talla', 'rj', 'utp', 'stp', 'nvr', 'dvr', 'xvr', 'ptz',
        'fps', 'ghz', 'mhz', 'mm', 'led', 'ir', 'sd', 'ssd', 'hdd', 'sata', 'hdmi', 'vga',
    ];

    /**
     * Dirección publicada del proyecto.
     *
     * Está escrita y no deducida de app.url porque en el equipo de desarrollo app.url es
     * http://localhost:8000, y eso no sirve para lo que este control hace:
     *
     *   - Auditar el propio localhost DESDE la petición que pinta el panel se bloquea a sí
     *     mismo cuando el servidor tiene un solo proceso de PHP, que es el mismo motivo por
     *     el que el panel de integridad lanza su vigilancia con --sin-red.
     *   - Lo que hay que auditar es el sitio PUBLICADO. Es el que lee Google, y el único
     *     sobre el que un hallazgo significa algo.
     */
    public const DIRECCION_PUBLICADA = 'https://marketgt.duckdns.org';

    /**
     * Resultado del filtro anti-petición-falsificada por anfitrión, memorizado por corrida.
     *
     * @var array<string, bool>
     */
    private array $resolucionesDeHost = [];

    public function __construct(
        private readonly DetectorSpamSeo $detector,
        private readonly RegistroIncidentesSeo $registro,
        private readonly PoliticaIndexacion $politica,
    ) {}

    /**
     * Qué sitio auditar cuando nadie dice cuál.
     *
     * Si la aplicación está configurada contra un anfitrión local se devuelve el sitio
     * publicado; si ya apunta a un dominio real —el servidor de producción— se respeta lo
     * que diga la configuración, porque allí app.url ya es la respuesta correcta.
     */
    public function direccionPorDefecto(): string
    {
        $configurada = (string) config('app.url');
        $host = strtolower((string) parse_url($configurada, PHP_URL_HOST));

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.test') || str_ends_with($host, '.local')) {
            return self::DIRECCION_PUBLICADA;
        }

        $esDireccionIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $esInterna = $esDireccionIp
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;

        return $esInterna ? self::DIRECCION_PUBLICADA : $configurada;
    }

    // -------------------------------------------------------------------------
    // Entrada principal
    // -------------------------------------------------------------------------

    /**
     * Audita el mapa del sitio y el archivo de exclusión de un sitio.
     *
     * No escribe nada: devolver el análisis y persistirlo son dos pasos distintos para que
     * el panel pueda auditar un sitio de prueba sin ensuciar el histórico, igual que el
     * laboratorio de contenido separa "analizar" de "intentar publicar".
     *
     * @param  array{comprobar_respuestas?: bool, muestra?: int, mapa?: string|null}  $opciones
     * @return array<string, mixed>
     */
    public function auditar(string $urlSitio, array $opciones = []): array
    {
        $inicio = microtime(true);

        $base = $this->normalizarBase($urlSitio);
        $host = (string) parse_url($base, PHP_URL_HOST);

        // Se valida lo que escribió el operador ANTES de tocar la red: si el campo no sirve,
        // decírselo cuesta cero peticiones, y así el mensaje de error habla de su campo y
        // no del robots.txt que no llegó a mirarse.
        $pedido = $this->mapaPedido($opciones['mapa'] ?? null, $base);

        $errores = [];
        $exclusion = $this->auditarExclusion($base, $host);

        // De dónde salen los mapas a descargar, por orden de confianza: el que pidió el
        // operador, los que declara el propio robots.txt (ahí es donde el atacante añade
        // el suyo) y, como último recurso, la ruta convencional.
        $mapas = array_values(array_unique(array_filter(array_merge(
            $pedido,
            $exclusion['sitemaps_declarados'],
            [$base.'/sitemap.xml'],
        ))));

        $recoleccion = $this->recolectar($mapas, $host);
        $errores = array_merge($errores, $recoleccion['errores']);

        $direcciones = $recoleccion['direcciones'];

        // El vocabulario propio se aprende de la LÍNEA BASE cuando la hay, y solo del mapa
        // actual cuando no la hay. La diferencia no es cosmética: si el atacante inyecta
        // ciento veinte páginas de apuestas en un sitio de cuarenta, "gacor" y "maxwin"
        // pasan a ser las palabras MÁS frecuentes del mapa y el control aprende que el spam
        // es lo normal de la casa. Aprendiendo del estado autorizado, cuanto más inyecta el
        // atacante, más se delata.
        $contexto = $this->perfilarCorpus($direcciones, $host, $this->urlsAutorizadas($host));

        $analizadas = [];

        foreach ($direcciones as $entrada) {
            $analizadas[] = $this->analizarDireccion($entrada, $contexto);
        }

        // Se ordena por puntuación antes de comprobar respuestas: si solo se pueden pedir
        // ocho páginas, que sean las ocho peores y no las ocho primeras del archivo.
        usort($analizadas, static fn (array $a, array $b): int => $b['puntuacion'] <=> $a['puntuacion']);

        if (($opciones['comprobar_respuestas'] ?? true) === true) {
            $analizadas = $this->comprobarRespuestas(
                $analizadas,
                max(0, (int) ($opciones['muestra'] ?? self::MUESTRA_COMPROBACION)),
            );
        }

        $lineaBase = $this->compararLineaBase($host, $base, $analizadas, $exclusion);

        return [
            'ejecucion' => (string) Str::ulid(),
            'momento' => Carbon::now(),
            'sitio' => $host,
            'base' => $base,
            'mapas' => $recoleccion['mapas'],
            'exclusion' => $exclusion,
            'direcciones' => $analizadas,
            'resumen' => $this->resumir($analizadas, $exclusion, $contexto),
            'linea_base' => $lineaBase,
            'errores' => $errores,
            'duracion_ms' => (int) round((microtime(true) - $inicio) * 1000),
        ];
    }

    // -------------------------------------------------------------------------
    // 2. Análisis de una dirección declarada
    // -------------------------------------------------------------------------

    /**
     * @param  array{url: string, lastmod: string|null, origen: string}  $entrada
     * @param  array<string, mixed>  $contexto
     * @return array<string, mixed>
     */
    private function analizarDireccion(array $entrada, array $contexto): array
    {
        $url = $entrada['url'];
        $partes = parse_url($url);
        $hostDeclarado = strtolower((string) ($partes['host'] ?? ''));
        $ruta = (string) ($partes['path'] ?? '/');
        $consulta = (string) ($partes['query'] ?? '');

        $segmentos = array_values(array_filter(explode('/', trim($ruta, '/')), static fn (string $s): bool => $s !== ''));
        $profundidad = count($segmentos);

        $motivos = [];
        $puntuacion = 0;
        $esAjeno = $hostDeclarado !== '' && ! $this->mismoSitio($hostDeclarado, $contexto['host']);

        // --- Dominio ajeno ---------------------------------------------------
        // Un mapa del sitio SOLO puede declarar direcciones del propio sitio: así lo exige
        // el protocolo. Declarar ajenas no admite explicación inocente, y por eso es lo
        // único aquí que por sí solo basta para el veredicto.
        if ($esAjeno) {
            $puntuacion += 8;
            $motivos[] = $this->motivo(
                'dominio_ajeno',
                'El mapa declara una dirección alojada en otro dominio',
                8,
                $hostDeclarado,
            );
        }

        // --- Vocabulario de los sectores de abuso ----------------------------
        // Se analiza la RUTA, no la dirección entera, salvo cuando el anfitrión es ajeno.
        //
        // La diferencia importa más de lo que parece: el anfitrión es idéntico en las miles
        // de direcciones de un mapa, así que cualquier señal que viva en él puntuaría en
        // TODAS. Una tienda honesta alojada en un dominio .top vería su catálogo completo
        // marcado como dominio desechable, y un control que marca el cien por cien no
        // informa de nada. Cuando el anfitrión SÍ es ajeno se pasa entero, porque
        // entonces es justo la evidencia que hay que puntuar.
        $rutaYConsulta = $ruta.($consulta === '' ? '' : '?'.$consulta);
        $legible = $this->textoLegible($esAjeno ? $url : $rutaYConsulta);
        $general = $this->detector->analizar(($esAjeno ? $url : $rutaYConsulta)."\n".$legible);

        foreach ($general['motivos'] as $delDetector) {
            $puntuacion += (int) $delDetector['puntos'];
            $motivos[] = [
                'regla' => 'spam:'.$delDetector['regla'],
                'descripcion' => $delDetector['descripcion'],
                'puntos' => (int) $delDetector['puntos'],
                'evidencia' => (string) $delDetector['evidencia'],
            ];
        }

        foreach ($this->senalesDeApuestas($legible) as $senal) {
            $puntuacion += $senal['puntos'];
            $motivos[] = $senal;
        }

        // --- Alfabeto que no corresponde al idioma del sitio ------------------
        // La dirección se decodifica antes: el atacante no escribe los kanji en claro, los
        // escribe en %E3%83%... y sin decodificar la regla no vería nada.
        if (preg_match(self::PATRON_ALFABETO_AJENO, $legible, $ajeno) === 1) {
            $puntuacion += 5;
            $motivos[] = $this->motivo(
                'alfabeto_ajeno',
                'Caracteres de un alfabeto que no corresponde a un sitio en español',
                5,
                (string) $ajeno[0],
            );
        }

        // Punycode: la misma inyección de otro alfabeto, pero ya codificada por el
        // atacante para que pase por texto ASCII inocente.
        if (preg_match('/(^|[.\/-])xn--[a-z0-9-]+/i', $url, $puny) === 1) {
            $puntuacion += 4;
            $motivos[] = $this->motivo(
                'punycode',
                'Etiqueta punycode: alfabeto no latino codificado para pasar por ASCII',
                4,
                trim((string) $puny[0], './-'),
            );
        }

        // --- Un dominio escrito DENTRO de la ruta -----------------------------
        // "/96n.com/" es la firma de la página puente: el atacante nombra la carpeta con la
        // marca que quiere posicionar. Ningún gestor de contenidos genera eso solo.
        if ($segmentos !== [] && preg_match('/\b[a-z0-9][a-z0-9-]{1,30}\.(com|net|org|xyz|top|vip|club|live|bet|icu|cc|io|co)\b/i', implode(' ', $segmentos), $dominio) === 1) {
            $puntuacion += 5;
            $motivos[] = $this->motivo(
                'dominio_en_la_ruta',
                'Un nombre de dominio escrito como segmento de la ruta: página puente',
                5,
                (string) $dominio[0],
            );
        }

        // --- Forma anómala respecto del resto ---------------------------------
        //
        // Las dos señales de esta familia se suman entre sí pero NO por encima de tres
        // puntos, y ese tope no es prudencia decorativa: está medido. Profundidad y
        // segmento kilométrico miden lo mismo por dos caminos —que la dirección es larga—,
        // así que sumaban 3+2=5 y alcanzaban solas el umbral de alarma. Con eso,
        // "/blog/2026/09/22/guia-completa-de-instalacion-de-camaras-de-seguridad/parte-2",
        // que es una entrada de blog honesta de la propia tienda, salía marcada como
        // ANÓMALA y abría un incidente crítico. Contar dos veces la misma evidencia es
        // exactamente cómo un control se convierte en ruido, y un control ruidoso no se
        // mira dos veces. Con el tope, la forma por sí sola deja la dirección en
        // "sospechosa" —se ve en pantalla, no despierta a nadie— y hace falta una señal de
        // otra familia (vocabulario, marca, fecha) para cruzar el umbral. Las páginas
        // realmente inyectadas del caso real cruzan igual, porque ninguna dependía de la
        // forma: el doorway /wp-content/uploads/2019/slot-gacor-maxwin/… suma 9 por
        // puerta_de_entrada, vocabulario ajeno y fecha futura.
        $mediana = (int) $contexto['profundidad_mediana'];
        $forma = 0;

        if ($profundidad > $mediana + 3 && $profundidad >= 4) {
            $forma += 3;
            $motivos[] = $this->motivo(
                'profundidad_anomala',
                'Profundidad muy superior a la del resto del mapa (mediana '.$mediana.')',
                3,
                $profundidad.' niveles',
            );
        }

        foreach ($segmentos as $segmento) {
            if (mb_strlen($segmento) > 80 || substr_count($segmento, '-') > 8) {
                $forma += 2;
                $motivos[] = $this->motivo(
                    'segmento_kilometrico',
                    'Segmento cargado de palabras clave, típico de la página generada en masa',
                    2,
                    mb_substr($segmento, 0, 60).'…',
                );

                break;
            }
        }

        $puntuacion += min(3, $forma);

        // --- Fuera del vocabulario del propio sitio ---------------------------
        // La medida más valiosa y la que atrapa lo que ninguna lista negra atrapa: una
        // tienda de cámaras habla de cámaras en TODAS sus direcciones. La inyectada no
        // comparte una sola palabra con las demás. "porh300" no está en ninguna lista de
        // spam del mundo, y aun así es evidentemente ajeno a este catálogo.
        if ($contexto['vocabulario'] !== [] && $this->fueraDelVocabulario($legible, $contexto['vocabulario'])) {
            $puntuacion += 3;
            $motivos[] = $this->motivo(
                'fuera_del_vocabulario',
                'No comparte ninguna palabra con el vocabulario propio del sitio',
                3,
                mb_substr(trim($legible), 0, 60),
            );
        }

        // --- Contradicción con el archivo de exclusión ------------------------
        if ($this->rutaExcluida($ruta, $contexto['rutas_excluidas'])) {
            $puntuacion += 2;
            $motivos[] = $this->motivo(
                'declarada_y_excluida',
                'El mapa pide que se indexe una ruta que la política marca como no indexable',
                2,
                $ruta,
            );
        }

        if ($consulta !== '' && $this->pareceBusqueda($consulta)) {
            $puntuacion += 3;
            $motivos[] = $this->motivo(
                'resultados_de_busqueda',
                'Declara una página de resultados: la vía clásica del envenenamiento por búsqueda interna',
                3,
                mb_substr($consulta, 0, 80),
            );
        }

        // --- Fecha de modificación --------------------------------------------
        foreach ($this->senalesDeFecha($entrada['lastmod'], $contexto) as $senal) {
            $puntuacion += $senal['puntos'];
            $motivos[] = $senal;
        }

        return [
            'url' => $url,
            'origen' => $entrada['origen'],
            'profundidad' => $profundidad,
            'lastmod' => $entrada['lastmod'],
            'puntuacion' => $puntuacion,
            'veredicto' => $this->veredicto($puntuacion),
            'motivos' => $motivos,
            'codigo_http' => null,
            'titulo_remoto' => null,
            'destino_final' => null,
            'comprobada_en' => null,
        ];
    }

    /**
     * Señales de apuestas que DetectorSpamSeo no puede tener, porque no son palabras.
     *
     * @return array<int, array{regla: string, descripcion: string, puntos: int, evidencia: string}>
     */
    private function senalesDeApuestas(string $texto): array
    {
        $senales = [];

        if (preg_match(self::PATRON_MARCA_APUESTAS, $texto, $marca) === 1) {
            $senales[] = $this->motivo(
                'marca_apuestas',
                'Marca de casa de apuestas: dígitos pegados a una raíz de juego',
                5,
                (string) $marca[0],
            );
        }

        if (preg_match(self::PATRON_NUMERO_SUERTE, $texto, $numero) === 1) {
            $senales[] = $this->motivo(
                'numero_de_la_suerte',
                'Sigla con el número de la suerte del mercado asiático de apuestas (888, 168, 77)',
                3,
                (string) $numero[0],
            );
        }

        // Se cuentan las palabras DISTINTAS, no una sola vez la regla.
        //
        // "login" suelto no prueba nada: cualquier sitio tiene su /login, y de hecho la
        // política de indexación ya lo marca como no indexable, así que sumarle más de dos
        // puntos convertía "/login" declarado en el mapa en una anomalía con incidente.
        // Pero "daftar situs judi bola terpercaya" son cinco palabras de un mismo idioma
        // que no es el del sitio: eso ya no es una coincidencia, es una frase. Dos puntos
        // por la primera y dos por cada palabra distinta más, con tope de seis para que la
        // familia no se coma ella sola el veredicto de una dirección larga.
        if (preg_match_all(self::PATRON_PUERTA_ENTRADA, $texto, $puertas) >= 1) {
            $distintas = array_values(array_unique(array_map(
                static fn (string $p): string => mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($p))),
                $puertas[0],
            )));

            $senales[] = $this->motivo(
                'puerta_de_entrada',
                'Vocabulario de página puente de apuestas (login, daftar, situs, rtp, gacor)',
                min(6, 2 * count($distintas)),
                implode(', ', array_slice($distintas, 0, 6)),
            );
        }

        foreach ($this->tokens($texto) as $token) {
            if ($this->pareceJergaInventada($token)) {
                $senales[] = $this->motivo(
                    'jerga_inventada',
                    'Sigla sin significado con dígitos al final: nombre de marca inventada',
                    2,
                    $token,
                );

                break;
            }
        }

        return $senales;
    }

    /**
     * "porh300" sí, "win10" no. La diferencia no es el patrón, es si la sigla significa
     * algo en el catálogo que se está auditando.
     */
    private function pareceJergaInventada(string $token): bool
    {
        if (preg_match('/^([a-z]{3,8})(\d{2,6})$/i', $token, $partes) !== 1) {
            return false;
        }

        return ! in_array(strtolower($partes[1]), self::SIGLAS_TECNICAS, true);
    }

    /**
     * @param  array<int, string>  $vocabulario
     */
    private function fueraDelVocabulario(string $legible, array $vocabulario): bool
    {
        $propias = 0;
        $evaluables = 0;
        $inventadas = 0;

        foreach ($this->tokens($legible) as $token) {
            if (mb_strlen($token) < 4 || is_numeric($token)) {
                continue;
            }

            $evaluables++;

            if ($this->pareceJergaInventada($token)) {
                $inventadas++;
            }

            if ($this->coincideConVocabulario($token, $vocabulario)) {
                $propias++;
            }
        }

        if ($propias > 0) {
            return false;
        }

        // Una dirección de un solo token (/contacto, /es, /2026) no prueba nada por sí
        // misma: exigir evidencia mínima es lo que separa una señal de una casualidad.
        //
        // LA EXCEPCIÓN ES "porh300", Y ES EL MOTIVO DE QUE ESTÉ ESCRITA
        // -----------------------------------------------------------------------
        // De las cinco consultas del caso real, cuatro cruzaban el umbral y "porh300" se
        // quedaba en 2, porque es un solo token y esta regla se apagaba por falta de
        // evidencia. Detectar cuatro de cinco no es detectar el caso: es dejar viva la
        // campaña por la puerta que menos ruido hace.
        //
        // Un solo token basta cuando ADEMÁS tiene forma de marca inventada —letras y
        // dígitos, y la sigla no está en la lista de siglas técnicas del sector—. No es
        // contar dos veces la misma prueba: pareceJergaInventada() habla de la FORMA del
        // token y esta regla habla del CONTEXTO, de que el sitio no usa esa palabra en
        // ninguna otra dirección. Y el borde está justo donde tiene que estar: si la
        // tienda vendiera de verdad un producto llamado "porh300", la palabra aparecería
        // en más de una dirección, entraría en el vocabulario propio y esta regla no
        // dispararía. "/contacto" sigue sin puntuar porque no mezcla dígitos, y "/win10"
        // y "/cat6" tampoco porque "win" y "cat" son siglas técnicas conocidas.
        return $evaluables >= 2 || $inventadas === 1;
    }

    /**
     * Comparación por raíz y no por igualdad exacta.
     *
     * "camara" y "camaras" son la misma palabra, y "instalacion" e "instalaciones"
     * también. Comparando cadenas completas, media tienda honesta salía marcada como
     * ajena a su propio catálogo por un plural. Cinco caracteres de raíz común es
     * suficiente en español para el singular, el plural y el diminutivo, y corto de más
     * para juntar dos palabras que no tienen nada que ver.
     *
     * @param  array<int, string>  $vocabulario
     */
    private function coincideConVocabulario(string $token, array $vocabulario): bool
    {
        foreach ($vocabulario as $palabra) {
            if ($token === $palabra) {
                return true;
            }

            if (mb_strlen($token) >= 5 && mb_strlen($palabra) >= 5
                && (str_starts_with($token, mb_substr($palabra, 0, 5)) || str_starts_with($palabra, mb_substr($token, 0, 5)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $contexto
     * @return array<int, array{regla: string, descripcion: string, puntos: int, evidencia: string}>
     */
    private function senalesDeFecha(?string $lastmod, array $contexto): array
    {
        if ($lastmod === null || trim($lastmod) === '') {
            return [];
        }

        try {
            $fecha = Carbon::parse($lastmod);
        } catch (Throwable) {
            return [$this->motivo('fecha_ilegible', 'La fecha de modificación no es una fecha válida', 2, mb_substr($lastmod, 0, 40))];
        }

        $senales = [];

        // Una fecha en el futuro no la escribe un gestor de contenidos: la escribe quien
        // quiere que su página parezca siempre la más reciente del sitio.
        if ($fecha->greaterThan(Carbon::now()->addDay())) {
            $senales[] = $this->motivo(
                'fecha_futura',
                'Fecha de modificación en el futuro: se declara más reciente que el resto para ganar prioridad de rastreo',
                4,
                $fecha->toDateString(),
            );
        }

        $referencia = $contexto['fecha_mediana'];

        if ($referencia instanceof Carbon && abs($fecha->diffInDays($referencia)) > 1825) {
            $senales[] = $this->motivo(
                'fecha_aislada',
                'Fecha muy alejada del resto del mapa (más de cinco años de diferencia con la mediana)',
                2,
                $fecha->toDateString(),
            );
        }

        return $senales;
    }

    // -------------------------------------------------------------------------
    // 3. Comprobación de respuesta
    // -------------------------------------------------------------------------

    /**
     * Pide una muestra de las direcciones sospechosas y mira si existen.
     *
     * Distingue dos cosas que parecen la misma y no lo son:
     *   - 404 -> basura. El mapa declara páginas que ya no están: restos de una campaña
     *     limpiada a medias, o un mapa generado por el atacante que nunca tuvo respaldo.
     *   - 200 -> la página inyectada está VIVA y el buscador la puede indexar hoy.
     *
     * @param  array<int, array<string, mixed>>  $direcciones
     * @return array<int, array<string, mixed>>
     */
    private function comprobarRespuestas(array $direcciones, int $muestra): array
    {
        if ($muestra <= 0) {
            return $direcciones;
        }

        $comprobadas = 0;

        foreach ($direcciones as $indice => $direccion) {
            if ($comprobadas >= $muestra) {
                break;
            }

            if ((int) $direccion['puntuacion'] < self::UMBRAL_SOSPECHA) {
                // Están ordenadas de peor a mejor: la primera limpia marca el final de lo
                // que merece una petición.
                break;
            }

            // La <loc> la escribe el sitio auditado. Pedirla a ciegas convertía el paso 3
            // en un escáner de la red interna a las órdenes del atacante: bastaba declarar
            // http://10.0.0.5:8080/admin/reiniciar en el mapa para que el servidor lo
            // pidiera. Se cuenta contra la muestra a propósito: así el recorrido sigue
            // acotado aunque el mapa traiga cinco mil direcciones internas, y además
            // declarar una dirección interna en el mapa público de un sitio público es en
            // sí mismo un hallazgo que merece quedar escrito.
            if (! $this->puedePedirse((string) $direccion['url'])) {
                $comprobadas++;
                $direcciones[$indice]['puntuacion'] = (int) $direccion['puntuacion'] + 3;
                $direcciones[$indice]['motivos'][] = $this->motivo(
                    'destino_interno_declarado',
                    'El mapa declara una dirección interna o de un esquema que no es http: no se pide y queda como hallazgo',
                    3,
                    mb_substr((string) $direccion['url'], 0, 160),
                );

                if ((int) $direcciones[$indice]['puntuacion'] >= self::UMBRAL_ANOMALA) {
                    $direcciones[$indice]['veredicto'] = self::VEREDICTO_ANOMALA;
                }

                continue;
            }

            $respuesta = $this->pedir((string) $direccion['url'], self::TIMEOUT_COMPROBACION);
            $comprobadas++;

            $direcciones[$indice]['comprobada_en'] = Carbon::now();

            if ($respuesta === null) {
                $direcciones[$indice]['codigo_http'] = 0;

                continue;
            }

            $codigo = $respuesta->status();
            $direcciones[$indice]['codigo_http'] = $codigo;

            $destino = $this->destinoFinal($respuesta);
            $direcciones[$indice]['destino_final'] = $destino;

            if ($codigo === 404 || $codigo === 410) {
                if ((int) $direccion['puntuacion'] >= self::UMBRAL_ANOMALA) {
                    $direcciones[$indice]['veredicto'] = self::VEREDICTO_FANTASMA;
                }

                $direcciones[$indice]['motivos'][] = $this->motivo(
                    'declarada_inexistente',
                    'Declarada en el mapa pero el servidor dice que no existe: el mapa está sucio',
                    1,
                    'HTTP '.$codigo,
                );
                $direcciones[$indice]['puntuacion'] = (int) $direccion['puntuacion'] + 1;

                continue;
            }

            if ($codigo >= 200 && $codigo < 300) {
                $titulo = $this->tituloDe($respuesta->body());
                $direcciones[$indice]['titulo_remoto'] = $titulo;

                $delTitulo = $titulo === null ? [] : $this->senalesDeApuestas($titulo);

                foreach ($delTitulo as $senal) {
                    $senal['regla'] = 'titulo:'.$senal['regla'];
                    $senal['descripcion'] = 'En el título de la página servida: '.$senal['descripcion'];
                    $direcciones[$indice]['motivos'][] = $senal;
                    $direcciones[$indice]['puntuacion'] = (int) $direcciones[$indice]['puntuacion'] + $senal['puntos'];
                }

                if ((int) $direcciones[$indice]['puntuacion'] >= self::UMBRAL_ANOMALA) {
                    // Responde 200 y puntúa por encima del umbral: no es una sospecha, es
                    // una página que existe ahora mismo bajo este dominio y que Google
                    // puede indexar hoy.
                    $direcciones[$indice]['veredicto'] = self::VEREDICTO_INYECTADA;
                }

                continue;
            }

            if ($codigo >= 300 && $codigo < 400 && $destino !== null) {
                $hostDestino = strtolower((string) parse_url($destino, PHP_URL_HOST));
                $hostOrigen = strtolower((string) parse_url((string) $direccion['url'], PHP_URL_HOST));

                if ($hostDestino !== '' && ! $this->mismoSitio($hostDestino, $hostOrigen)) {
                    $direcciones[$indice]['veredicto'] = self::VEREDICTO_INYECTADA;
                    $direcciones[$indice]['puntuacion'] = (int) $direccion['puntuacion'] + 6;
                    $direcciones[$indice]['motivos'][] = $this->motivo(
                        'redireccion_a_otro_dominio',
                        'La dirección declarada redirige fuera del sitio: se usa la reputación del dominio como trampolín',
                        6,
                        $hostDestino,
                    );
                }
            }
        }

        return $direcciones;
    }

    // -------------------------------------------------------------------------
    // 4. Archivo de exclusión de rastreadores
    // -------------------------------------------------------------------------

    /**
     * @return array{url: string, disponible: bool, bytes: int, huella: string|null, sitemaps_declarados: array<int, string>, hallazgos: array<int, array<string, mixed>>, contenido: string}
     */
    private function auditarExclusion(string $base, string $host): array
    {
        $url = $base.'/robots.txt';
        $respuesta = $this->pedir($url, self::TIMEOUT_DESCARGA);

        if ($respuesta === null || ! $respuesta->successful()) {
            return [
                'url' => $url,
                'disponible' => false,
                'bytes' => 0,
                'huella' => null,
                'sitemaps_declarados' => [],
                'hallazgos' => [[
                    'regla' => 'sin_archivo_de_exclusion',
                    'descripcion' => 'No se pudo leer robots.txt: sin él no hay forma de saber qué se pidió excluir',
                    'puntos' => 2,
                    'evidencia' => $respuesta === null ? 'sin respuesta' : 'HTTP '.$respuesta->status(),
                ]],
                'contenido' => '',
            ];
        }

        $contenido = $respuesta->body();
        $hallazgos = [];
        $sitemaps = [];

        // Anfitriones que pueden aparecer en una directiva Sitemap: el propio y el de la
        // aplicación. Se compara el anfitrión YA ANALIZADO y no un sufijo de cadena, porque
        // con un sufijo "marketgt.gt.sitio-del-atacante.tld" pasaría por propio.
        foreach ($this->directivas($contenido, 'sitemap') as $declarado) {
            $hostDeclarado = strtolower((string) parse_url($declarado, PHP_URL_HOST));

            if ($hostDeclarado === '' || ! $this->mismoSitio($hostDeclarado, $host)) {
                // Dos hallazgos distintos con el mismo síntoma, porque no significan lo mismo:
                //
                //   - "marketgt.gt" declarado en el sitio servido desde "marketgt.duckdns.org"
                //     es un robots.txt copiado de producción a otro entorno. Importa —Google
                //     está siendo enviado a un mapa que aquí no existe—, pero no es un ataque.
                //   - "mapa-del-atacante.top" no tiene ninguna explicación inocente.
                //
                // Mezclarlos daría una alarma roja permanente en la demostración del sábado, y
                // una alarma que siempre está encendida deja de mirarse a la tercera semana.
                $deOtroEntorno = $this->comparteEtiquetaPrincipal($hostDeclarado, $host);

                $hallazgos[] = [
                    'regla' => $deOtroEntorno ? 'mapa_de_otro_entorno' : 'mapa_ajeno_declarado',
                    'descripcion' => $deOtroEntorno
                        ? 'robots.txt declara el mapa de otro entorno del mismo proyecto: el buscador está siendo enviado a una dirección que este servidor no sirve'
                        : 'robots.txt declara un mapa del sitio alojado en otro dominio: el atacante le entrega su lista a Google usando este dominio como aval',
                    'puntos' => $deOtroEntorno ? 3 : 8,
                    'evidencia' => mb_substr($declarado, 0, 200),
                ];

                continue;
            }

            $sitemaps[] = $declarado;
        }

        // Directivas Allow que abren rutas que la política del proyecto declara no
        // indexables. Es el movimiento silencioso del atacante: no borra nada, añade una
        // línea que reabre /seo o /settings al rastreador.
        foreach ($this->directivas($contenido, 'allow') as $permitida) {
            if ($permitida === '' || $permitida === '/') {
                continue;
            }

            if ($this->rutaExcluida($permitida, $this->politica->rutasNoIndexables())) {
                $hallazgos[] = [
                    'regla' => 'permiso_sobre_ruta_excluida',
                    'descripcion' => 'Se permite rastrear una ruta que la política de indexación marca como excluida',
                    'puntos' => 5,
                    'evidencia' => 'Allow: '.mb_substr($permitida, 0, 120),
                ];
            }

            $delTexto = $this->senalesDeApuestas($this->textoLegible($permitida));

            foreach ($delTexto as $senal) {
                $hallazgos[] = [
                    'regla' => 'permiso_sospechoso',
                    'descripcion' => 'Ruta permitida con '.mb_strtolower($senal['descripcion']),
                    'puntos' => $senal['puntos'],
                    'evidencia' => 'Allow: '.mb_substr($permitida, 0, 120),
                ];
            }
        }

        foreach ($this->buscadoresBloqueados($contenido) as $agente) {
            $hallazgos[] = [
                'regla' => 'bloqueo_total_de_buscador',
                'descripcion' => 'Un "Disallow: /" para un buscador principal desindexa el sitio entero en días y nadie se entera hasta que caen las ventas',
                'puntos' => 8,
                'evidencia' => 'User-agent: '.$agente,
            ];
        }

        if (preg_match('/^\s*Noindex\s*:/mi', $contenido, $noindex) === 1) {
            $hallazgos[] = [
                'regla' => 'directiva_noindex',
                'descripcion' => 'Directiva Noindex en robots.txt: no es estándar y varios rastreadores la obedecen igual',
                'puntos' => 4,
                'evidencia' => trim((string) $noindex[0]),
            ];
        }

        return [
            'url' => $url,
            'disponible' => true,
            'bytes' => strlen($contenido),
            'huella' => hash('sha256', $this->normalizarExclusion($contenido)),
            'sitemaps_declarados' => array_values(array_unique($sitemaps)),
            'hallazgos' => $hallazgos,
            'contenido' => $contenido,
        ];
    }

    /**
     * Buscadores a los que el archivo prohíbe el sitio completo.
     *
     * Se analiza por GRUPOS y no con una expresión regular suelta porque "Disallow: /" es
     * legítimo y deseable contra un raspador de herramientas de posicionamiento, y
     * catastrófico contra Googlebot. Una regla que no distinga obliga a elegir entre un
     * falso positivo permanente y no detectar la desindexación.
     *
     * @return array<int, string>
     */
    private function buscadoresBloqueados(string $contenido): array
    {
        $criticos = ['*', 'googlebot', 'bingbot', 'applebot', 'duckduckbot', 'yandex'];

        $agentesDelGrupo = [];
        $esperandoAgentes = false;
        $bloqueados = [];

        foreach (preg_split('/\R/', $contenido) ?: [] as $linea) {
            $linea = trim((string) preg_replace('/#.*$/', '', (string) $linea));

            if ($linea === '') {
                continue;
            }

            [$directiva, $valor] = array_pad(explode(':', $linea, 2), 2, '');
            $directiva = strtolower(trim($directiva));
            $valor = trim($valor);

            if ($directiva === 'user-agent') {
                if (! $esperandoAgentes) {
                    $agentesDelGrupo = [];
                    $esperandoAgentes = true;
                }

                $agentesDelGrupo[] = strtolower($valor);

                continue;
            }

            $esperandoAgentes = false;

            if ($directiva === 'disallow' && $valor === '/') {
                foreach ($agentesDelGrupo as $agente) {
                    if (in_array($agente, $criticos, true)) {
                        $bloqueados[] = $agente;
                    }
                }
            }
        }

        return array_values(array_unique($bloqueados));
    }

    /**
     * @return array<int, string>
     */
    private function directivas(string $contenido, string $nombre): array
    {
        preg_match_all('/^\s*'.preg_quote($nombre, '/').'\s*:\s*(\S+)/mi', $contenido, $coincidencias);

        return array_values(array_filter($coincidencias[1] ?? []));
    }

    /**
     * Los comentarios y los espacios no son el control: si la huella cambiara porque
     * alguien alineó una línea, la alerta se convertiría en ruido y en dos semanas nadie
     * la miraría.
     */
    private function normalizarExclusion(string $contenido): string
    {
        $lineas = [];

        foreach (preg_split('/\R/', $contenido) ?: [] as $linea) {
            $linea = trim((string) preg_replace('/#.*$/', '', (string) $linea));

            if ($linea !== '') {
                $lineas[] = strtolower((string) preg_replace('/\s+/', ' ', $linea));
            }
        }

        return implode("\n", $lineas);
    }

    // -------------------------------------------------------------------------
    // 5. Línea base
    // -------------------------------------------------------------------------

    /**
     * Compara contra el estado autorizado guardado en lineas_base_seo.
     *
     * Lo valioso no es el análisis puntual, que un atacante paciente puede esperar a que
     * pase: es detectar el CAMBIO. Un mapa que gana ciento veinte direcciones de un día
     * para otro en una tienda que publica dos productos por semana es la inyección, aunque
     * ninguna de las ciento veinte lleve una palabra prohibida.
     *
     * Reutiliza el modelo LineaBaseSeo con una clave propia ("mapa:host") para no pisar las
     * de seo:vigilar, que sella el sitemap LOCAL generado en memoria. Son dos cosas
     * distintas: aquel vigila lo que la aplicación produce, este audita lo que el sitio
     * publicado declara de verdad, que es lo que lee Google.
     *
     * @param  array<int, array<string, mixed>>  $direcciones
     * @param  array<string, mixed>  $exclusion
     * @return array<string, mixed>
     */
    private function compararLineaBase(string $host, string $base, array $direcciones, array $exclusion): array
    {
        $urls = array_map(static fn (array $d): string => (string) $d['url'], $direcciones);
        sort($urls);

        $huella = hash('sha256', implode("\n", $urls));

        $comparacion = [
            'artefacto_mapa' => $this->clave('mapa', $host),
            'artefacto_exclusion' => $this->clave('exclusion', $host),
            'existe' => false,
            'cambio' => false,
            'huella_actual' => $huella,
            'huella_anterior' => null,
            'sellada_en' => null,
            'cantidad_actual' => count($urls),
            'cantidad_anterior' => null,
            'nuevas' => [],
            'desaparecidas' => [],
            'crecimiento_subito' => false,
            'exclusion_cambiada' => false,
            'exclusion_sellada_en' => null,
        ];

        if (! $this->tablaDisponible('lineas_base_seo')) {
            return $comparacion;
        }

        $linea = LineaBaseSeo::query()->where('artefacto', $comparacion['artefacto_mapa'])->first();

        if ($linea instanceof LineaBaseSeo) {
            $anteriores = array_values(array_filter(
                (array) (($linea->resumen ?? [])['urls'] ?? []),
                static fn ($valor): bool => is_string($valor),
            ));

            $comparacion['existe'] = true;
            $comparacion['huella_anterior'] = $linea->huella;
            $comparacion['sellada_en'] = $linea->sellada_en;
            $comparacion['cantidad_anterior'] = (int) (($linea->resumen ?? [])['cantidad'] ?? count($anteriores));
            $comparacion['cambio'] = ! hash_equals($linea->huella, $huella);

            $nuevas = array_values(array_diff($urls, $anteriores));
            $comparacion['nuevas'] = array_slice($nuevas, 0, 100);
            $comparacion['desaparecidas'] = array_slice(array_values(array_diff($anteriores, $urls)), 0, 100);

            // El umbral es relativo y absoluto a la vez: veinticinco direcciones nuevas son
            // muchas en un catálogo de cien y ninguna en uno de cincuenta mil.
            $anterior = max(1, (int) $comparacion['cantidad_anterior']);
            $comparacion['crecimiento_subito'] = count($nuevas) >= 25
                || count($nuevas) / $anterior >= 0.25;
        }

        $lineaExclusion = LineaBaseSeo::query()->where('artefacto', $comparacion['artefacto_exclusion'])->first();

        if ($lineaExclusion instanceof LineaBaseSeo && is_string($exclusion['huella'])) {
            $comparacion['exclusion_cambiada'] = ! hash_equals($lineaExclusion->huella, $exclusion['huella']);
            $comparacion['exclusion_sellada_en'] = $lineaExclusion->sellada_en;
        }

        return $comparacion;
    }

    /**
     * Declara el estado ACTUAL como autorizado.
     *
     * Se deja explícito y separado de auditar() porque sellar un mapa que ya está
     * envenenado legitima el envenenamiento: a partir de ahí las direcciones inyectadas
     * dejan de ser "nuevas" y el control de cambio queda ciego para siempre.
     *
     * @param  array<string, mixed>  $auditoria
     */
    public function sellar(array $auditoria, ?string $nota = null, int|string|null $usuarioId = null): void
    {
        if (! $this->tablaDisponible('lineas_base_seo')) {
            return;
        }

        $host = (string) $auditoria['sitio'];
        $urls = array_map(static fn (array $d): string => (string) $d['url'], $auditoria['direcciones']);
        sort($urls);

        LineaBaseSeo::query()->updateOrCreate(
            ['artefacto' => $this->clave('mapa', $host)],
            [
                'url' => (string) $auditoria['base'].'/sitemap.xml',
                'huella' => hash('sha256', implode("\n", $urls)),
                'resumen' => [
                    'cantidad' => count($urls),
                    // Se guarda la lista completa hasta el tope, no una muestra: sin ella
                    // la comparación solo podría decir "cambió", y "cambió" no se investiga.
                    'urls' => array_slice($urls, 0, self::MAXIMO_DIRECCIONES),
                    'anomalas' => (int) ($auditoria['resumen']['anomalas'] ?? 0),
                ],
                'sellada_por' => $usuarioId,
                'sellada_en' => Carbon::now(),
                'notas' => $nota ?? 'Sellado de la auditoría del mapa del sitio',
            ],
        );

        if (is_string($auditoria['exclusion']['huella'] ?? null)) {
            LineaBaseSeo::query()->updateOrCreate(
                ['artefacto' => $this->clave('exclusion', $host)],
                [
                    'url' => (string) $auditoria['exclusion']['url'],
                    'huella' => (string) $auditoria['exclusion']['huella'],
                    'resumen' => [
                        'bytes' => (int) $auditoria['exclusion']['bytes'],
                        'sitemaps' => $auditoria['exclusion']['sitemaps_declarados'],
                    ],
                    'sellada_por' => $usuarioId,
                    'sellada_en' => Carbon::now(),
                    'notas' => $nota ?? 'Sellado de la auditoría del archivo de exclusión',
                ],
            );
        }
    }

    /**
     * ¿Existe la tabla y responde la base de datos?
     *
     * Se pregunta con red de seguridad porque el análisis del mapa no necesita la base de
     * datos para nada: si está caída, el operador tiene que poder ver igual que su sitio
     * declara cuarenta páginas de apuestas. Un control que se apaga entero porque falla
     * algo que no le hacía falta no es un control, es una dependencia disfrazada.
     */
    private function tablaDisponible(string $tabla): bool
    {
        try {
            return Schema::hasTable($tabla);
        } catch (Throwable) {
            return false;
        }
    }

    private function clave(string $prefijo, string $host): string
    {
        // La columna artefacto es única y de 80 caracteres: un anfitrión largo tiene que
        // caber sin colisionar con otro y sin que la base de datos lo recorte por su cuenta.
        return $prefijo.':'.mb_substr(strtolower($host), 0, 70);
    }

    // -------------------------------------------------------------------------
    // Persistencia de hallazgos e incidentes
    // -------------------------------------------------------------------------

    /**
     * Guarda los hallazgos y levanta los incidentes que correspondan.
     *
     * Un incidente por MOTIVO agregado y no uno por dirección: una campaña que inyecta
     * doscientas páginas es un incidente con doscientas evidencias, no doscientos
     * incidentes idénticos que hacen ilegible el panel justo cuando más hay que leerlo.
     *
     * @param  array<string, mixed>  $auditoria
     * @param  array{ip?: string|null, agente_usuario?: string|null, usuario_id?: int|string|null}  $contexto
     * @return array{hallazgos: int, incidentes: int}
     */
    public function registrar(array $auditoria, array $contexto = []): array
    {
        // El incidente y la bitácora se escriben SIEMPRE y PRIMERO, esté o no la tabla de
        // evidencia. Es el mismo criterio de RegistroIncidentesSeo y por la misma razón: si
        // falta una tabla, el hallazgo tiene que llegar igual al SIEM, que lee el archivo.
        // Condicionar la alerta a que exista la tabla de detalle sería apagar el control
        // por un problema que no es del control.
        $hayEvidencia = $this->tablaDisponible('hallazgos_mapa_sitio');

        $ejecucion = (string) $auditoria['ejecucion'];
        $sitio = (string) $auditoria['sitio'];

        $graves = array_values(array_filter(
            $auditoria['direcciones'],
            static fn (array $d): bool => in_array($d['veredicto'], [
                self::VEREDICTO_ANOMALA, self::VEREDICTO_INYECTADA, self::VEREDICTO_FANTASMA,
            ], true),
        ));

        $incidente = null;
        $incidentes = 0;

        if ($graves !== []) {
            $incidente = $this->registro->registrar(
                IncidenteSeo::TIPO_SITEMAP_AJENO,
                'Mapa del sitio de '.$sitio.': '.count($graves).' dirección(es) anómala(s) declaradas al buscador',
                [
                    'ruta' => (string) $auditoria['base'].'/sitemap.xml',
                    // Se agrupa por sitio y día: la auditoría periódica corre cada hora y
                    // sin agrupar abriría veinticuatro incidentes iguales cada día.
                    'agrupar_por' => 'mapa:'.$sitio,
                    'ip' => $contexto['ip'] ?? null,
                    'agente_usuario' => $contexto['agente_usuario'] ?? null,
                    'usuario_id' => $contexto['usuario_id'] ?? null,
                    'detalle' => [
                        'artefacto' => 'mapa:'.$sitio,
                        'motivo' => 'direcciones_anomalas_en_el_mapa',
                        'explicacion' => 'El mapa declara direcciones que no corresponden al negocio del sitio: patrón de inyección de contenido para posicionamiento',
                        'ejecucion' => $ejecucion,
                        'total_declaradas' => (int) $auditoria['resumen']['total'],
                        'anomalas' => count($graves),
                        'inyectadas_vivas' => (int) $auditoria['resumen']['inyectadas'],
                        'muestra' => array_map(
                            static fn (array $d): array => [
                                'url' => $d['url'],
                                'puntuacion' => $d['puntuacion'],
                                'veredicto' => $d['veredicto'],
                                'codigo_http' => $d['codigo_http'],
                                'reglas' => array_column($d['motivos'], 'regla'),
                            ],
                            array_slice($graves, 0, 20),
                        ),
                    ],
                ],
            );

            $incidentes++;
        }

        $hallazgosExclusion = (array) $auditoria['exclusion']['hallazgos'];

        if ($hallazgosExclusion !== []) {
            $this->registro->registrar(
                IncidenteSeo::TIPO_INTEGRIDAD,
                'Archivo de exclusión de '.$sitio.': '.count($hallazgosExclusion).' directiva(s) peligrosa(s)',
                [
                    'ruta' => (string) $auditoria['exclusion']['url'],
                    'agrupar_por' => 'exclusion:'.$sitio,
                    'ip' => $contexto['ip'] ?? null,
                    'usuario_id' => $contexto['usuario_id'] ?? null,
                    'detalle' => [
                        'artefacto' => 'exclusion:'.$sitio,
                        'motivo' => 'directivas_peligrosas',
                        'explicacion' => 'robots.txt gobierna qué rastrea el buscador: una línea añadida ahí cambia la superficie indexada del sitio entero',
                        'ejecucion' => $ejecucion,
                        'hallazgos' => array_slice($hallazgosExclusion, 0, 20),
                    ],
                ],
            );

            $incidentes++;
        }

        $base = $auditoria['linea_base'];

        if (($base['cambio'] ?? false) === true || ($base['exclusion_cambiada'] ?? false) === true) {
            $this->registro->registrar(
                IncidenteSeo::TIPO_INTEGRIDAD,
                'El mapa del sitio de '.$sitio.' cambió respecto de la línea base autorizada',
                [
                    'ruta' => (string) $auditoria['base'].'/sitemap.xml',
                    'agrupar_por' => 'linea_base_mapa:'.$sitio,
                    'ip' => $contexto['ip'] ?? null,
                    'usuario_id' => $contexto['usuario_id'] ?? null,
                    'detalle' => [
                        'artefacto' => (string) $base['artefacto_mapa'],
                        'motivo' => ($base['crecimiento_subito'] ?? false) ? 'crecimiento_subito' : 'huella_distinta',
                        'explicacion' => ($base['crecimiento_subito'] ?? false)
                            ? 'El mapa ganó de golpe un número de direcciones que el ritmo de publicación del sitio no explica'
                            : 'El conjunto de direcciones declaradas ya no es el autorizado',
                        'ejecucion' => $ejecucion,
                        'cantidad_anterior' => $base['cantidad_anterior'],
                        'cantidad_actual' => $base['cantidad_actual'],
                        'nuevas' => array_slice((array) $base['nuevas'], 0, 50),
                        'desaparecidas' => array_slice((array) $base['desaparecidas'], 0, 20),
                        'exclusion_cambiada' => (bool) ($base['exclusion_cambiada'] ?? false),
                    ],
                ],
            );

            $incidentes++;
        }

        $filas = 0;

        if (! $hayEvidencia) {
            return ['hallazgos' => 0, 'incidentes' => $incidentes];
        }

        foreach ($auditoria['direcciones'] as $direccion) {
            // Las direcciones limpias no se guardan: son el 99 % del mapa y llenarían la
            // tabla de filas que nadie va a leer. Lo que interesa conservar es la evidencia.
            if ((int) $direccion['puntuacion'] < self::UMBRAL_SOSPECHA) {
                continue;
            }

            if ($filas >= self::MAXIMO_EVIDENCIAS) {
                break;
            }

            HallazgoMapaSitio::query()->create([
                'ejecucion' => $ejecucion,
                'sitio' => $sitio,
                'tipo' => HallazgoMapaSitio::TIPO_DIRECCION,
                'url' => mb_substr((string) $direccion['url'], 0, 2000),
                'origen' => mb_substr((string) $direccion['origen'], 0, 500),
                'veredicto' => (string) $direccion['veredicto'],
                'puntuacion' => min(65535, (int) $direccion['puntuacion']),
                'motivos' => $direccion['motivos'],
                'profundidad' => min(255, (int) $direccion['profundidad']),
                'fecha_declarada' => $this->fechaONulo($direccion['lastmod'] ?? null),
                'codigo_http' => $direccion['codigo_http'],
                'titulo_remoto' => $direccion['titulo_remoto'] === null
                    ? null
                    : mb_substr((string) $direccion['titulo_remoto'], 0, 250),
                'destino_final' => $direccion['destino_final'],
                'comprobada_en' => $direccion['comprobada_en'],
                'incidente_id' => $incidente?->getKey(),
            ]);

            $filas++;
        }

        foreach ($hallazgosExclusion as $hallazgo) {
            // El mismo tope que arriba: un robots.txt puede traer cientos de líneas Allow
            // y cada una fabricaba una fila sin contar contra el límite de evidencia.
            if ($filas >= self::MAXIMO_EVIDENCIAS) {
                break;
            }

            HallazgoMapaSitio::query()->create([
                'ejecucion' => $ejecucion,
                'sitio' => $sitio,
                'tipo' => HallazgoMapaSitio::TIPO_EXCLUSION,
                'url' => (string) $auditoria['exclusion']['url'],
                'origen' => (string) $auditoria['exclusion']['url'],
                'veredicto' => (int) $hallazgo['puntos'] >= self::UMBRAL_ANOMALA
                    ? self::VEREDICTO_ANOMALA
                    : self::VEREDICTO_SOSPECHOSA,
                'puntuacion' => min(65535, (int) $hallazgo['puntos']),
                'motivos' => [$hallazgo],
                'profundidad' => 0,
            ]);

            $filas++;
        }

        return ['hallazgos' => $filas, 'incidentes' => $incidentes];
    }

    // -------------------------------------------------------------------------
    // Banco de pruebas: analizar un texto suelto
    // -------------------------------------------------------------------------

    /**
     * Puntúa una consulta o una dirección sueltas, sin descargar nada.
     *
     * Existe para poder pegar en pantalla las consultas del panel de Search Console de un
     * sitio comprometido y ver el veredicto en el acto. Es la mitad del control que se
     * puede DEMOSTRAR en tres minutos: la auditoría completa necesita un sitio comprometido
     * de verdad para enseñar algo, y esto no.
     *
     * @param  array<int, string>  $vocabularioDelSitio  Palabras propias del negocio, para
     *                                                   poder decidir si el texto es ajeno.
     * @return array{texto: string, puntuacion: int, veredicto: string, motivos: array<int, array{regla: string, descripcion: string, puntos: int, evidencia: string}>}
     */
    public function analizarTexto(string $texto, array $vocabularioDelSitio = []): array
    {
        $legible = $this->textoLegible($texto);
        $motivos = [];
        $puntuacion = 0;

        foreach ($this->detector->analizar($texto."\n".$legible)['motivos'] as $delDetector) {
            $puntuacion += (int) $delDetector['puntos'];
            $motivos[] = [
                'regla' => 'spam:'.$delDetector['regla'],
                'descripcion' => (string) $delDetector['descripcion'],
                'puntos' => (int) $delDetector['puntos'],
                'evidencia' => (string) $delDetector['evidencia'],
            ];
        }

        foreach ($this->senalesDeApuestas($legible) as $senal) {
            $puntuacion += $senal['puntos'];
            $motivos[] = $senal;
        }

        if (preg_match(self::PATRON_ALFABETO_AJENO, $legible, $ajeno) === 1) {
            $puntuacion += 5;
            $motivos[] = $this->motivo('alfabeto_ajeno', 'Caracteres de un alfabeto que no corresponde a un sitio en español', 5, (string) $ajeno[0]);
        }

        if (preg_match('/\b[a-z0-9][a-z0-9-]{1,30}\.(com|net|org|xyz|top|vip|club|live|bet|icu|cc|io|co)\b/i', $texto, $dominio) === 1) {
            $puntuacion += 5;
            $motivos[] = $this->motivo('dominio_como_consulta', 'La consulta es un nombre de dominio: se busca la marca del atacante, no el negocio', 5, (string) $dominio[0]);
        }

        $vocabulario = $this->normalizarVocabulario($vocabularioDelSitio);

        if ($vocabulario !== [] && $this->fueraDelVocabulario($legible, $vocabulario)) {
            $puntuacion += 3;
            $motivos[] = $this->motivo('fuera_del_vocabulario', 'No comparte ninguna palabra con el vocabulario propio del sitio', 3, mb_substr(trim($legible), 0, 60));
        }

        return [
            'texto' => $texto,
            'puntuacion' => $puntuacion,
            'veredicto' => $this->veredicto($puntuacion),
            'motivos' => $motivos,
        ];
    }

    /**
     * Vocabulario legítimo a partir de frases sueltas ("camaras de seguridad guatemala").
     *
     * @param  array<int, string>  $frases
     * @return array<int, string>
     */
    public function normalizarVocabulario(array $frases): array
    {
        $palabras = [];

        foreach ($frases as $frase) {
            foreach ($this->tokens($this->textoLegible((string) $frase)) as $token) {
                if (mb_strlen($token) >= 4 && ! is_numeric($token)) {
                    $palabras[] = $token;
                }
            }
        }

        return array_values(array_unique($palabras));
    }

    // -------------------------------------------------------------------------
    // 1. Descarga del mapa del sitio
    // -------------------------------------------------------------------------

    /**
     * @param  array<int, string>  $urlsMapas
     * @return array{direcciones: array<int, array{url: string, lastmod: string|null, origen: string}>, mapas: array<int, array<string, mixed>>, errores: array<int, string>}
     */
    private function recolectar(array $urlsMapas, string $host): array
    {
        $pendientes = array_map(static fn (string $u): array => ['url' => $u, 'nivel' => 0], $urlsMapas);
        $vistos = [];
        $direcciones = [];
        $mapas = [];
        $errores = [];

        while ($pendientes !== [] && count($mapas) < self::MAXIMO_MAPAS) {
            $actual = array_shift($pendientes);
            $url = $actual['url'];

            // Un índice que se declara a sí mismo, o dos que se declaran mutuamente, serían
            // un bucle infinito servido por el atacante. Se corta por visitados y por nivel.
            if (isset($vistos[$url])) {
                continue;
            }

            $vistos[$url] = true;

            $respuesta = $this->pedir($url, self::TIMEOUT_DESCARGA);

            if ($respuesta === null || ! $respuesta->successful()) {
                $mapas[] = [
                    'url' => $url,
                    'tipo' => 'error',
                    'direcciones' => 0,
                    'detalle' => $respuesta === null ? 'sin respuesta' : 'HTTP '.$respuesta->status(),
                ];

                $errores[] = 'No se pudo descargar '.$url.($respuesta === null ? '' : ' (HTTP '.$respuesta->status().')');

                continue;
            }

            $cuerpo = $this->descomprimir($respuesta->body());
            $analisis = $this->interpretarMapa($cuerpo, $url);

            if ($analisis['tipo'] === 'indice') {
                if ($actual['nivel'] >= self::PROFUNDIDAD_MAXIMA_INDICE) {
                    $errores[] = 'Índice de mapas anidado más allá del límite en '.$url;
                } else {
                    foreach ($analisis['hijos'] as $hijo) {
                        // El índice lo sirve el sitio auditado, que es justo el que puede
                        // estar en manos del atacante: cada <loc> de aquí decide a dónde va
                        // a ir el servidor a buscar. Sin estas dos líneas, escribir
                        // "http://169.254.169.254/" en el índice bastaba para que el panel
                        // leyera las credenciales de la máquina y las trajera de vuelta.
                        if (! $this->puedePedirse($hijo)) {
                            $mapas[] = ['url' => $hijo, 'tipo' => 'rechazado', 'direcciones' => 0, 'detalle' => 'destino no público'];
                            $errores[] = 'El índice declara un mapa que apunta a una dirección interna o a un esquema que no es http: no se pide. '.mb_substr($hijo, 0, 160);

                            continue;
                        }

                        // Un mapa hijo alojado en otro dominio no es legítimo —el protocolo
                        // obliga a que el índice y sus mapas compartan anfitrión— y además
                        // convertiría el panel en el trampolín del atacante hacia el sitio
                        // que él elija. Se informa, que es lo que interesa, y no se descarga.
                        if (! $this->mismoSitio((string) parse_url($hijo, PHP_URL_HOST), $host)) {
                            $mapas[] = ['url' => $hijo, 'tipo' => 'rechazado', 'direcciones' => 0, 'detalle' => 'mapa hijo de otro dominio'];
                            $errores[] = 'El índice de mapas declara un mapa alojado en otro dominio: no se descarga y queda como hallazgo. '.mb_substr($hijo, 0, 160);

                            continue;
                        }

                        $pendientes[] = ['url' => $hijo, 'nivel' => $actual['nivel'] + 1];
                    }
                }
            }

            $lleno = false;

            foreach ($analisis['direcciones'] as $entrada) {
                if (count($direcciones) >= self::MAXIMO_DIRECCIONES) {
                    $errores[] = 'Se alcanzó el tope de '.self::MAXIMO_DIRECCIONES.' direcciones analizadas';
                    $lleno = true;

                    break;
                }

                $direcciones[] = $entrada;
            }

            // La fila del mapa se anota siempre, también cuando se llegó al tope dentro de
            // él: con "break 2" el mapa que colmó el análisis desaparecía de la tabla de
            // mapas leídos, y la pantalla decía haber leído menos archivos de los que leyó.
            $mapas[] = [
                'url' => $url,
                'tipo' => $analisis['tipo'],
                'direcciones' => $analisis['tipo'] === 'indice' ? count($analisis['hijos']) : count($analisis['direcciones']),
                'detalle' => $analisis['detalle'],
            ];

            if ($lleno) {
                break;
            }
        }

        // Un mapa envenenado puede declarar la misma dirección mil veces para inflar el
        // recuento; se analiza cada una una sola vez.
        $unicas = [];

        foreach ($direcciones as $entrada) {
            $unicas[$entrada['url']] ??= $entrada;
        }

        return ['direcciones' => array_values($unicas), 'mapas' => $mapas, 'errores' => $errores];
    }

    /**
     * @return array{tipo: string, direcciones: array<int, array{url: string, lastmod: string|null, origen: string}>, hijos: array<int, string>, detalle: string}
     */
    private function interpretarMapa(string $cuerpo, string $origen): array
    {
        $cuerpo = ltrim($cuerpo, "\xEF\xBB\xBF \t\n\r");

        if ($cuerpo === '') {
            return ['tipo' => 'vacio', 'direcciones' => [], 'hijos' => [], 'detalle' => 'respuesta vacía'];
        }

        // El estándar admite un mapa en texto plano, una dirección por línea. Se soporta
        // porque es exactamente el formato que sube un atacante con acceso a la carpeta:
        // no necesita generar XML válido para que Google se lo trague.
        if ($cuerpo[0] !== '<') {
            $direcciones = [];

            foreach (preg_split('/\R/', $cuerpo) ?: [] as $linea) {
                $linea = trim((string) $linea);

                if ($linea !== '' && preg_match('#^https?://#i', $linea) === 1) {
                    $direcciones[] = ['url' => $linea, 'lastmod' => null, 'origen' => $origen];
                }
            }

            return [
                'tipo' => $direcciones === [] ? 'desconocido' : 'texto',
                'direcciones' => $direcciones,
                'hijos' => [],
                'detalle' => $direcciones === [] ? 'no es XML ni lista de direcciones' : 'lista en texto plano',
            ];
        }

        $anterior = libxml_use_internal_errors(true);

        try {
            // LIBXML_NONET y sin expansión de entidades: el XML lo sirve un tercero, y un
            // mapa del sitio es un vector de XXE de manual. Aquí se está auditando un sitio
            // que puede estar en manos del atacante; confiar en su XML sería el chiste.
            $xml = simplexml_load_string($cuerpo, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } catch (Throwable) {
            $xml = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($anterior);
        }

        if (! $xml instanceof SimpleXMLElement) {
            return ['tipo' => 'ilegible', 'direcciones' => [], 'hijos' => [], 'detalle' => 'XML mal formado'];
        }

        $nombre = strtolower($xml->getName());

        if ($nombre === 'sitemapindex') {
            $hijos = [];

            foreach ($xml->children() as $hijo) {
                $loc = trim((string) $hijo->loc);

                if ($loc !== '') {
                    $hijos[] = $loc;
                }
            }

            return ['tipo' => 'indice', 'direcciones' => [], 'hijos' => $hijos, 'detalle' => count($hijos).' mapas hijos'];
        }

        $direcciones = [];

        foreach ($xml->children() as $hijo) {
            $loc = trim((string) $hijo->loc);

            if ($loc === '') {
                continue;
            }

            $lastmod = trim((string) $hijo->lastmod);

            $direcciones[] = [
                'url' => $loc,
                'lastmod' => $lastmod === '' ? null : $lastmod,
                'origen' => $origen,
            ];
        }

        return [
            'tipo' => $nombre === 'urlset' ? 'urlset' : $nombre,
            'direcciones' => $direcciones,
            'hijos' => [],
            'detalle' => count($direcciones).' direcciones declaradas',
        ];
    }

    /** Los mapas grandes se sirven comprimidos; el cliente HTTP no siempre lo deshace. */
    private function descomprimir(string $cuerpo): string
    {
        if (str_starts_with($cuerpo, "\x1f\x8b")) {
            // El segundo argumento es la diferencia entre descomprimir y morir: sin él,
            // gzdecode() expande hasta donde diga el archivo, y un mapa comprimido de un
            // kilobyte fabricado para eso deja sin memoria al proceso que lo audita.
            $plano = @gzdecode($cuerpo, self::MAXIMO_BYTES_MAPA);

            return $plano === false ? $cuerpo : $plano;
        }

        return $cuerpo;
    }

    // -------------------------------------------------------------------------
    // Perfil del corpus y utilidades
    // -------------------------------------------------------------------------

    /**
     * Qué es "normal" EN ESTE SITIO. Sin esto, la desviación no se puede medir.
     *
     * @param  array<int, array{url: string, lastmod: string|null, origen: string}>  $direcciones
     * @param  array<int, string>  $autorizadas  Direcciones de la línea base sellada, si la hay.
     * @return array<string, mixed>
     */
    private function perfilarCorpus(array $direcciones, string $host, array $autorizadas = []): array
    {
        $profundidades = [];
        $fechas = [];

        foreach ($direcciones as $entrada) {
            $ruta = (string) parse_url($entrada['url'], PHP_URL_PATH);
            $segmentos = array_values(array_filter(explode('/', trim($ruta, '/')), static fn (string $s): bool => $s !== ''));
            $profundidades[] = count($segmentos);

            if ($entrada['lastmod'] !== null) {
                try {
                    $fechas[] = Carbon::parse($entrada['lastmod']);
                } catch (Throwable) {
                    // Una fecha ilegible ya la puntúa la regla de fechas; aquí solo
                    // estorbaría al cálculo de la mediana.
                }
            }
        }

        $total = count($direcciones);

        $muestra = $autorizadas !== []
            ? $autorizadas
            : array_map(static fn (array $e): string => $e['url'], $direcciones);

        return [
            'host' => $host,
            'total' => $total,
            'profundidad_mediana' => $this->mediana($profundidades),
            'vocabulario' => $this->vocabularioDe($muestra),
            'vocabulario_sellado' => $autorizadas !== [],
            'fecha_mediana' => $this->medianaDeFechas($fechas),
            'rutas_excluidas' => $this->politica->rutasNoIndexables(),
        ];
    }

    /**
     * Palabras que el sitio usa de verdad, a partir de un conjunto de direcciones.
     *
     * @param  array<int, string>  $urls
     * @return array<int, string>
     */
    private function vocabularioDe(array $urls): array
    {
        $total = count($urls);

        // Por debajo de veinte direcciones no hay vocabulario que valga: en un sitio de
        // diez páginas cada palabra aparece una sola vez y NINGUNA llegaría al mínimo, de
        // modo que "/contacto" y "/nosotros" saldrían marcadas como ajenas a su propio
        // sitio. La regla se apaga sola en vez de mentir.
        if ($total < 20) {
            return [];
        }

        $frecuencia = [];

        foreach ($urls as $url) {
            foreach ($this->tokens($this->textoLegible((string) parse_url($url, PHP_URL_PATH))) as $token) {
                if (mb_strlen($token) >= 4 && ! is_numeric($token)) {
                    $frecuencia[$token] = ($frecuencia[$token] ?? 0) + 1;
                }
            }
        }

        // Una palabra entra en el vocabulario propio si se REPITE: un término que aparece
        // en una sola dirección puede ser precisamente el inyectado, y meterlo en el
        // vocabulario haría que el control se autoconvenciera de que el spam es normal.
        $minimo = max(2, (int) ceil($total * 0.03));

        return array_keys(array_filter($frecuencia, static fn (int $n): bool => $n >= $minimo));
    }

    /**
     * Direcciones del estado autorizado, si alguien selló alguna vez este sitio.
     *
     * @return array<int, string>
     */
    private function urlsAutorizadas(string $host): array
    {
        if (! $this->tablaDisponible('lineas_base_seo')) {
            return [];
        }

        $linea = LineaBaseSeo::query()->where('artefacto', $this->clave('mapa', $host))->first();

        if (! $linea instanceof LineaBaseSeo) {
            return [];
        }

        return array_values(array_filter(
            (array) (($linea->resumen ?? [])['urls'] ?? []),
            static fn ($valor): bool => is_string($valor),
        ));
    }

    /**
     * @param  array<int, int>  $valores
     */
    private function mediana(array $valores): int
    {
        if ($valores === []) {
            return 0;
        }

        sort($valores);

        return (int) $valores[intdiv(count($valores), 2)];
    }

    /**
     * @param  array<int, Carbon>  $fechas
     */
    private function medianaDeFechas(array $fechas): ?Carbon
    {
        if ($fechas === []) {
            return null;
        }

        usort($fechas, static fn (Carbon $a, Carbon $b): int => $a <=> $b);

        return $fechas[intdiv(count($fechas), 2)];
    }

    /**
     * Texto legible de una dirección: se decodifica y los separadores se vuelven espacios.
     *
     * Sin este paso nada funciona: los patrones de DetectorSpamSeo usan \b y "/slot-gacor/"
     * no tiene fronteras de palabra donde hacen falta, y los kanji viajan en %E3%83%.
     */
    private function textoLegible(string $url): string
    {
        $texto = rawurldecode($url);
        $texto = $this->detector->sanearUtf8($texto);
        $texto = (string) preg_replace('#[/_\-+.?&=%:,;\#\[\]()]+#u', ' ', $texto);

        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }

    /**
     * @return array<int, string>
     */
    private function tokens(string $texto): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($texto), $coincidencias);

        return $coincidencias[0] ?? [];
    }

    /**
     * Comparación de anfitrión por ETIQUETA COMPLETA, nunca por sufijo de cadena.
     *
     * Con str_ends_with, "marketgt.gt.sitio-del-atacante.tld" pasaría por propio y el
     * control entero quedaría anulado por el truco más viejo del oficio.
     */
    private function mismoSitio(string $candidato, string $propio): bool
    {
        $candidato = strtolower(ltrim($candidato, '.'));
        $propio = strtolower(ltrim($propio, '.'));

        if ($candidato === $propio) {
            return true;
        }

        $raiz = str_starts_with($propio, 'www.') ? substr($propio, 4) : $propio;

        return $candidato === $raiz
            || $candidato === 'www.'.$raiz
            || str_ends_with($candidato, '.'.$raiz);
    }

    /**
     * ¿Los dos anfitriones son el mismo proyecto en entornos distintos?
     *
     * Se exige que compartan la primera etiqueta Y que el declarado no tenga más de tres,
     * y lo segundo es lo que impide el truco: "marketgt.gt.sitio-del-atacante.tld" también
     * empieza por "marketgt", y sin el límite de etiquetas se colaría como "otro entorno"
     * rebajando su propia alarma justo cuando más había que darla.
     *
     * No es infalible —sin una lista de sufijos públicos no se puede saber qué parte del
     * nombre es el dominio registrable—, por eso solo sirve para BAJAR la severidad de un
     * hallazgo que se reporta igual, nunca para callarlo.
     */
    private function comparteEtiquetaPrincipal(string $declarado, string $propio): bool
    {
        $etiquetasDeclarado = explode('.', strtolower(ltrim($declarado, '.')));
        $etiquetasPropio = explode('.', strtolower(ltrim($propio, '.')));

        if (count($etiquetasDeclarado) > 3) {
            return false;
        }

        return ($etiquetasDeclarado[0] ?? '') !== ''
            && ($etiquetasDeclarado[0] ?? '') === ($etiquetasPropio[0] ?? '');
    }

    /**
     * @param  array<int, string>  $patrones
     */
    private function rutaExcluida(string $ruta, array $patrones): bool
    {
        $ruta = '/'.ltrim(trim($ruta), '/');

        foreach ($patrones as $patron) {
            $patron = '/'.ltrim(rtrim($patron, '*'), '/');

            if ($patron === '/') {
                continue;
            }

            if ($ruta === $patron || str_starts_with($ruta, rtrim($patron, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    private function pareceBusqueda(string $consulta): bool
    {
        parse_str($consulta, $parametros);

        foreach ($this->politica->parametrosDeBusqueda() as $parametro) {
            if (isset($parametros[$parametro]) && trim((string) $parametros[$parametro]) !== '') {
                return true;
            }
        }

        return false;
    }

    private function veredicto(int $puntuacion): string
    {
        return match (true) {
            $puntuacion >= self::UMBRAL_ANOMALA => self::VEREDICTO_ANOMALA,
            $puntuacion >= self::UMBRAL_SOSPECHA => self::VEREDICTO_SOSPECHOSA,
            default => self::VEREDICTO_LIMPIA,
        };
    }

    /**
     * @return array{regla: string, descripcion: string, puntos: int, evidencia: string}
     */
    private function motivo(string $regla, string $descripcion, int $puntos, string $evidencia): array
    {
        return [
            'regla' => $regla,
            'descripcion' => $descripcion,
            'puntos' => $puntos,
            'evidencia' => mb_substr($evidencia, 0, 200),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $direcciones
     * @param  array<string, mixed>  $exclusion
     * @param  array<string, mixed>  $contexto
     * @return array<string, int>
     */
    private function resumir(array $direcciones, array $exclusion, array $contexto): array
    {
        $cuenta = static fn (string $veredicto): int => count(array_filter(
            $direcciones,
            static fn (array $d): bool => $d['veredicto'] === $veredicto,
        ));

        return [
            'total' => count($direcciones),
            'limpias' => $cuenta(self::VEREDICTO_LIMPIA),
            'sospechosas' => $cuenta(self::VEREDICTO_SOSPECHOSA),
            'anomalas' => $cuenta(self::VEREDICTO_ANOMALA),
            'inyectadas' => $cuenta(self::VEREDICTO_INYECTADA),
            'fantasmas' => $cuenta(self::VEREDICTO_FANTASMA),
            'comprobadas' => count(array_filter($direcciones, static fn (array $d): bool => $d['comprobada_en'] !== null)),
            'hallazgos_exclusion' => count((array) $exclusion['hallazgos']),
            'profundidad_mediana' => (int) $contexto['profundidad_mediana'],
            'palabras_propias' => count((array) $contexto['vocabulario']),
            // Si el vocabulario sale del mapa actual y no de la línea base, el control
            // puede estar aprendiendo del propio atacante: la pantalla tiene que poder
            // decirlo en vez de dar una confianza que no tiene.
            'vocabulario_sellado' => (int) ($contexto['vocabulario_sellado'] ? 1 : 0),
        ];
    }

    private function fechaONulo(?string $valor): ?Carbon
    {
        if ($valor === null || trim($valor) === '') {
            return null;
        }

        try {
            return Carbon::parse($valor);
        } catch (Throwable) {
            return null;
        }
    }

    private function tituloDe(string $html): ?string
    {
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $coincidencia) !== 1) {
            return null;
        }

        $titulo = html_entity_decode(strip_tags($coincidencia[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $this->detector->sanearUtf8($titulo)));
    }

    /**
     * Dónde acabó de verdad la petición.
     *
     * Guzzle apila el historial de redirecciones en esa cabecera cuando se le pide
     * track_redirects, y es el único sitio donde queda constancia del salto: una página
     * que responde 200 después de haber rebotado a otro dominio se vería "sana" mirando
     * solo el código de estado.
     */
    private function destinoFinal(Response $respuesta): ?string
    {
        $historial = $respuesta->headers()['X-Guzzle-Redirect-History'] ?? [];

        if (is_array($historial) && $historial !== []) {
            return (string) end($historial);
        }

        $ubicacion = (string) $respuesta->header('Location');

        return $ubicacion === '' ? null : $ubicacion;
    }

    /**
     * Normaliza lo que escribió el operador y cierra la puerta al servidor interno.
     *
     * El campo lo rellena una persona con sesión de administrador, pero un campo que pide
     * una dirección y la descarga desde el servidor es una petición falsificada del lado
     * del servidor de manual: sin este filtro, escribir http://127.0.0.1:8200 convertiría
     * el panel en un escáner de la red interna. Se admite el anfitrión de la propia
     * aplicación porque la demostración del sábado corre en local.
     */
    private function normalizarBase(string $urlSitio): string
    {
        $urlSitio = trim($urlSitio);

        if ($urlSitio === '') {
            throw new InvalidArgumentException('Indique la dirección del sitio que se va a auditar.');
        }

        if (preg_match('#^https?://#i', $urlSitio) !== 1) {
            $urlSitio = 'https://'.$urlSitio;
        }

        $partes = parse_url($urlSitio);
        $host = strtolower((string) ($partes['host'] ?? ''));

        if ($host === '') {
            throw new InvalidArgumentException('La dirección no tiene un anfitrión válido.');
        }

        if (! $this->esDestinoPermitido($host)) {
            throw new InvalidArgumentException('Solo se auditan anfitriones públicos: '.$host.' resuelve a una dirección interna.');
        }

        $esquema = strtolower((string) ($partes['scheme'] ?? 'https'));
        $puerto = isset($partes['port']) ? ':'.((int) $partes['port']) : '';

        return $esquema.'://'.$host.$puerto;
    }

    private function esDestinoPermitido(string $host): bool
    {
        // La resolución se memoriza por corrida. Sin memoria, un mapa envenenado con cinco
        // mil anfitriones distintos convertiría la comprobación de seguridad en cinco mil
        // consultas de DNS, es decir, en la denegación de servicio que venía a evitar.
        if (array_key_exists($host, $this->resolucionesDeHost)) {
            return $this->resolucionesDeHost[$host];
        }

        $propio = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        if ($propio !== '' && $this->mismoSitio($host, $propio)) {
            return $this->resolucionesDeHost[$host] = true;
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : gethostbyname($host);

        if ($ip === $host && filter_var($host, FILTER_VALIDATE_IP) === false) {
            // No resolvió. Se deja pasar: el error real lo dará la petición HTTP, y
            // rechazar aquí confundiría un DNS lento con un destino prohibido.
            return $this->resolucionesDeHost[$host] = true;
        }

        return $this->resolucionesDeHost[$host]
            = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * ¿Se puede pedir esta dirección concreta sin convertir el panel en un escáner interno?
     *
     * POR QUÉ HACE FALTA ADEMÁS DE normalizarBase()
     * -----------------------------------------------------------------------
     * normalizarBase() filtra lo que escribe el operador, y con eso solo se cierra una de
     * las tres puertas. Las otras dos las abre el sitio auditado, que es precisamente el
     * que puede estar en manos del atacante:
     *
     *   - Un índice de mapas puede declarar <loc>http://169.254.169.254/…</loc>, y el
     *     servidor iría a buscarlo con sus propias credenciales de red.
     *   - Una <loc> cualquiera puede ser http://10.0.0.5:8080/admin/reiniciar, y la
     *     comprobación de respuesta del paso 3 la pediría con un GET.
     *
     * Es el vector clásico de petición falsificada del lado del servidor, y aquí es peor de
     * lo habitual porque el contenido que decide a dónde se pide lo escribe el adversario.
     * Se filtra por esquema —un mapa nunca declara file:// ni gopher://— y por dirección
     * resuelta, con la única excepción del anfitrión de la propia aplicación, que es el que
     * hace falta para poder demostrar el control contra el sitio local.
     */
    private function puedePedirse(string $url): bool
    {
        $partes = parse_url($url);

        if ($partes === false) {
            return false;
        }

        $esquema = strtolower((string) ($partes['scheme'] ?? ''));
        $host = strtolower((string) ($partes['host'] ?? ''));

        if ($host === '' || ! in_array($esquema, ['http', 'https'], true)) {
            return false;
        }

        return $this->esDestinoPermitido($host);
    }

    /**
     * Mapa concreto que pidió el operador, ya normalizado y filtrado.
     *
     * Se admite escribirlo relativo ("/sitemap-productos.xml") porque es lo que teclea
     * quien tiene delante la dirección del sitio; y se pasa por el mismo filtro que la
     * base, porque un campo que pide una dirección y la descarga desde el servidor es una
     * petición falsificada del lado del servidor con otro nombre.
     *
     * @return array<int, string>
     */
    private function mapaPedido(mixed $valor, string $base): array
    {
        if (! is_string($valor) || trim($valor) === '') {
            return [];
        }

        $mapa = trim($valor);

        if (preg_match('#^https?://#i', $mapa) !== 1) {
            $mapa = $base.'/'.ltrim($mapa, '/');
        }

        if (! $this->puedePedirse($mapa)) {
            throw new InvalidArgumentException('El mapa indicado no es una dirección pública que se pueda pedir: '.mb_substr($mapa, 0, 120));
        }

        return [$mapa];
    }

    private function pedir(string $url, int $segundos): ?Response
    {
        try {
            return Http::withHeaders([
                // Se identifica con nombre propio: un control que se disfraza de Googlebot
                // para auditar es indistinguible del ataque que dice perseguir.
                'User-Agent' => 'MarketGT-AuditorMapa/1.0 (+auditoria de indexacion)',
                'Accept' => '*/*',
            ])
                ->timeout($segundos)
                ->connectTimeout(min(4, $segundos))
                ->withOptions(['allow_redirects' => ['max' => 3, 'track_redirects' => true]])
                ->get($url);
        } catch (Throwable) {
            // Que un sitio ajeno no responda no es un fallo del control: es un dato, y se
            // informa como tal en la fila correspondiente.
            return null;
        }
    }
}
