<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\ConsultaSospechosa;
use App\Models\IncidenteSeo;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Análisis de las consultas de búsqueda con las que el sitio aparece en Google.
 *
 * POR QUÉ EXISTE ESTE CONTROL Y POR QUÉ NO BASTABA LO QUE YA HABÍA
 * ---------------------------------------------------------------------------
 * El sanitizador y el detector de spam vigilan lo que ENTRA por el formulario. Sirven
 * mientras el atacante escriba una reseña. No sirven de nada cuando el atacante ya tiene
 * acceso al servidor o al gestor de contenidos: entonces no publica nada por el formulario,
 * escribe los archivos directamente y ninguna de las dos capas llega a verlo.
 *
 * Esa es la situación real que motiva esta clase. Un sitio guatemalteco que vende cámaras
 * de seguridad aparecía en Google con estas consultas:
 *
 *      p9bet login      45 impresiones   0 clics
 *      0016bet          13 impresiones   0 clics
 *      96n.com          11 impresiones   1 clic
 *      kmj888           10 impresiones   0 clics
 *      porh300           2 impresiones   1 clic
 *
 * Son marcas de casas de apuestas asiáticas. El contenido inyectado no se veía en la
 * portada, no pasó por ningún formulario y el WAF no lo vio entrar. Pero Google sí lo
 * indexó, y Search Console lo delató. Este control convierte ese informe en una detección:
 * lo que el sitio no puede ver de sí mismo, se lo pregunta al buscador.
 *
 * CÓMO PUNTÚA
 * ---------------------------------------------------------------------------
 * Puntuación de anomalía acumulativa, el mismo criterio que DetectorSpamSeo y que el Core
 * Rule Set del WAF, por la misma razón: ninguna señal aislada prueba nada. Una consulta rara
 * con pocas impresiones no dice nada; una consulta sin relación con el negocio, con forma de
 * marca de apuestas y con 45 impresiones y cero clics, sí.
 *
 * Dos umbrales en vez de uno porque el destinatario no es un bloqueo automático sino una
 * persona: por debajo de 4 no se molesta a nadie, entre 4 y 6 se pide revisión humana, desde
 * 7 se declara envenenada y se abre incidente.
 */
class AnalizadorConsultas
{
    /** Desde aquí la consulta merece que una persona la mire. */
    public const UMBRAL_REVISION = 4;

    /** Desde aquí se da por contenido ajeno indexado y se abre incidente. */
    public const UMBRAL_ENVENENADA = 7;

    public const VEREDICTO_LIMPIA = 'limpia';

    public const VEREDICTO_REVISAR = 'revisar';

    public const VEREDICTO_ENVENENADA = 'envenenada';

    /**
     * Impresiones mínimas para que la tasa de clics signifique algo. Una consulta con una
     * sola impresión y cero clics no es una anomalía, es ruido estadístico: cualquier sitio
     * tiene cientos de esas cada semana.
     */
    public const MINIMO_IMPRESIONES = 10;

    /** Nunca menos de tres, aunque el usuario configure uno: con dos datos no hay tasa. */
    private const MINIMO_ABSOLUTO_IMPRESIONES = 3;

    /**
     * Un pegado de Search Console de un sitio grande trae decenas de miles de filas. El
     * límite protege la petición, no la lógica: analizar 40.000 consultas con ocho
     * expresiones regulares cada una agota el tiempo de ejecución en medio de la demostración.
     */
    public const LIMITE_FILAS = 500;

    /**
     * Tope de incidentes por análisis. Un sitio muy comprometido produce cientos de consultas
     * envenenadas, y abrir un incidente por cada una convierte el panel en un muro ilegible
     * justo cuando hay que leerlo. Se abren los peores y el resto queda en consultas_sospechosas.
     */
    public const LIMITE_INCIDENTES = 25;

    /**
     * Regla hermana en la numeración del proyecto. APP-15031 es contenido de usuario marcado
     * como spam; esta es la variante que se detecta DESPUÉS de la indexación, no antes.
     */
    public const REGLA = 'APP-15032';

    /**
     * Techo de lo que puede aportar el vocabulario de sector en una sola consulta.
     *
     * Una consulta son tres o cuatro palabras. Sin techo, "casino online" sumaba por el
     * diccionario de esta clase y otra vez por el de DetectorSpamSeo, y la puntuación dejaba
     * de significar "cuántas señales distintas" para significar "cuántos diccionarios repiten
     * lo mismo". El umbral solo tiene sentido si las señales son independientes.
     */
    private const TOPE_VOCABULARIO = 6;

    /** El mismo techo cuando la consulta sí habla del negocio. Ver senalesVocabulario(). */
    private const TOPE_VOCABULARIO_NEGOCIO = 3;

    /** Vocabulario del negocio real que motivó el control. Es un ejemplo editable. */
    public const VOCABULARIO_EJEMPLO = 'camara, camaras, seguridad, vigilancia, cctv, alarma, monitoreo, instalacion, mantenimiento, dvr, nvr';

    /**
     * El caso real, tal como se exporta de Search Console, con consultas legítimas del mismo
     * sitio delante. Está aquí y no en la pantalla porque es el dato con el que se comprueba
     * que el control funciona: si algún día deja de detectarlo, la prueba falla sola.
     *
     * Orden de columnas de Search Console: consulta, clics, impresiones.
     *
     * @var array<int, array{0: string, 1: int, 2: int}>
     */
    public const CONSULTAS_EJEMPLO = [
        ['camaras de seguridad guatemala', 28, 1320],
        ['mantenimiento de camaras de seguridad', 11, 460],
        ['instalacion de camaras cctv zona 10', 7, 210],
        ['camaras hikvision guatemala precio', 5, 180],
        ['alarma con monitoreo para negocio', 3, 96],
        ['p9bet login', 0, 45],
        ['0016bet', 0, 13],
        ['96n.com', 1, 11],
        ['kmj888', 0, 10],
        ['porh300', 1, 2],
    ];

