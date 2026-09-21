<?php

// =============================================================================
// MarketGT - Controles PREVENTIVOS de SEO en la aplicacion
//
// Este fichero agrupa cuatro piezas que en el proyecto van en cuatro archivos.
// Al final de cada bloque esta el DESTINO exacto dentro del proyecto Laravel.
//
// Regla de oro del capitulo: el WAF detecta y bloquea lo que ENTRA; la
// aplicacion decide que se PUBLICA y que se INDEXA. Un WAF no puede poner
// rel="nofollow ugc" ni un noindex. Sin esta mitad, la defensa esta coja.
// =============================================================================


// #############################################################################
// 1. MIDDLEWARE: marca y registra al crawler falsificado
//    DESTINO: app/Http/Middleware/ValidarCrawler.php
//    REGISTRO: en bootstrap/app.php ->
//        $middleware->append(\App\Http\Middleware\ValidarCrawler::class);
// #############################################################################

namespace App\Http\Middleware;

use App\Services\Seguridad\VerificadorCrawler;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidarCrawler
{
    public function __construct(private VerificadorCrawler $verificador) {}

    public function handle(Request $request, Closure $next): Response
    {
        // OJO: $request->ip() solo devuelve la IP REAL si TrustProxies esta
        // configurado. Detras de Cloudflare + WAF hay DOS saltos; si no se
        // configura, todas las IP son las de Cloudflare y esta verificacion
        // marca a Googlebot como falso. Ver bootstrap/app.php:
        //     $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
        //         | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PROTO);
        $veredicto = $this->verificador->verificar(
            $request->ip(),
            $request->userAgent(),
        );

        // La vista puede consultarlo: @if(request()->attributes->get('crawler_verificado'))
        $request->attributes->set('crawler_verificado', $veredicto['verificado']);
        $request->attributes->set('crawler_veredicto', $veredicto);

        // Politica de MarketGT: NO servimos contenido distinto a nadie. Eso es
        // cloaking y Google lo penaliza aunque lo hagas "para defenderte".
        // Al bot falso se le responde 403; al legitimo, exactamente lo mismo
        // que a un humano. Esa es la postura defendible ante el profesor.
        if ($veredicto['declara_ser_bot'] && !$veredicto['verificado']) {
            abort(403, 'Crawler no verificado.');
        }

        return $next($request);
    }
}


// #############################################################################
// 2. REDIRECCION CON LISTA BLANCA
//    DESTINO: app/Services/Seguridad/RedireccionSegura.php
//
//    El control definitivo contra el open redirect NO es una expresion regular
//    de bloqueo: es no aceptar nunca una URL del usuario. Aqui el parametro
//    ?ir= no lleva una URL, lleva una CLAVE de un mapa cerrado.
// #############################################################################

namespace App\Services\Seguridad;

use Illuminate\Support\Facades\Log;

class RedireccionSegura
{
    /** Unicos destinos externos que MarketGT admite. Lista CERRADA. */
    private const DESTINOS = [
        'ayuda'    => 'https://support.google.com/',
        'sat'      => 'https://portal.sat.gob.gt/',
        'facebook' => 'https://www.facebook.com/',
    ];

    /**
     * @param  string  $clave  valor de ?ir=  (NO una URL)
     */
    public function resolver(string $clave, string $porDefecto = '/'): string
    {
        if (isset(self::DESTINOS[$clave])) {
            return self::DESTINOS[$clave];
        }

        Log::channel('seguridad')->warning('open_redirect_bloqueado', [
            'evento'    => 'SEO/OPEN-REDIRECT',
            'severidad' => 'ERROR',
            'clave'     => mb_substr($clave, 0, 200),
            'regla'     => 'APP-15040',
            'iso27001'  => 'A.8.26',
            'ts'        => now()->toIso8601String(),
        ]);

        return $porDefecto;
    }

    /**
     * Para el "volver a donde estaba" despues del login: solo rutas relativas
     * del propio sitio. Rechaza //evil.tld, /\evil.tld, https://... y esquemas.
     */
    public function rutaInternaSegura(?string $ruta, string $porDefecto = '/'): string
    {
        if ($ruta === null || $ruta === '') {
            return $porDefecto;
        }

        // Una barra, y lo siguiente NO puede ser otra barra ni una contrabarra.
        // Eso descarta de golpe //evil.tld, /\evil.tld y \/\/evil.tld.
        if (!preg_match('#^/(?![/\\\\])[^\\\\\s]*$#', $ruta)) {
            return $porDefecto;
        }

        // Y nunca puede aparecer un esquema, ni siquiera codificado.
        if (preg_match('#(?:^|[^a-z0-9])(?:javascript|data|vbscript|file)\s*:#i', rawurldecode($ruta))) {
            return $porDefecto;
        }

        return $ruta;
    }
}


// #############################################################################
// 3. SANITIZACION DE CONTENIDO DE USUARIO
//    DESTINO: app/Services/Seguridad/SanitizadorContenido.php
//
//    composer require stevebauman/purify        (v6.3.2, soporta Laravel 12/13)
//    php artisan vendor:publish --provider="Stevebauman\Purify\PurifyServiceProvider"
//
//    Detras hay HTMLPurifier: no "filtra lo malo" (lista negra, siempre se
//    evade), sino que RECONSTRUYE el HTML desde cero permitiendo solo lo que
//    esta en la lista blanca. Es la diferencia entre limpiar y reescribir.
// #############################################################################

