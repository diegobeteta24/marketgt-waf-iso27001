<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\IncidenteSeo;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Illuminate\Support\Str;

/**
 * Sanitización del contenido que escriben los usuarios (reseñas y comentarios).
 *
 * POR QUÉ NO BASTA EL WAF
 * ---------------------------------------------------------------------------
 * La regla 15030 corta el enlace externo que viaja en el cuerpo de la petición. Pero el WAF
 * no puede poner rel="nofollow ugc" ni decidir que una reseña se queda en revisión en vez
 * de publicarse. El WAF gobierna lo que ENTRA; la aplicación gobierna lo que se PUBLICA y
 * lo que se INDEXA. Sin esta mitad, la defensa está coja.
 *
 * CÓMO SE LIMPIA
 * ---------------------------------------------------------------------------
 * No se filtra "lo malo" con expresiones regulares —esa es una lista negra y siempre se
 * evade—: el HTML se RECONSTRUYE desde cero recorriendo el árbol y copiando únicamente las
 * etiquetas y los atributos de la lista blanca. Lo que no está en la lista no sobrevive,
 * aunque nadie haya previsto esa forma de escribirlo.
 *
 * A los enlaces que sobreviven se les impone rel="nofollow ugc noopener noreferrer":
 *   nofollow  no se le cede autoridad de enlace al spammer
 *   ugc       valor que Google define para contenido de terceros
 *   noopener  la pestaña destino no puede tocar window.opener (robo de pestaña)
 *   noreferrer no se le regala la URL de origen
 * Aunque un enlace de spam se cuele, con esto no le sirve de nada al atacante.
 */
class SanitizadorUgc
{
    /**
     * Lista blanca: etiqueta => atributos admitidos. Todo lo demás se descarta.
     *
     * No hay img: una imagen remota en una reseña es una baliza que revela la dirección de
     * cada persona que lee la página, y además es la vía del hotlinking.
     * No hay span ni div: son el envoltorio con el que se inyecta texto oculto por CSS.
     *
     * @var array<string, array<int, string>>
     */
    private const ETIQUETAS_PERMITIDAS = [
        'p' => [],
        'br' => [],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'u' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'blockquote' => [],
        'code' => [],
        'a' => ['href'],
    ];