    /**
     * Vocabulario de los sectores que abusan de dominios ajenos. Incluye chino, japonés,
     * coreano e indonesio a propósito: el contenido inyectado casi nunca está en español, y
     * un diccionario que solo mira el español está ciego justo ante el caso más frecuente.
     *
     * No duplica a DetectorSpamSeo, lo complementa: aquel busca FRASES dentro de una reseña
     * ("apuestas deportivas", "préstamos rápidos"); aquí llegan consultas de dos palabras
     * donde el término aparece suelto ("slot", "togel", "bokep").
     *
     * @var array<string, array{patron: string, puntos: int, descripcion: string}>
     */
    private const SECTORES = [
        'apuestas' => [
            // "rtp" se dejó FUERA del patrón a propósito: es también el protocolo de vídeo
            // de las cámaras IP, y marcaría como spam las consultas legítimas del negocio.
            'patron' => '/(\b(bet|bets|betting|casino|kasino|slot|slots|togel|toto|judi|situs|daftar|gacor|maxwin|bandar|taruhan|sabong|baccarat|sportsbook|jackpot|4d)\b|娱乐城|娛樂城|赌场|賭場|博彩|老虎机|百家乐|彩票|バカラ|カジノ|スロット|온라인카지노|바카라)/iu',
            'puntos' => 5,
            'descripcion' => 'Vocabulario de apuestas o casinos',
        ],
        'farmacia' => [
            'patron' => '/\b(viagra|cialis|kamagra|tadalafil|sildenafil|xanax|tramadol|oxycodone|ivermectina\s+sin\s+receta|pastillas\s+sin\s+receta)\b/iu',
            'puntos' => 5,
            'descripcion' => 'Vocabulario farmacéutico',
        ],
        'adulto' => [
            'patron' => '/(\b(porn|porno|xxx|xvideos|xnxx|hentai|bokep|jav|escort|onlyfans|camgirl|sexo\s+gratis)\b|エロ|無修正|色情)/iu',
            'puntos' => 5,
            'descripcion' => 'Vocabulario de contenido para adultos',
        ],
        'prestamos' => [
            'patron' => '/\b(payday\s+loan|pinjaman|pinjol|kredit\s+online|pr[eé]stamo\s+sin\s+buro|dinero\s+r[aá]pido\s+sin\s+aval)\b/iu',
            'puntos' => 4,
            'descripcion' => 'Vocabulario de préstamos rápidos',
        ],
        'replicas' => [
            'patron' => '/\b(r[eé]plica[s]?\s+(rolex|gucci|nike|omega|cartier)|super\s?clone|aaa\s+quality|fake\s+(rolex|gucci|watch))\b/iu',
            'puntos' => 4,
            'descripcion' => 'Vocabulario de réplicas de marcas',
        ],
        'cripto' => [
            'patron' => '/\b(airdrop|shitcoin|pump\s+and\s+dump|bitcoin\s+generator|dobla\s+tu\s+bitcoin|usdt\s+gratis|forex\s+se[nñ]ales)\b/iu',
            'puntos' => 4,
            'descripcion' => 'Vocabulario de estafa con criptomonedas',
        ],
    ];

    /**
     * Forma de las marcas de apuestas. Es una HEURÍSTICA, no una certeza: describe el aspecto
     * de las cadenas que usan estas campañas (cortas, mezcla de letras y cifras, sin ser
     * palabra de ningún idioma, a menudo con un sufijo del sector o con cifras de la suerte),
     * y nada impide que un nombre de producto legítimo tenga esa misma forma. Por eso suma
     * puntos en lugar de decidir, y por eso nunca llega sola al umbral de "envenenada".
     *
     * Se comprueba contra p9bet, 0016bet, kmj888, 96n y porh300, que son los del caso real.
     *
     * @var array<string, array{patron: string, puntos: int, descripcion: string}>
     */
    private const PATRONES_MARCA = [
        'sufijo_sector' => [
            // p9bet, 0016bet, hoki188slot: cifras + una palabra del sector pegada.
            // Se exige al menos una cifra para no marcar palabras corrientes que terminan
            // igual ("gameplay", "nightclub").
            'patron' => '/^(?=.{3,14}$)(?=.*\d)[a-z0-9]*(bet|win|slot|togel|toto|judi|casino|poker|club|vip)[a-z0-9]*$/',
            'puntos' => 4,
            'descripcion' => 'Forma de marca de apuestas: cifras y sufijo del sector',
        ],
        'letras_y_cifras' => [
            // kmj888, porh300: dos a seis letras impronunciables seguidas de cifras.
            'patron' => '/^[a-z]{2,6}\d{2,5}$/',
            'puntos' => 4,
            'descripcion' => 'Forma de marca de apuestas: letras sin significado seguidas de cifras',
        ],
        'cifras_y_letras' => [
            // 96n, 188max: la misma forma al revés.
            'patron' => '/^\d{2,6}[a-z]{1,5}\d{0,3}$/',
            'puntos' => 4,
            'descripcion' => 'Forma de marca de apuestas: cifras seguidas de letras',
        ],
        'cifras_de_la_suerte' => [
            // Cualquier mezcla que termine en las cifras que estas marcas repiten sin parar.
            'patron' => '/^(?=.*[a-z])(?=.*\d)[a-z0-9]{3,12}(88|99|77|777|888|999|4d)$/',
            'puntos' => 3,
            'descripcion' => 'Termina en las cifras habituales de estas marcas (88, 99, 777)',
        ],
    ];

