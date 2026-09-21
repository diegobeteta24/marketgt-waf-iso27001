<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\IncidenteSeo;
use Illuminate\Support\Str;

/**
 * Lista blanca de destinos de redirección.
 *
 * La redirección abierta es una vulnerabilidad de posicionamiento antes que de phishing:
 * el atacante publica en foros y redes miles de enlaces con el dominio de MarketGT delante
 * y el suyo detrás. Google ve un dominio con reputación que redirige a un sitio de spam, y
 * quien paga la penalización es MarketGT. El visitante confía porque el enlace empieza con
 * el nombre de la tienda.
 *
 * El control definitivo NO es una expresión regular que bloquee dominios malos: es no
 * aceptar nunca una URL escrita por el usuario. El parámetro ?ir= no lleva una dirección,
 * lleva una CLAVE de un mapa cerrado. Lo que no está en el mapa no existe, y no hay
 * codificación, doble barra ni truco de unicode que invente una entrada nueva.
 */
class RedireccionSegura
{
    /**
     * Únicos destinos externos que MarketGT admite. Lista CERRADA: añadir uno es un cambio
     * de código que pasa por revisión, que es exactamente la intención.
     *
     * @var array<string, array{url: string, descripcion: string}>
     */
    private const DESTINOS = [
        'sat' => [
            'url' => 'https://portal.sat.gob.gt/portal/',
            'descripcion' => 'Superintendencia de Administración Tributaria',
        ],
        'banguat' => [
            'url' => 'https://www.banguat.gob.gt/',
            'descripcion' => 'Banco de Guatemala (tipo de cambio)',
        ],
        'diaco' => [
            'url' => 'https://www.mineco.gob.gt/diaco',
            'descripcion' => 'Dirección de Atención y Asistencia al Consumidor',
        ],
        'umg' => [
            'url' => 'https://www.umg.edu.gt/',
            'descripcion' => 'Universidad Mariano Gálvez de Guatemala',
        ],
    ];

    public function __construct(private readonly RegistroIncidentesSeo $registro) {}

    /**
     * @return array<string, array{url: string, descripcion: string}>
     */
    public function destinos(): array
    {
        return self::DESTINOS;
    }

    /**
     * Traduce la clave de ?ir= a una dirección real.
     *
     * @param  array{ip?: string|null, agente_usuario?: string|null, ruta?: string|null, usuario_id?: int|string|null}  $contexto
     */
    public function resolver(?string $clave, string $porDefecto = '/', array $contexto = []): string
    {
        $clave = is_string($clave) ? trim($clave) : '';

        if ($clave !== '' && isset(self::DESTINOS[$clave])) {
            return self::DESTINOS[$clave]['url'];
        }

        $this->registro->registrar(
            IncidenteSeo::TIPO_REDIRECCION_BLOQUEADA,
            'Redirección rechazada: la clave solicitada no está en la lista blanca',
            [
                'ip' => $contexto['ip'] ?? null,
                'agente_usuario' => $contexto['agente_usuario'] ?? null,
                'ruta' => $contexto['ruta'] ?? null,
                'usuario_id' => $contexto['usuario_id'] ?? null,
                'detalle' => [
                    'valor_recibido' => Str::limit($clave, 300),
                    // Si lo recibido parece una dirección completa, no es un error de tecleo:
                    // es un intento deliberado de redirección abierta.
                    'parece_url' => preg_match('#^[a-z][a-z0-9+.-]*:|^//#i', $clave) === 1,
                    'claves_validas' => array_keys(self::DESTINOS),
                ],
            ],
        );

        return $porDefecto;
    }

    /**
     * Para el "volver a donde estaba" después de iniciar sesión: solo rutas relativas del
     * propio sitio.
     *
     * Rechaza //sitio-del-atacante.tld (el navegador la trata como absoluta),
     * /\sitio-del-atacante.tld (varios navegadores la normalizan a la anterior),
     * las direcciones absolutas y cualquier esquema, aunque venga codificado.
     */
    public function rutaInternaSegura(?string $ruta, string $porDefecto = '/'): string
    {
        if ($ruta === null || trim($ruta) === '') {
            return $porDefecto;
        }

        $ruta = trim($ruta);

        // Una barra al principio, y lo siguiente no puede ser otra barra ni una barra
        // invertida. Eso descarta de golpe //destino, /\destino y \/\/destino.
        if (preg_match('#^/(?![/\\\\])[^\\\\\s]*$#u', $ruta) !== 1) {
            return $porDefecto;
        }

        $decodificado = rawurldecode($ruta);

        if (preg_match('#(?:^|[^a-z0-9])(?:javascript|data|vbscript|file|about):#i', $decodificado) === 1) {
            return $porDefecto;
        }

        // Retorno de carro o salto de línea codificados: inyección de cabeceras en la
        // respuesta de redirección (partición de respuesta HTTP).
        if (preg_match('/[\r\n\x00]/', $decodificado) === 1) {
            return $porDefecto;
        }

        return $ruta;
    }

    /**
     * Comprueba una dirección completa contra el mapa cerrado.
     *
     * Se compara el anfitrión por etiqueta completa: "portal.sat.gob.gt.atacante.tld"
     * termina en algo que se parece, y tiene que fallar igual.
     */
    public function esDestinoPermitido(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower($host);

        foreach (self::DESTINOS as $destino) {
            $permitido = strtolower((string) parse_url($destino['url'], PHP_URL_HOST));

            if ($host === $permitido) {
                return true;
            }
        }

        return false;
    }
}