    /**
     * Etiquetas que se eliminan CON su contenido. En el resto se conserva el texto y se
     * tira el envoltorio, pero el texto de un <script> no es texto: es código.
     *
     * @var array<int, string>
     */
    private const ETIQUETAS_DESTRUIDAS = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet',
        'noscript', 'template', 'svg', 'math', 'form', 'input', 'button', 'select',
        'textarea', 'link', 'meta', 'base', 'title', 'head',
    ];

    private const ETIQUETAS_VACIAS = ['br'];

    /** HTML anidado a mucha profundidad es una técnica para agotar la pila del analizador. */
    private const PROFUNDIDAD_MAXIMA = 20;

    public function __construct(
        private readonly DetectorSpamSeo $detector,
        private readonly RegistroIncidentesSeo $registro,
    ) {}

    /**
     * Flujo completo: limpia, puntúa y decide si el contenido se publica o queda retenido.
     *
     * Retener no es censurar: el contenido se guarda íntegro como evidencia del incidente y
     * una persona decide. Publicar spam y borrarlo después es perder la carrera, porque para
     * entonces el rastreador ya lo indexó.
     *
     * @param  array{ip?: string|null, agente_usuario?: string|null, ruta?: string|null, usuario_id?: int|null, origen?: string}  $contexto
     * @return array{html: string, texto: string, publicable: bool, puntuacion: int, motivos: array<int, array{regla: string, descripcion: string, puntos: int, evidencia: string}>, enlaces: array<int, string>, incidente_id: int|null}
     */
    public function procesar(string $contenido, array $contexto = []): array
    {
        $limpio = $this->limpiar($contenido);

        // Se analiza el ORIGINAL, no el limpio: si solo se analizara lo que sobrevive a la
        // limpieza, el <script> con la carga de spam ya habría desaparecido y el incidente
        // no se registraría nunca. Lo que importa es qué intentó publicar el usuario.
        $veredicto = $this->detector->analizar($contenido);

        $incidenteId = null;

        if (! $veredicto['publicable']) {
            $incidente = $this->registro->registrar(
                IncidenteSeo::TIPO_CONTENIDO_SPAM,
                'Contenido de usuario retenido: '.$veredicto['puntuacion'].' puntos de anomalía en '.($contexto['origen'] ?? 'contenido publicable'),
                [
                    'ip' => $contexto['ip'] ?? null,
                    'agente_usuario' => $contexto['agente_usuario'] ?? null,
                    'ruta' => $contexto['ruta'] ?? null,
                    'usuario_id' => $contexto['usuario_id'] ?? null,
                    'detalle' => [
                        'puntuacion' => $veredicto['puntuacion'],
                        'umbral' => DetectorSpamSeo::UMBRAL,
                        'reglas' => array_column($veredicto['motivos'], 'regla'),
                        'enlaces' => array_slice($veredicto['enlaces'], 0, 10),
                        'fragmento' => Str::limit($contenido, 500),
                        'origen' => $contexto['origen'] ?? null,
                    ],
                ],
            );

            $incidenteId = $incidente?->id;
        }

        return [
            'html' => $limpio,
            'texto' => $this->soloTextoPlano($limpio, 5000),
            'publicable' => $veredicto['publicable'],
            'puntuacion' => $veredicto['puntuacion'],
            'motivos' => $veredicto['motivos'],
            'enlaces' => $veredicto['enlaces'],
            'incidente_id' => $incidenteId,
        ];
    }

    /**
     * Reconstruye el HTML dejando solo lo que está en la lista blanca.
     */
    public function limpiar(string $html): string
    {
        $html = trim($html);

        if ($html === '') {
            return '';
        }

        $documento = new DOMDocument('1.0', 'UTF-8');

        // El HTML de un atacante está mal formado a propósito: es una de las maneras de
        // conseguir que el analizador y el navegador entiendan cosas distintas. Los errores
        // se silencian porque aquí no interesa que sea válido, interesa reconstruirlo.
        $estadoAnterior = libxml_use_internal_errors(true);

        $cargado = $documento->loadHTML(
            '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>'
            .$html
            .'</body></html>',
            // LIBXML_NONET impide que una entidad externa haga una petición de red desde el
            // servidor: es la puerta del XXE, y se cierra aunque aquí se cargue como HTML.
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($estadoAnterior);

        if ($cargado === false) {
            // Si ni siquiera se pudo analizar, se devuelve texto plano escapado. Ante la
            // duda, nunca se devuelve el original: se degrada a lo que no puede hacer daño.
            return $this->escapar($this->soloTextoPlano($html, 5000));
        }

        $cuerpo = $documento->getElementsByTagName('body')->item(0);

        if (! $cuerpo instanceof DOMNode) {
            return '';
        }

        $resultado = '';

        foreach ($cuerpo->childNodes as $hijo) {
            $resultado .= $this->reconstruir($hijo, 0);
        }

        return trim($resultado);
    }

    /**
     * Campos que nunca llevan HTML: nombre de producto, término de búsqueda, meta título.
     */
    public function soloTextoPlano(string $valor, int $maximo = 255): string
    {
        $valor = strip_tags($valor);
        $valor = html_entity_decode($valor, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Controles, anchos cero y marcas de dirección: invisibles en pantalla y usados para
        // partir palabras prohibidas o para invertir el texto que ve el rastreador.
        $valor = (string) preg_replace(
            '/[\x00-\x08\x0b\x0c\x0e-\x1f\x{200b}-\x{200f}\x{202a}-\x{202e}\x{2060}\x{feff}]/u',
            '',
            $valor,
        );

        return mb_substr(trim($valor), 0, $maximo);
    }

    private function reconstruir(DOMNode $nodo, int $profundidad): string
    {
        if ($nodo instanceof DOMText) {
            return $this->escapar($nodo->textContent);
        }

        if (! $nodo instanceof DOMElement) {
            // Comentarios, instrucciones de proceso y secciones CDATA no se copian: un
            // comentario condicional es HTML ejecutable en navegadores antiguos.
            return '';
        }

        $etiqueta = strtolower($nodo->nodeName);

        if (in_array($etiqueta, self::ETIQUETAS_DESTRUIDAS, true)) {
            return '';
        }

        if ($profundidad >= self::PROFUNDIDAD_MAXIMA) {
            return $this->escapar($nodo->textContent);
        }

        $interior = '';

        foreach ($nodo->childNodes as $hijo) {
            $interior .= $this->reconstruir($hijo, $profundidad + 1);
        }

        if (! array_key_exists($etiqueta, self::ETIQUETAS_PERMITIDAS)) {
            // Etiqueta desconocida: se tira el envoltorio y se conserva el texto. Borrar
            // también el texto convertiría la limpieza en pérdida de contenido legítimo.
            return $interior;
        }

        if ($etiqueta === 'a') {
            return $this->reconstruirEnlace($nodo, $interior);
        }

        if (in_array($etiqueta, self::ETIQUETAS_VACIAS, true)) {
            return '<'.$etiqueta.'>';
        }

        return '<'.$etiqueta.'>'.$interior.'</'.$etiqueta.'>';
    }

    private function reconstruirEnlace(DOMElement $nodo, string $interior): string
    {
        $destino = $this->normalizarDestino($nodo->getAttribute('href'));

        if ($destino === null) {
            // Enlace con destino inaceptable (javascript:, data:, esquema raro): se queda
            // el texto y desaparece el enlace. El usuario ve lo que escribió; el rastreador
            // no ve ningún enlace que seguir.
            return $interior;
        }

        return '<a href="'.$this->escapar($destino).'" rel="nofollow ugc noopener noreferrer" target="_blank">'
            .$interior
            .'</a>';
    }

    /**
     * Solo http y https, y solo con anfitrión. Todo lo demás se rechaza.
     *
     * Se decodifica antes de comprobar porque "jav&#x61;script:" y "java%73cript:" son el
     * mismo esquema escrito para que una comparación ingenua no lo reconozca.
     */
    private function normalizarDestino(string $href): ?string
    {
        $href = trim($href);

        if ($href === '') {
            return null;
        }

        $decodificado = strtolower(rawurldecode(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        // Espacios y controles intercalados: "java\tscript:alert(1)" lo ejecutan varios
        // navegadores, así que se quitan antes de mirar el esquema.
        $decodificado = (string) preg_replace('/[\s\x00-\x1f]/u', '', $decodificado);

        if (preg_match('/^(javascript|data|vbscript|file|about|blob):/i', $decodificado) === 1) {
            return null;
        }

        $partes = parse_url($href);

        if ($partes === false || ! isset($partes['scheme'], $partes['host'])) {
            return null;
        }

        if (! in_array(strtolower($partes['scheme']), ['http', 'https'], true)) {
            return null;
        }

        return mb_substr($href, 0, 500);
    }

    private function escapar(string $valor): string
    {
        return htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