    /**
     * Cifras que en este negocio son técnicas, no marcas: resoluciones, códecs y años.
     * Sin esta lista, "hd1080" y "ahd720" caían en el patrón de letras y cifras, que es
     * exactamente el falso positivo que haría que el responsable dejara de usar el control.
     *
     * @var array<int, string>
     */
    private const NUMEROS_TECNICOS = [
        '720', '1080', '1440', '2160', '4096', '480', '360', '265', '264', '2025', '2026', '2027',
    ];

    /**
     * Palabras con las que se busca la puerta de entrada de un servicio ajeno. Solas no
     * significan nada; junto a una marca desconocida son la consulta típica de quien busca
     * OTRO sitio y acaba viendo el tuyo.
     */
    private const PATRON_ACCESO = '/\b(login|masuk|daftar|apk|app|descargar\s+apk|link\s+alternatif|alternatif|register|deposit|withdraw)\b/iu';

    /** Alfabetos que en una tienda guatemalteca no tienen ninguna explicación honesta. */
    private const PATRON_ALFABETO_AJENO = '/[\x{0400}-\x{04ff}\x{0590}-\x{05ff}\x{0600}-\x{06ff}\x{0e00}-\x{0e7f}\x{0900}-\x{097f}]/u';

    public function __construct(
        private readonly DetectorSpamSeo $detector,
        private readonly RegistroIncidentesSeo $registro,
    ) {}

    /**
     * Punto de entrada: recibe el pegado de Search Console y devuelve la tabla puntuada.
     *
     * @param  array{vocabulario?: string|array<int, string>, marca?: string, dominio_propio?: string, minimo_impresiones?: int}  $opciones
     * @return array{filas: array<int, array<string, mixed>>, resumen: array<string, mixed>, contexto: array<string, mixed>}
     */
    public function analizar(string $pegado, array $opciones = []): array
    {
        $contexto = $this->contexto($opciones);
        $lectura = $this->interpretar($pegado);

        $filas = [];

        foreach ($lectura['filas'] as $fila) {
            $filas[] = $this->evaluar($fila, $contexto);
        }

        // Orden descendente por puntuación: lo que hay que mirar primero, primero. El
        // desempate por impresiones pone delante la consulta que más veces se enseñó.
        usort($filas, static function (array $a, array $b): int {
            return [$b['puntuacion'], $b['impresiones'] ?? 0] <=> [$a['puntuacion'], $a['impresiones'] ?? 0];
        });

        return [
            'filas' => $filas,
            'resumen' => $this->resumir($filas, $lectura),
            'contexto' => $contexto,
        ];
    }

    /**
     * Convierte el pegado en filas. Es deliberadamente tolerante: quien copia una tabla de
     * una pantalla no limpia el texto, y un control que exige un CSV perfecto es un control
     * que nadie usa.
     *
     * Admite, en este orden: columnas separadas por tabulador (lo que produce copiar la
     * tabla de Search Console), CSV con coma, columnas separadas por varios espacios, dos
     * cifras al final de la línea, y una consulta suelta por línea.
     *
     * @return array{filas: array<int, array{consulta: string, clics: int|null, impresiones: int|null}>, descartadas: int, recortado: bool}
     */
    public function interpretar(string $pegado): array
    {
        // Se reutiliza el saneado del detector en vez de repetirlo: un solo byte inválido
        // pegado desde Excel dejaba ciega a cualquier expresión regular con /u.
        $pegado = $this->detector->sanearUtf8($pegado);
        $pegado = str_replace(["\r\n", "\r"], "\n", $pegado);

        $filas = [];
        $descartadas = 0;
        $recortado = false;

        foreach (explode("\n", $pegado) as $linea) {
            if (trim($linea) === '') {
                continue;
            }

            if (count($filas) >= self::LIMITE_FILAS) {
                $recortado = true;
                break;
            }

            $campos = $this->separarCampos($linea);
            $consulta = trim((string) array_shift($campos));

            if ($consulta === '' || $this->esEncabezado($consulta)) {
                $descartadas++;

                continue;
            }

            $clics = isset($campos[0]) && $this->esNumero($campos[0]) ? $this->aEntero($campos[0]) : null;
            $impresiones = isset($campos[1]) && $this->esNumero($campos[1]) ? $this->aEntero($campos[1]) : null;

            $clave = $this->normalizar($consulta);

            // La misma consulta pegada dos veces (dos rangos de fechas, o un pegado
            // repetido por error) es una fila, no dos. Se conserva el valor mayor en vez de
            // sumarlos: sumar convierte un pegado duplicado por accidente en el doble de
            // impresiones, y la tasa de clics dejaría de ser la que informó Google.
            if (isset($filas[$clave])) {
                $filas[$clave]['clics'] = $this->mayor($filas[$clave]['clics'], $clics);
                $filas[$clave]['impresiones'] = $this->mayor($filas[$clave]['impresiones'], $impresiones);

                continue;
            }

            $filas[$clave] = [
                'consulta' => mb_substr($consulta, 0, 255),
                'clics' => $clics,
                'impresiones' => $impresiones,
            ];
        }

        return [
            'filas' => array_values($filas),
            'descartadas' => $descartadas,
            'recortado' => $recortado,
        ];
    }