namespace App\Services\Seguridad;

use Stevebauman\Purify\Facades\Purify;

class SanitizadorContenido
{
    /**
     * Limpia una resena o comentario y deja los enlaces inutiles para el SEO.
     *
     * config/purify.php, perfil 'ugc':
     *   'HTML.Allowed'    => 'p,br,strong,em,ul,ol,li,a[href|rel|target]',
     *   'HTML.Nofollow'   => true,     // anade rel="nofollow" a cada <a>
     *   'HTML.TargetBlank'=> true,
     *   'URI.AllowedSchemes' => ['http' => true, 'https' => true],
     *   'Attr.AllowedRel' => ['nofollow', 'ugc', 'noopener', 'noreferrer'],
     */
    public function limpiarUgc(string $html): string
    {
        $limpio = Purify::config('ugc')->clean($html);

        // rel="nofollow ugc noopener noreferrer" en TODO enlace de usuario.
        //   nofollow -> no le pasas autoridad de enlace al spammer
        //   ugc      -> "user generated content", el valor que Google define
        //               para contenido de terceros (resenas, comentarios)
        // Sin esto, el spam que se cuele SIGUE valiendole al atacante aunque
        // este visible. Con esto, aunque se cuele, no vale nada.
        return preg_replace_callback(
            '#<a\b([^>]*)>#i',
            function (array $m): string {
                $attrs = preg_replace('#\s+rel\s*=\s*(["\']).*?\1#i', '', $m[1]);
                return '<a' . $attrs . ' rel="nofollow ugc noopener noreferrer" target="_blank">';
            },
            $limpio,
        ) ?? $limpio;
    }

    /** Campos que NUNCA llevan HTML: nombre, slug, meta title. */
    public function soloTextoPlano(string $valor, int $max = 255): string
    {
        $valor = strip_tags($valor);
        $valor = html_entity_decode($valor, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $valor = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x{200b}-\x{200f}\x{202a}-\x{202e}]/u', '', $valor);

        return mb_substr(trim($valor ?? ''), 0, $max);
    }

    /**
     * Cuenta enlaces. Mas de 2 en una resena de producto es spam, no opinion.
     * Se usa como regla de validacion antes de guardar.
     */
    public function demasiadosEnlaces(string $texto, int $limite = 2): bool
    {
        return preg_match_all('#https?://|www\.[a-z0-9-]+\.|\[url#i', $texto) > $limite;
    }
}


// #############################################################################
// 4. CABECERAS Y META DE INDEXACION
//    DESTINO: app/Http/Middleware/CabecerasSeguridad.php
// #############################################################################

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CabecerasSeguridad
{
    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);

        // -- noindex en TODA pagina que refleje entrada del usuario ------------
        // La busqueda interna es el vector de "search results poisoning": el
        // atacante busca spam, la pagina de resultados lo refleja, Googlebot la
        // indexa y tu dominio aparece en Google vendiendo replicas.
        // La cabecera X-Robots-Tag funciona incluso si el atacante logra
        // inyectar su propio <meta name="robots"> en el HTML: la cabecera HTTP
        // manda sobre la etiqueta.
        $rutasNoIndexables = ['buscar', 'busqueda', 'carrito', 'cuenta/*', 'pedidos/*', 'ir'];

        if ($request->is(...$rutasNoIndexables)) {
            $respuesta->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }

        // -- CSP: corta el XSS almacenado, que es la via de entrada #1 del -----
        //    SEO spam injection. Sin 'unsafe-inline' no hay <script> inyectado
        //    que ejecute, y form-action impide exfiltrar por formulario.
        $respuesta->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "frame-ancestors 'none'",       // anti clickjacking
            "base-uri 'self'",              // anula un <base href> inyectado
            "form-action 'self'",
            "object-src 'none'",
            'upgrade-insecure-requests',
            'report-uri /csp-reporte',      // las violaciones alimentan el SIEM
        ]));

        $respuesta->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        $respuesta->headers->set('X-Content-Type-Options', 'nosniff');
        $respuesta->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $respuesta->headers->set('Permissions-Policy', 'geolocation=(), camera=(), microphone=()');
        $respuesta->headers->remove('X-Powered-By');

        return $respuesta;
    }
}

// =============================================================================
// config/logging.php - canal que lee el panel SIEM
// -----------------------------------------------------------------------------
// 'seguridad' => [
//     'driver' => 'single',
//     'path'   => storage_path('logs/seguridad.json'),
//     'level'  => 'debug',
//     'formatter' => Monolog\Formatter\JsonFormatter::class,
//     'permission' => 0640,
// ],
//
// El colector del SIEM lo lee igual que el audit.json de ModSecurity:
//   tail -F storage/logs/seguridad.json | jq -c .
// Asi el panel correlaciona en una sola linea de tiempo el evento del WAF
// (regla 15021) y el evento de la aplicacion (APP-15021, con el PTR incluido).
// =============================================================================
