<?php

declare(strict_types=1);

namespace App\Services\Seo;

/**
 * Detector de spam de posicionamiento en contenido escrito por usuarios.
 *
 * Usa puntuación de anomalía, igual que el Core Rule Set de la capa 4, y por la misma
 * razón: ninguna señal aislada prueba nada. Una reseña puede citar un enlace sin ser spam,
 * y la palabra "crédito" es legítima en una tienda. Lo que delata la inyección de spam es
 * la ACUMULACIÓN: tres enlaces a dominios desechables, vocabulario de farmacia y texto
 * oculto en la misma reseña.
 *
 * Umbral 5, el mismo del WAF del proyecto, para que la defensa del sábado pueda decir que
 * las dos capas comparten criterio y no se contradicen entre sí.
 */
class DetectorSpamSeo
{
    public const UMBRAL = 5;

    /**
     * Vocabulario de las campañas clásicas de spam inyectado. Cada patrón lleva su puntaje:
     * el vocabulario farmacéutico y el de pirateria pesan más porque casi nunca aparecen en
     * una reseña honesta de una tienda guatemalteca.
     *
     * @var array<string, array{patron: string, puntos: int, descripcion: string}>
     */
    private const VOCABULARIO = [
        'farmacia' => [
            'patron' => '/\b(viagra|cialis|levitra|kamagra|tadalafil|sildenafil|xanax|tramadol|oxycodone|farmacia\s+(online|sin\s+receta))\b/iu',
            'puntos' => 5,
            'descripcion' => 'Vocabulario farmacéutico de spam',
        ],
        'juego' => [
            'patron' => '/\b(casino|tragamonedas|tragaperras|apuestas\s+(deportivas|en\s+l[ií]nea)|betting|bet365|poker\s+online|ruleta\s+en\s+l[ií]nea|jackpot)\b/iu',
            'puntos' => 4,
            'descripcion' => 'Vocabulario de casinos y apuestas',
        ],
        'prestamos' => [
            'patron' => '/\b(pr[eé]stamos?\s+(r[aá]pidos?|urgentes?|sin\s+buro|sin\s+aval)|cr[eé]dito\s+sin\s+(buro|historial)|dinero\s+f[aá]cil|payday\s+loan)\b/iu',
            'puntos' => 4,
            'descripcion' => 'Vocabulario de préstamos rápidos',
        ],
        'pirateria' => [
            'patron' => '/\b(crack(ed)?\s+(software|version)|keygen|serial\s+key|iptv\s+gratis|descargar\s+gratis\s+full|r[eé]plicas?\s+de\s+(relojes|bolsos)|full\s+mega\s+descarga)\b/iu',
            'puntos' => 5,
            'descripcion' => 'Vocabulario de piratería y réplicas',
        ],
        'servicios_seo' => [
            'patron' => '/\b(comprar\s+(seguidores|backlinks|enlaces)|posicionamiento\s+garantizado|primer\s+lugar\s+en\s+google|seo\s+barato)\b/iu',
            'puntos' => 3,
            'descripcion' => 'Oferta de servicios de posicionamiento',
        ],
        'cripto' => [
            'patron' => '/\b(bitcoin\s+(doubler|generator)|inversi[oó]n\s+garantizada|multiplica\s+tu\s+dinero|forex\s+se[nñ]ales|airdrop\s+gratis)\b/iu',
            'puntos' => 3,
            'descripcion' => 'Vocabulario de estafa financiera',
        ],
    ];

    /**
     * Dominios de baja reputación y acortadores. El acortador es la técnica estándar para
     * esconder el destino real de un enlace de spam: lo que se publica es inofensivo y lo
     * que Google sigue es otra cosa.
     *
     * @var array<int, string>
     */
    private const DOMINIOS_SOSPECHOSOS = [
        'bit.ly', 'tinyurl.com', 't.co', 'goo.gl', 'is.gd', 'cutt.ly', 'rebrand.ly',
        'shorturl.at', 'ow.ly', 'buff.ly', 'rb.gy', 'tiny.cc', 'bc.vc', 'adf.ly',
    ];