    /**
     * Puntúa UNA consulta. Es pública porque la misma lógica tiene que poder usarse desde
     * una prueba o desde un comando de consola sin pasar por el pegado completo.
     *
     * @param  array{consulta: string, clics: int|null, impresiones: int|null}  $fila
     * @param  array<string, mixed>  $contexto
     * @return array<string, mixed>
     */
    public function evaluar(array $fila, array $contexto): array
    {
        $consulta = $fila['consulta'];
        $normalizada = $this->normalizar($consulta);
        $tokens = $this->tokenizar($normalizada);

        $senales = [];

        // La distancia semántica se calcula primero porque las demás señales dependen de
        // ella: una palabra ambigua pesa distinto en una consulta que habla del negocio que
        // en una que no habla de nada de lo que el sitio vende.
        $distancia = $this->senalDistanciaSemantica($tokens, $contexto);
        $hablaDelNegocio = $distancia === null && $contexto['vocabulario'] !== [];

        foreach ([
            $this->senalDesproporcion($fila, $contexto),
            $this->senalDominio($normalizada, $contexto),
            $distancia,
            $this->senalMarcaApuestas($tokens),
            $this->senalAcceso($normalizada, $hablaDelNegocio),
            $this->senalAlfabetoAjeno($consulta),
        ] as $senal) {
            if ($senal !== null) {
                $senales[] = $senal;
            }
        }

        foreach ($this->senalesVocabulario($consulta, $normalizada, $hablaDelNegocio) as $senal) {
            $senales[] = $senal;
        }

        $puntuacion = array_sum(array_column($senales, 'puntos'));

        return [
            'consulta' => $consulta,
            'normalizada' => $normalizada,
            'clics' => $fila['clics'],
            'impresiones' => $fila['impresiones'],
            'tasa_clics' => $this->tasaClics($fila['clics'], $fila['impresiones']),
            'puntuacion' => $puntuacion,
            'veredicto' => $this->veredicto($puntuacion),
            'senales' => $senales,
        ];
    }

    // -------------------------------------------------------------------------
    // Señales
    // -------------------------------------------------------------------------

    /**
     * SEÑAL 1. Desproporción entre impresiones y clics.
     *
     * Es la señal que delata el caso real y la más difícil de falsificar, porque no depende
     * de ningún diccionario: son los datos que da Google. Quien busca "p9bet login" quiere
     * entrar en p9bet; si el sitio se le muestra 45 veces y no pulsa ninguna, es que lo que
     * Google enseña de ese dominio no es lo que la persona buscaba. Esa distancia entre lo
     * que se indexa y lo que el sitio es, es la definición del contenido inyectado.
     *
     * @param  array{consulta: string, clics: int|null, impresiones: int|null}  $fila
     * @param  array<string, mixed>  $contexto
     * @return array{regla: string, descripcion: string, puntos: int, evidencia: string}|null
     */
    private function senalDesproporcion(array $fila, array $contexto): ?array
    {
        $impresiones = $fila['impresiones'];
        $clics = $fila['clics'];
        $minimo = (int) $contexto['minimo_impresiones'];

        if ($impresiones === null || $clics === null || $impresiones < $minimo) {
            return null;
        }

        if ($clics === 0) {
            // Cuantas más impresiones sin un solo clic, menos margen queda para el azar.
            $puntos = $impresiones >= $minimo * 3 ? 6 : 5;

            return [
                'regla' => 'desproporcion_clics',
                'descripcion' => 'Muchas impresiones y ningún clic: el resultado no es lo que se buscaba',
                'puntos' => $puntos,
                'evidencia' => $impresiones.' impresiones, 0 clics',
            ];
        }

        $tasa = $clics / $impresiones;

        if ($tasa < 0.01 && $impresiones >= $minimo * 3) {
            return [
                'regla' => 'tasa_clics_nula',
                'descripcion' => 'Tasa de clics por debajo del 1 % con volumen alto de impresiones',
                'puntos' => 3,
                'evidencia' => $impresiones.' impresiones, '.$clics.' clics ('.round($tasa * 100, 2).' %)',
            ];
        }

        return null;
    }

    /**
     * SEÑAL 5. La consulta ES un nombre de dominio que no es el propio.
     *
     * Quien escribe "96n.com" en Google busca 96n.com. Que aparezca otro dominio en esa
     * búsqueda significa que ese otro dominio contiene el nombre, la marca o el contenido de
     * 96n.com. No hay lectura inocente: o es contenido inyectado o es suplantación.
     *
     * @param  array<string, mixed>  $contexto
     * @return array{regla: string, descripcion: string, puntos: int, evidencia: string}|null
     */
    private function senalDominio(string $normalizada, array $contexto): ?array
    {
        if (preg_match('#^(?:https?://)?(?:www\.)?([a-z0-9-]+(?:\.[a-z0-9-]+)+)(?:/\S*)?$#u', $normalizada, $coincidencia) !== 1) {
            return null;
        }

        $dominio = $coincidencia[1];
        $tld = (string) substr((string) strrchr($dominio, '.'), 1);

        // El último tramo tiene que parecer un dominio de primer nivel. Sin esta condición,
        // una consulta legítima como "camara 2.5k" se leía como dominio.
        if (preg_match('/^[a-z]{2,}$/', $tld) !== 1) {
            return null;
        }

        $propio = (string) $contexto['dominio_propio'];

        if ($propio !== '' && ($dominio === $propio || str_ends_with($dominio, '.'.$propio))) {
            return null;
        }

        return [
            'regla' => 'consulta_es_dominio',
            'descripcion' => 'La consulta es otro dominio: alguien busca ese sitio y aparece el nuestro',
            'puntos' => 5,
            'evidencia' => $dominio,
        ];
    }

    /**
     * SEÑAL 2. Distancia semántica del negocio.
     *
     * El responsable declara el vocabulario de su actividad. Una consulta que no comparte
     * NINGÚN término con ese vocabulario ni con la marca no describe lo que el sitio vende.
     * Vale poco por sí sola —hay consultas legítimas con palabras que nadie declaró— y por
     * eso puntúa 3: es la señal que convierte en sospechoso lo que otra señal ya insinuó.
     *
     * @param  array<int, string>  $tokens
     * @param  array<string, mixed>  $contexto
     * @return array{regla: string, descripcion: string, puntos: int, evidencia: string}|null
     */
    private function senalDistanciaSemantica(array $tokens, array $contexto): ?array
    {
        /** @var array<int, string> $vocabulario */
        $vocabulario = $contexto['vocabulario'];

        if ($vocabulario === [] || $tokens === []) {
            return null;
        }

        foreach ($tokens as $token) {
            foreach ($vocabulario as $termino) {
                if ($this->emparenta($token, $termino)) {
                    return null;
                }
            }
        }

        return [
            'regla' => 'distancia_semantica',
            'descripcion' => 'Ningún término de la consulta pertenece al vocabulario declarado del negocio',
            'puntos' => 3,
            'evidencia' => implode(' ', array_slice($tokens, 0, 6)),
        ];
    }

