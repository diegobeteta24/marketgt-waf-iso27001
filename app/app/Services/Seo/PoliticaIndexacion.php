<?php

declare(strict_types=1);

namespace App\Services\Seo;

use Illuminate\Http\Request;

/**
 * Qué se indexa y qué no.
 *
 * El envenenamiento por resultados de búsqueda interna es el ataque más barato de todos:
 * el atacante busca en la tienda "viagra barata comprar aquí <enlace>", la página de
 * resultados refleja el término, el rastreador la indexa y a los pocos días MarketGT
 * aparece en Google vendiendo lo que no vende. No hace falta vulnerar nada; basta con que
 * la página de resultados sea indexable.
 *
 * La defensa tiene dos niveles y hacen falta los dos:
 *   - robots.txt pide que no se rastree. Es una petición, no una orden, y solo la cumplen
 *     los rastreadores honestos.
 *   - La cabecera X-Robots-Tag ordena que no se indexe. Manda sobre cualquier
 *     <meta name="robots"> del HTML, así que sigue valiendo aunque un atacante consiga
 *     inyectar su propia etiqueta en la página.
 */
class PoliticaIndexacion
{
    public const DIRECTIVA_NOINDEX = 'noindex, nofollow, noarchive';

    /**
     * Rutas que nunca deben aparecer en un buscador: reflejan entrada del usuario, exponen
     * datos de una sesión o son superficie de administración.
     *
     * @var array<int, string>
     */
    private const RUTAS_NO_INDEXABLES = [
        'buscar', 'buscar/*', 'busqueda', 'busqueda/*',
        'tienda/carrito', 'tienda/pago', 'tienda/comprobante/*',
        'carrito', 'carrito/*', 'pago', 'pago/*', 'comprobante/*',
        'cuenta', 'cuenta/*', 'pedidos', 'pedidos/*',
        'settings', 'settings/*', 'seguridad', 'seguridad/*',
        'siem', 'siem/*', 'seo', 'seo/*',
        'ir', 'login', 'register', 'forgot-password', 'reset-password/*',
        'confirm-password', 'verify-email', 'verify-email/*', 'two-factor-challenge',
    ];

    /**
     * Parámetros que convierten cualquier página en una página de resultados. Da igual en
     * qué ruta aparezcan: si la respuesta refleja lo que escribió el visitante, no se indexa.
     *
     * @var array<int, string>
     */
    private const PARAMETROS_DE_BUSQUEDA = [
        'buscar', 'busqueda', 'q', 'query', 's', 'termino', 'search', 'filtro', 'orden',
    ];

    public function debeLlevarNoindex(Request $peticion): bool
    {
        if ($peticion->is(...self::RUTAS_NO_INDEXABLES)) {
            return true;
        }

        foreach (self::PARAMETROS_DE_BUSQUEDA as $parametro) {
            if ($peticion->query->has($parametro) && trim((string) $peticion->query->get($parametro)) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Motivo legible, para que el incidente y el panel puedan explicarlo sin adivinar.
     */
    public function motivo(Request $peticion): ?string
    {
        if ($peticion->is(...self::RUTAS_NO_INDEXABLES)) {
            return 'ruta_no_indexable';
        }

        foreach (self::PARAMETROS_DE_BUSQUEDA as $parametro) {
            if ($peticion->query->has($parametro) && trim((string) $peticion->query->get($parametro)) !== '') {
                return 'resultados_de_busqueda';
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function rutasNoIndexables(): array
    {
        return self::RUTAS_NO_INDEXABLES;
    }

    /**
     * @return array<int, string>
     */
    public function parametrosDeBusqueda(): array
    {
        return self::PARAMETROS_DE_BUSQUEDA;
    }
}
