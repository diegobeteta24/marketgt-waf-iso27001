<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Seguridad\PoliticaContenido;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cabeceras de seguridad de la capa 5.
 *
 * El WAF de la capa 4 mira lo que entra; estas cabeceras gobiernan lo que el
 * navegador hace con lo que sale. Son controles complementarios: si una carga XSS
 * se cuela entre las reglas del CRS, la CSP es lo único que queda entre el
 * atacante y la sesión de la víctima.
 */
class CabecerasSeguridad
{
    public function __construct(private readonly PoliticaContenido $politica) {}

    public function handle(Request $peticion, Closure $siguiente): Response
    {
        if (! (bool) config('seguridad.cabeceras.activas', true)) {
            return $siguiente($peticion);
        }

        // El nonce nace antes de renderizar: Vite, Livewire y Flux lo leen al
        // emitir sus etiquetas <script>. Si naciera después, saldrían sin nonce y
        // la propia aplicación quedaría bloqueada por su propia política.
        $nonce = $this->politica->generarNonce();

        // Disponible en las vistas como $nonceCsp para cualquier <script> propio.
        View::share('nonceCsp', $nonce);

        /** @var Response $respuesta */
        $respuesta = $siguiente($peticion);

        // Descargas y respuestas en flujo (comprobantes, exportaciones del SIEM)
        // no ejecutan nada en el navegador y añadirles cabeceras de documento solo
        // complica la depuración.
        if ($respuesta instanceof BinaryFileResponse || $respuesta instanceof StreamedResponse) {
            return $respuesta;
        }

        $cabeceras = $respuesta->headers;

        if ($this->politica->modo() !== PoliticaContenido::MODO_DESACTIVADA) {
            $cabeceras->set(
                $this->politica->nombreCabecera(),
                $this->politica->construir($nonce),
            );
        }

        // HSTS. Mitiga el descenso a http y el secuestro por SSL-strip: tras la
        // primera visita, el navegador se niega a hablar con el sitio en claro
        // aunque la víctima escriba http:// o esté en una red hostil. Un año,
        // subdominios incluidos y apto para la lista de precarga.
        //
        // Solo sobre TLS: enviarlo por http lo ignoran los navegadores, y en local
        // fijaría la regla en el navegador del equipo para siempre (localhost
        // quedaría inaccesible por http hasta borrar el estado HSTS a mano).
        if ($peticion->isSecure()) {
            $hsts = (array) config('seguridad.cabeceras.hsts', []);
            $valor = 'max-age='.(int) ($hsts['edad_maxima'] ?? 31536000);

            if ((bool) ($hsts['incluir_subdominios'] ?? true)) {
                $valor .= '; includeSubDomains';
            }

            if ((bool) ($hsts['precarga'] ?? true)) {
                $valor .= '; preload';
            }

            $cabeceras->set('Strict-Transport-Security', $valor);
        }

        // Confusión de tipo MIME: impide que el navegador "adivine" que un .txt
        // subido por un usuario es en realidad JavaScript y lo ejecute.
        $cabeceras->set('X-Content-Type-Options', 'nosniff');

        // Secuestro de clics, para navegadores que no entienden frame-ancestors.
        // Es redundante con la CSP a propósito: cuesta 30 bytes por respuesta.
        $cabeceras->set('X-Frame-Options', (string) config('seguridad.cabeceras.x_frame_options', 'DENY'));

        // Fuga por el encabezado Referer: sin esto, al salir de la tienda hacia un
        // sitio externo se le regala la URL completa, que puede llevar el número de
        // pedido o un identificador de sesión en la cadena de consulta.
        $cabeceras->set('Referrer-Policy', (string) config('seguridad.cabeceras.referrer_policy', 'strict-origin-when-cross-origin'));

        // Superficie del navegador: se apagan cámara, micrófono, geolocalización y
        // demás. Lo que no se enciende no se puede secuestrar desde un marco.
        $permisos = (array) config('seguridad.cabeceras.permissions_policy', []);

        if ($permisos !== []) {
            $partes = [];

            foreach ($permisos as $caracteristica => $permitido) {
                $partes[] = $caracteristica.'='.$permitido;
            }

            $cabeceras->set('Permissions-Policy', implode(', ', $partes));
        }

        // Aísla el contexto de navegación: una ventana abierta desde la tienda
        // pierde la referencia a window.opener, que es la vía del "tabnabbing".
        // Además habilita el aislamiento que necesitan las passkeys.
        $cabeceras->set('Cross-Origin-Opener-Policy', (string) config('seguridad.cabeceras.cross_origin_opener_policy', 'same-origin'));

        // Evita que otro sitio incruste recursos de la tienda y los mida para
        // deducir su contenido (ataques de canal lateral tipo Spectre).
        $cabeceras->set('Cross-Origin-Resource-Policy', (string) config('seguridad.cabeceras.cross_origin_resource_policy', 'same-origin'));

        // Reconocimiento: la versión del servidor y del marco de trabajo le dicen
        // al atacante qué exploits probar primero. Nginx quita las suyas; estas
        // las pone PHP.
        $cabeceras->remove('X-Powered-By');
        $cabeceras->remove('Server');

        return $respuesta;
    }
}