    /**
     * SEÑAL 4. Forma de marca de apuestas. Solo se cuenta el primer patrón que coincide:
     * kmj888 encaja en dos, y cobrar por los dos sería contar la misma evidencia dos veces.
     *
     * @param  array<int, string>  $tokens
     * @return array{regla: string, descripcion: string, puntos: int, evidencia: string}|null
     */
    private function senalMarcaApuestas(array $tokens): ?array
    {
        foreach ($tokens as $token) {
            if ($this->esNumeroTecnico($token)) {
                continue;
            }

            foreach (self::PATRONES_MARCA as $clave => $definicion) {
                if (preg_match($definicion['patron'], $token) === 1) {
                    return [
                        'regla' => 'marca_apuestas_'.$clave,
                        'descripcion' => $definicion['descripcion'].' (heurística: describe la forma, no prueba el sector)',
                        'puntos' => $definicion['puntos'],
                        'evidencia' => $token,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * SEÑAL 3. Vocabulario de los sectores que abusan de dominios ajenos.
     *
     * Se apoya en DetectorSpamSeo para no reescribir lo que ya existe —su diccionario de
     * frases y su regla de caracteres chinos, japoneses y coreanos siguen valiendo aquí— y
     * añade encima el vocabulario suelto que aparece en consultas de dos palabras.
     *
     * @return array<int, array{regla: string, descripcion: string, puntos: int, evidencia: string}>
     */
    private function senalesVocabulario(string $consulta, string $normalizada, bool $hablaDelNegocio): array
    {
        $senales = [];

        foreach (self::SECTORES as $sector => $definicion) {
            if (preg_match($definicion['patron'], $normalizada, $coincidencia) === 1) {
                $senales[] = [
                    'regla' => 'sector_'.$sector,
                    'descripcion' => $definicion['descripcion'],
                    'puntos' => $definicion['puntos'],
                    'evidencia' => mb_substr((string) $coincidencia[0], 0, 60),
                ];
            }
        }

        foreach ($this->detector->analizar($consulta)['motivos'] as $motivo) {
            $senales[] = [
                'regla' => 'spam_'.$motivo['regla'],
                'descripcion' => $motivo['descripcion'].' (detector de spam compartido)',
                'puntos' => $motivo['puntos'],
                'evidencia' => $motivo['evidencia'],
            ];
        }

        // "Slot para memoria SD de la cámara" es una consulta legítima de esta tienda, y sin
        // este descuento la palabra "slot" la mandaba a revisión. Cuando la consulta SÍ habla
        // del negocio, una sola palabra ambigua del diccionario no basta para sospechar: hace
        // falta que además se active otra señal independiente, que es de lo que trata la
        // puntuación acumulativa. El contenido inyectado, en cambio, casi nunca menciona el
        // negocio, así que este descuento no le llega.
        return $this->aplicarTope(
            $senales,
            $hablaDelNegocio ? self::TOPE_VOCABULARIO_NEGOCIO : self::TOPE_VOCABULARIO,
        );
    }

    /**
     * Señal de apoyo: la consulta busca la puerta de entrada de un servicio ("p9bet login",
     * "daftar situs"). Puntúa poco porque "login" es una palabra corriente, y solo cuenta
     * cuando la consulta ya no pertenece al vocabulario del negocio.
     *
     * @return array{regla: string, descripcion: string, puntos: int, evidencia: string}|null
     */
    private function senalAcceso(string $normalizada, bool $hablaDelNegocio): ?array
    {
        if (preg_match(self::PATRON_ACCESO, $normalizada, $coincidencia) !== 1) {
            return null;
        }

        // Si la consulta sí habla del negocio ("login camaras hikvision"), no hay señal:
        // el cliente que busca el acceso a su propio sistema de cámaras es legítimo.
        if ($hablaDelNegocio) {
            return null;
        }

        return [
            'regla' => 'acceso_a_servicio_ajeno',
            'descripcion' => 'Busca el acceso o la aplicación de un servicio que no es el nuestro',
            'puntos' => 2,
            'evidencia' => (string) $coincidencia[0],
        ];
    }

    /**
     * @return array{regla: string, descripcion: string, puntos: int, evidencia: string}|null
     */
    private function senalAlfabetoAjeno(string $consulta): ?array
    {
        // El chino, el japonés y el coreano ya los cubre DetectorSpamSeo; aquí se añaden los
        // alfabetos que aquel no mira (cirílico, árabe, hebreo, tailandés, devanagari) para
        // que no haya un hueco por el que pase justo la campaña de otro idioma.
        if (preg_match(self::PATRON_ALFABETO_AJENO, $consulta, $coincidencia) !== 1) {
            return null;
        }

        return [
            'regla' => 'alfabeto_ajeno',
            'descripcion' => 'Alfabeto que no corresponde al mercado del sitio',
            'puntos' => 4,
            'evidencia' => (string) $coincidencia[0],
        ];
    }

    // -------------------------------------------------------------------------
    // Persistencia
    // -------------------------------------------------------------------------

    /**
     * Guarda lo sospechoso y abre incidente por lo envenenado.
     *
     * Las de "revisar" se guardan pero NO abren incidente: un incidente es una afirmación de
     * que algo pasó, y una consulta de cuatro puntos todavía es una pregunta. Inflar el panel
     * con preguntas es la forma más rápida de que el analista deje de mirarlo.
     *
     * @param  array{filas: array<int, array<string, mixed>>, resumen: array<string, mixed>, contexto: array<string, mixed>}  $analisis
     * @param  array{ip?: string|null, agente_usuario?: string|null, ruta?: string|null, usuario_id?: int|string|null}  $opciones
     * @return array{guardadas: int, incidentes: int, fallidas: int}
     */
    public function registrar(array $analisis, array $opciones = []): array
    {
        $ahora = Carbon::now();
        $guardadas = 0;
        $incidentes = 0;
        $fallidas = 0;

        $vocabulario = $analisis['contexto']['vocabulario'] ?? [];

        foreach ($analisis['filas'] as $fila) {
            if ($fila['veredicto'] === self::VEREDICTO_LIMPIA) {
                continue;
            }

            $huella = hash('sha256', 'consulta|'.$fila['normalizada']);

            $incidente = null;

            if ($fila['veredicto'] === self::VEREDICTO_ENVENENADA && $incidentes < self::LIMITE_INCIDENTES) {
                $incidente = $this->abrirIncidente($fila, $vocabulario, $opciones);

                if ($incidente instanceof IncidenteSeo) {
                    $incidentes++;
                }
            }

            try {
                $this->guardarConsulta($fila, $huella, $vocabulario, $incidente, $ahora, $opciones);
                $guardadas++;
            } catch (Throwable) {
                // Un fallo al guardar una fila no puede tumbar el análisis entero: el
                // hallazgo ya está en la bitácora y en la pantalla, que es lo que mira la
                // persona. Se sigue con la siguiente consulta, pero se cuenta el fallo: un
                // control que dice "guardadas: 0" sin distinguir entre "no había nada" y
                // "no se pudo" está mintiendo por omisión justo cuando más importa.
                $fallidas++;

                continue;
            }
        }

        return ['guardadas' => $guardadas, 'incidentes' => $incidentes, 'fallidas' => $fallidas];
    }

    /**
     * @param  array<string, mixed>  $fila
     * @param  array<int, string>  $vocabulario
     * @param  array<string, mixed>  $opciones
     */
    private function abrirIncidente(array $fila, array $vocabulario, array $opciones): ?IncidenteSeo
    {
        $resumen = 'Consulta ajena al negocio indexada: "'.$fila['consulta'].'" ('
            .$this->plural($fila['impresiones'], 'impresión', 'impresiones').', '
            .$this->plural($fila['clics'], 'clic', 'clics').', '
            .$fila['puntuacion'].' puntos)';

        return $this->registro->registrar(IncidenteSeo::TIPO_CONTENIDO_SPAM, $resumen, [
            // Alta y no crítica a propósito: la evidencia es indirecta —viene de un informe
            // que pega una persona, no del propio servidor—, así que escala a revisión
            // humana inmediata, no a la alerta que despierta a alguien de madrugada.
            'severidad' => 'alta',
            'regla' => self::REGLA,
            // Se agrupa por consulta y no por dirección: el atacante no tiene dirección aquí,
            // el hecho observable es la consulta. Así una misma consulta revisada cada semana
            // es una fila con contador y no una fila nueva cada vez.
            'agrupar_por' => 'consulta:'.$fila['normalizada'],
            'ip' => $opciones['ip'] ?? null,
            'agente_usuario' => $opciones['agente_usuario'] ?? null,
            'ruta' => $opciones['ruta'] ?? null,
            'usuario_id' => $opciones['usuario_id'] ?? null,
            'detalle' => [
                'origen' => 'analizador de consultas de búsqueda',
                'consulta' => $fila['consulta'],
                'clics' => $fila['clics'],
                'impresiones' => $fila['impresiones'],
                'tasa_clics' => $fila['tasa_clics'],
                'puntuacion' => $fila['puntuacion'],
                'senales' => implode(', ', array_column($fila['senales'], 'regla')),
                'vocabulario_declarado' => implode(', ', $vocabulario),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $fila
     * @param  array<int, string>  $vocabulario
     * @param  array<string, mixed>  $opciones
     */
    private function guardarConsulta(
        array $fila,
        string $huella,
        array $vocabulario,
        ?IncidenteSeo $incidente,
        Carbon $ahora,
        array $opciones,
    ): void {
        $existente = ConsultaSospechosa::query()->where('huella', $huella)->first();

        $datos = [
            'clics' => $fila['clics'],
            'impresiones' => $fila['impresiones'],
            'tasa_clics' => $fila['tasa_clics'],
            'puntuacion' => $fila['puntuacion'],
            'veredicto' => $fila['veredicto'],
            'senales' => $fila['senales'],
            // El vocabulario se guarda con la fila porque el veredicto DEPENDE de él: sin
            // saber qué se declaró como propio del negocio, nadie puede reproducir ni
            // discutir la decisión seis meses después, que es justo lo que pide una auditoría.
            'vocabulario' => $vocabulario,
            'ultima_vez_en' => $ahora,
        ];

        if ($existente instanceof ConsultaSospechosa) {
            $existente->fill($datos);
            $existente->veces_vista++;

            if ($incidente instanceof IncidenteSeo) {
                $existente->incidente_seo_id = $incidente->getKey();
            }

            // Una consulta que se dio por cerrada y vuelve a aparecer en el informe sigue
            // indexada: el contenido inyectado no se ha limpiado y hay que volver a mirarlo.
            if ($existente->estado === ConsultaSospechosa::ESTADO_CERRADA) {
                $existente->estado = ConsultaSospechosa::ESTADO_NUEVA;
            }

            $existente->save();

            return;
        }

        ConsultaSospechosa::query()->create(array_merge($datos, [
            'consulta' => $fila['consulta'],
            'consulta_normalizada' => $fila['normalizada'],
            'huella' => $huella,
            'estado' => ConsultaSospechosa::ESTADO_NUEVA,
            'veces_vista' => 1,
            'primera_vez_en' => $ahora,
            'incidente_seo_id' => $incidente?->getKey(),
            'analizado_por' => $opciones['usuario_id'] ?? null,
        ]));
    }

    // -------------------------------------------------------------------------
    // Utilidades
    // -------------------------------------------------------------------------

    /**
     * Pegado de ejemplo con el caso real. Se construye en código y no como texto literal
     * porque el separador es un tabulador, y un tabulador invisible dentro de una constante
     * es exactamente el tipo de carácter que alguien "arregla" sin querer al editar.
     */
    public function ejemploSearchConsole(): string
    {
        $lineas = [implode("\t", ['Consulta', 'Clics', 'Impresiones'])];

        foreach (self::CONSULTAS_EJEMPLO as [$consulta, $clics, $impresiones]) {
            $lineas[] = implode("\t", [$consulta, (string) $clics, (string) $impresiones]);
        }

        return implode("\n", $lineas);
    }

    /**
     * @param  array<string, mixed>  $opciones
     * @return array<string, mixed>
     */
    private function contexto(array $opciones): array
    {
        $vocabulario = $opciones['vocabulario'] ?? self::VOCABULARIO_EJEMPLO;
        $marca = trim((string) ($opciones['marca'] ?? ''));

        $terminos = is_array($vocabulario)
            ? $vocabulario
            : (preg_split('/[,;\n\r]+/u', $vocabulario) ?: []);

        if ($marca !== '') {
            // La marca es vocabulario propio por definición: quien busca el nombre del
            // negocio no está buscando otra cosa.
            $terminos = array_merge($terminos, $this->tokenizar($this->normalizar($marca)));
        }

        $limpios = [];

        foreach ($terminos as $termino) {
            $termino = $this->normalizar((string) $termino);

            // Menos de tres letras no discrimina nada: "de" emparejaría media lista.
            if (mb_strlen($termino) >= 3) {
                $limpios[] = $termino;
            }
        }

        $dominio = $this->normalizar((string) ($opciones['dominio_propio'] ?? ''));
        $dominio = (string) preg_replace('#^(?:https?://)?(?:www\.)?|/.*$#u', '', $dominio);

        // Las etiquetas del propio dominio son vocabulario propio por definición: quien
        // busca "marketgt.duckdns.org" está buscando este sitio, y sin esta línea la
        // consulta más legítima que existe —el nombre del dominio— salía con distancia
        // semántica por no estar en la lista que escribió el responsable.
        foreach (explode('.', $dominio) as $etiqueta) {
            if (mb_strlen($etiqueta) >= 4) {
                $limpios[] = $etiqueta;
            }
        }

        return [
            'vocabulario' => array_values(array_unique($limpios)),
            'marca' => $marca,
            'dominio_propio' => $dominio,
            'minimo_impresiones' => max(
                self::MINIMO_ABSOLUTO_IMPRESIONES,
                (int) ($opciones['minimo_impresiones'] ?? self::MINIMO_IMPRESIONES),
            ),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $filas
     * @param  array{filas: array<int, array<string, mixed>>, descartadas: int, recortado: bool}  $lectura
     * @return array<string, mixed>
     */
    private function resumir(array $filas, array $lectura): array
    {
        $total = count($filas);
        $envenenadas = 0;
        $revisar = 0;
        $impresionesSospechosas = 0;

        foreach ($filas as $fila) {
            if ($fila['veredicto'] === self::VEREDICTO_ENVENENADA) {
                $envenenadas++;
            } elseif ($fila['veredicto'] === self::VEREDICTO_REVISAR) {
                $revisar++;
            } else {
                continue;
            }

            $impresionesSospechosas += (int) ($fila['impresiones'] ?? 0);
        }

        $sospechosas = $envenenadas + $revisar;

        return [
            'analizadas' => $total,
            'sospechosas' => $sospechosas,
            'envenenadas' => $envenenadas,
            'revisar' => $revisar,
            'limpias' => $total - $sospechosas,
            'porcentaje' => $total > 0 ? round($sospechosas * 100 / $total, 1) : 0.0,
            'impresiones_sospechosas' => $impresionesSospechosas,
            'lineas_descartadas' => $lectura['descartadas'],
            'recortado' => $lectura['recortado'],
        ];
    }

    private function veredicto(int $puntuacion): string
    {
        if ($puntuacion >= self::UMBRAL_ENVENENADA) {
            return self::VEREDICTO_ENVENENADA;
        }

        return $puntuacion >= self::UMBRAL_REVISION ? self::VEREDICTO_REVISAR : self::VEREDICTO_LIMPIA;
    }

    /**
     * Reparte el techo entre las señales de vocabulario recortando la última que se pasa, en
     * lugar de tirarla entera: la evidencia sigue visible en la pantalla aunque no sume.
     *
     * @param  array<int, array{regla: string, descripcion: string, puntos: int, evidencia: string}>  $senales
     * @return array<int, array{regla: string, descripcion: string, puntos: int, evidencia: string}>
     */
    private function aplicarTope(array $senales, int $tope): array
    {
        $acumulado = 0;

        foreach ($senales as $indice => $senal) {
            $disponible = max(0, $tope - $acumulado);
            $senales[$indice]['puntos'] = min($senal['puntos'], $disponible);
            $acumulado += $senales[$indice]['puntos'];
        }

        return $senales;
    }

    /**
     * @return array<int, string>
     */
    private function separarCampos(string $linea): array
    {
        $linea = trim($linea);

        if (str_contains($linea, "\t")) {
            return $this->limpiarCampos(explode("\t", $linea));
        }

        // Coma: solo si las columnas 2 y 3 son cifras. "camaras de seguridad, guatemala" es
        // UNA consulta con una coma dentro, no una consulta con columnas.
        if (str_contains($linea, ',')) {
            $campos = $this->limpiarCampos(explode(',', $linea));

            if ($this->pareceTabla($campos)) {
                return $campos;
            }
        }

        // Copiar de la pantalla de Search Console suele dejar varias columnas separadas por
        // espacios en blanco en vez de por tabuladores.
        $porEspacios = $this->limpiarCampos(preg_split('/\s{2,}/u', $linea) ?: []);

        if ($this->pareceTabla($porEspacios)) {
            return $porEspacios;
        }

        // Último intento: dos cifras al final de la línea, separadas por un solo espacio.
        // Se exige que lo anterior tenga alguna letra para no partir "camaras 4 8" por la mitad.
        if (preg_match('/^(.*\p{L}.*?)\s+(\d[\d.,]*)\s+(\d[\d.,]*)(?:\s.*)?$/u', $linea, $partes) === 1) {
            return [trim($partes[1]), $partes[2], $partes[3]];
        }

        return [$linea];
    }

    /**
     * @param  array<int, string>  $campos
     * @return array<int, string>
     */
    private function limpiarCampos(array $campos): array
    {
        $limpios = [];

        foreach ($campos as $campo) {
            // Se quitan las comillas del CSV y el espacio duro que trae el pegado del
            // navegador, que no es el mismo carácter que el espacio y rompe cualquier trim.
            $campo = str_replace(["\u{00a0}", "\u{202f}"], ' ', $campo);
            $limpios[] = trim($campo, " \t\"'");
        }

        // Search Console numera las filas al copiarlas de la pantalla. Si la primera columna
        // es solo una cifra y la segunda no lo es, esa cifra es el número de fila.
        if (count($limpios) >= 3 && $this->esNumero($limpios[0]) && ! $this->esNumero($limpios[1])) {
            array_shift($limpios);
        }

        return $limpios;
    }

    /**
     * @param  array<int, string>  $campos
     */
    private function pareceTabla(array $campos): bool
    {
        return count($campos) >= 3
            && $campos[0] !== ''
            && $this->esNumero($campos[1])
            && $this->esNumero($campos[2]);
    }

    /**
     * Fila de encabezado o de totales. Se descarta en vez de analizarla porque "Consulta"
     * y "Total" no comparten vocabulario con el negocio y aparecerían como sospechosas: el
     * primer falso positivo que vería el responsable sería el título de su propia tabla.
     */
    private function esEncabezado(string $consulta): bool
    {
        $palabras = [
            'consulta', 'consultas', 'consultas principales', 'principales consultas',
            'query', 'queries', 'top queries', 'search query', 'busqueda', 'busquedas',
            'termino de busqueda', 'terminos de busqueda', 'palabra clave', 'keyword',
            'total', 'totales', 'clics', 'clicks', 'impresiones', 'impressions',
        ];

        $normalizada = $this->normalizar($consulta);

        if (in_array($normalizada, $palabras, true)) {
            return true;
        }

        // El encabezado del CSV ("Consulta,Clics,Impresiones") no se parte como tabla porque
        // sus columnas no son cifras, y sin esta comprobación entraba como si fuera una
        // consulta más: el primer hallazgo de la pantalla sería el título de la propia tabla.
        $primero = trim((string) (preg_split('/[,;\t]/u', $normalizada)[0] ?? ''));

        return $primero !== $normalizada && in_array($primero, $palabras, true);
    }

    private function esNumero(string $campo): bool
    {
        $campo = trim(str_replace(' ', '', $campo));

        return $campo !== '' && preg_match('/^\d[\d.,]*$/', $campo) === 1;
    }

    private function aEntero(string $campo): int
    {
        return (int) preg_replace('/\D/', '', $campo);
    }

    /**
     * El resumen del incidente lo lee una persona en una lista de incidentes reales, no un
     * programa: "1 clics" delata que nadie miró la pantalla antes de presentarla.
     */
    private function plural(?int $cantidad, string $singular, string $plural): string
    {
        $cantidad ??= 0;

        return $cantidad.' '.($cantidad === 1 ? $singular : $plural);
    }

    private function mayor(?int $a, ?int $b): ?int
    {
        if ($a === null) {
            return $b;
        }

        return $b === null ? $a : max($a, $b);
    }

    private function tasaClics(?int $clics, ?int $impresiones): ?float
    {
        if ($clics === null || $impresiones === null || $impresiones <= 0) {
            return null;
        }

        return round($clics / $impresiones, 4);
    }

    /**
     * Minúsculas y sin acentos, para que "Cámaras" y "camaras" sean la misma palabra. Se
     * hace con una tabla y no con iconv //TRANSLIT porque aquel depende de la configuración
     * regional del servidor y devuelve resultados distintos en el portátil y en el hosting.
     */
    private function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($this->detector->sanearUtf8($texto)), 'UTF-8');

        $texto = strtr($texto, [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ]);

        return (string) preg_replace('/\s+/u', ' ', $texto);
    }

    /**
     * @return array<int, string>
     */
    private function tokenizar(string $normalizada): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', $normalizada, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Dos términos se consideran el mismo si uno contiene al otro y ambos son largos. Es un
     * sustituto pobre de una raíz léxica, y a cambio no necesita ninguna biblioteca: basta
     * para que "camaras" encuentre "camara" y "instalaciones" encuentre "instalacion".
     */
    private function emparenta(string $token, string $termino): bool
    {
        if ($token === $termino) {
            return true;
        }

        if (mb_strlen($token) < 4 || mb_strlen($termino) < 4) {
            return false;
        }

        return str_contains($token, $termino) || str_contains($termino, $token);
    }

    private function esNumeroTecnico(string $token): bool
    {
        if (preg_match('/(\d+)$/', $token, $partes) !== 1) {
            return false;
        }

        return in_array($partes[1], self::NUMEROS_TECNICOS, true);
    }
}