    /**
     * Dominios de primer nivel con tasa de abuso alta y precio cercano a cero: son los que
     * usan las granjas de enlaces porque se compran por cientos y se desechan.
     *
     * @var array<int, string>
     */
    private const TLD_SOSPECHOSOS = [
        'xyz', 'top', 'click', 'link', 'loan', 'work', 'gq', 'tk', 'ml', 'cf', 'ga',
        'buzz', 'rest', 'bar', 'country', 'download', 'stream', 'racing', 'win', 'bid',
    ];

    /** Más de dos enlaces en una reseña de producto es publicidad, no opinión. */
    private const LIMITE_ENLACES = 2;

    /**
     * Analiza un texto y devuelve el veredicto con la evidencia de cada señal.
     *
     * @return array{puntuacion: int, publicable: bool, motivos: array<int, array{regla: string, descripcion: string, puntos: int, evidencia: string}>, enlaces: array<int, string>}
     */
    public function analizar(string $texto): array
    {
        $motivos = [];
        $puntuacion = 0;

        // También el texto SIN normalizar se sanea: las dos reglas que lo examinan tal cual
        // (caracteres invisibles y marcado de enlace) usan el modificador /u y quedarían
        // ciegas ante el mismo byte inválido que desactivaba el resto del detector.
        $texto = $this->sanearUtf8($texto);

        // El atacante codifica para esquivar comparaciones literales: "v i a g r a" con
        // entidades HTML, o %76iagra. Se analiza el texto ya decodificado.
        $normalizado = $this->normalizar($texto);

        foreach (self::VOCABULARIO as $regla => $definicion) {
            if (preg_match($definicion['patron'], $normalizado, $coincidencia) === 1) {
                $puntuacion += $definicion['puntos'];
                $motivos[] = [
                    'regla' => $regla,
                    'descripcion' => $definicion['descripcion'],
                    'puntos' => $definicion['puntos'],
                    'evidencia' => mb_substr((string) $coincidencia[0], 0, 120),
                ];
            }
        }

        $enlaces = $this->extraerEnlaces($normalizado);

        if (count($enlaces) > self::LIMITE_ENLACES) {
            $puntos = 3;
            $puntuacion += $puntos;
            $motivos[] = [
                'regla' => 'exceso_enlaces',
                'descripcion' => 'Más de '.self::LIMITE_ENLACES.' enlaces en un solo comentario',
                'puntos' => $puntos,
                'evidencia' => count($enlaces).' enlaces',
            ];
        }

        foreach ($this->enlacesSospechosos($enlaces) as $sospechoso) {
            $puntuacion += $sospechoso['puntos'];
            $motivos[] = $sospechoso;
        }

        // Ataque de palabra clave japonesa: se inyectan kanji y kana en una tienda escrita
        // en español para posicionar en búsquedas de otro idioma. Es la firma inconfundible
        // de un sitio comprometido, por eso pesa tanto.
        if (preg_match('/[\x{3040}-\x{30ff}\x{3400}-\x{4dbf}\x{4e00}-\x{9fff}\x{ac00}-\x{d7af}]/u', $normalizado, $cjk) === 1) {
            $puntos = 5;
            $puntuacion += $puntos;
            $motivos[] = [
                'regla' => 'texto_cjk',
                'descripcion' => 'Caracteres chinos, japoneses o coreanos en una tienda en español',
                'puntos' => $puntos,
                'evidencia' => (string) $cjk[0],
            ];
        }

        // Texto oculto: visible para el rastreador, invisible para la persona. Es cloaking
        // hecho con CSS dentro del propio contenido.
        if (preg_match('/(display\s*:\s*none|visibility\s*:\s*hidden|font-size\s*:\s*0|text-indent\s*:\s*-\d{3,}|position\s*:\s*absolute\s*;\s*left\s*:\s*-\d{3,})/i', $normalizado, $oculto) === 1) {
            $puntos = 5;
            $puntuacion += $puntos;
            $motivos[] = [
                'regla' => 'texto_oculto',
                'descripcion' => 'Estilo que oculta el texto a la persona pero no al rastreador',
                'puntos' => $puntos,
                'evidencia' => mb_substr((string) $oculto[0], 0, 120),
            ];
        }

        // Caracteres invisibles y marcas de dirección: separan las letras de una palabra
        // prohibida para que ninguna comparación literal la encuentre.
        if (preg_match('/[\x{200b}-\x{200f}\x{202a}-\x{202e}\x{2060}\x{feff}]/u', $texto) === 1) {
            $puntos = 3;
            $puntuacion += $puntos;
            $motivos[] = [
                'regla' => 'caracteres_invisibles',
                'descripcion' => 'Caracteres de ancho cero o de control de dirección usados para evadir filtros',
                'puntos' => $puntos,
                'evidencia' => 'presentes',
            ];
        }

        if (preg_match('/\[url[=\]]|\[link[=\]]|<a\s|href\s*=/i', $texto) === 1 && $enlaces === []) {
            // Marcado de enlace sin enlace visible: intento de inyectar etiqueta cruda.
            $puntos = 2;
            $puntuacion += $puntos;
            $motivos[] = [
                'regla' => 'marcado_de_enlace',
                'descripcion' => 'Marcado de enlace en un campo que no admite enlaces',
                'puntos' => $puntos,
                'evidencia' => 'etiqueta o BBCode de enlace',
            ];
        }

        return [
            'puntuacion' => $puntuacion,
            'publicable' => $puntuacion < self::UMBRAL,
            'motivos' => $motivos,
            'enlaces' => $enlaces,
        ];
    }

    /**
     * Anfitriones citados en el texto. Se usa también para pintar la evidencia en el panel.
     *
     * @return array<int, string>
     */
    public function extraerEnlaces(string $texto): array
    {
        preg_match_all('#\b(?:https?://|www\.)[^\s<>"\')\]]+#iu', $texto, $coincidencias);

        return array_values(array_unique($coincidencias[0]));
    }

    /**
     * @param  array<int, string>  $enlaces
     * @return array<int, array{regla: string, descripcion: string, puntos: int, evidencia: string}>
     */
    private function enlacesSospechosos(array $enlaces): array
    {
        $hallazgos = [];

        foreach ($enlaces as $enlace) {
            $host = $this->anfitrionDe($enlace);

            if ($host === null) {
                continue;
            }

            foreach (self::DOMINIOS_SOSPECHOSOS as $acortador) {
                if ($host === $acortador || str_ends_with($host, '.'.$acortador)) {
                    $hallazgos[] = [
                        'regla' => 'acortador',
                        'descripcion' => 'Enlace acortado: esconde el destino real',
                        'puntos' => 4,
                        'evidencia' => $host,
                    ];

                    continue 2;
                }
            }

            $tld = strtolower((string) substr(strrchr($host, '.') ?: '', 1));

            if (in_array($tld, self::TLD_SOSPECHOSOS, true)) {
                $hallazgos[] = [
                    'regla' => 'dominio_desechable',
                    'descripcion' => 'Dominio de primer nivel con alta tasa de abuso (.'.$tld.')',
                    'puntos' => 3,
                    'evidencia' => $host,
                ];
            }
        }

        return $hallazgos;
    }

    private function anfitrionDe(string $enlace): ?string
    {
        $conEsquema = str_starts_with(strtolower($enlace), 'http') ? $enlace : 'http://'.$enlace;
        $host = parse_url($conEsquema, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /**
     * Decodifica lo que el atacante codificó para esquivar la comparación literal:
     * entidades HTML (&#118;iagra), codificación de URL (%76iagra) y espacios repetidos.
     */
    private function normalizar(string $texto): string
    {
        $texto = html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texto = rawurldecode($texto);

        // El saneado de UTF-8 va DESPUÉS de decodificar y antes de cualquier patrón, y es
        // lo que sostiene todo el detector: PCRE con el modificador /u se niega a recorrer
        // un sujeto que no sea UTF-8 válido y devuelve false, no cero coincidencias. Sin
        // esta línea, un solo byte %FF dentro de la reseña hacía que TODAS las reglas de
        // vocabulario, las de CJK y las de caracteres invisibles devolvieran false: la
        // misma carga que puntuaba 22 pasaba a puntuar 0 y se publicaba. Un byte de
        // evasión no puede apagar el control entero.
        $texto = $this->sanearUtf8($texto);

        return (string) preg_replace('/\s+/u', ' ', $texto);
    }

    /**
     * Sustituye los bytes que rompen la codificación en vez de rechazar el texto: una
     * reseña honesta pegada desde Word con un byte latin-1 suelto tiene que analizarse
     * igual, no desaparecer.
     */
    public function sanearUtf8(string $texto): string
    {
        return mb_check_encoding($texto, 'UTF-8') ? $texto : mb_scrub($texto, 'UTF-8');
    }
}
