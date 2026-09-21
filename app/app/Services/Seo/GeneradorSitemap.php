<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\Producto;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Sitemap generado por la aplicación, nunca escrito a mano en disco.
 *
 * Es una decisión de seguridad, no de comodidad. Un sitemap.xml que vive como archivo en
 * public/ es un archivo que se puede reescribir: quien consiga escritura —por FTP, por un
 * contenedor comprometido o por una dependencia con puerta trasera— le entrega a Google una
 * lista de URLs del atacante alojadas bajo el dominio de MarketGT. Generado en memoria a
 * partir del catálogo, no hay archivo que envenenar: para meter una URL ajena habría que
 * meterla antes en la base de datos, y eso deja rastro en otra capa.
 *
 * Además, ninguna dirección de resultados de búsqueda entra aquí. El sitemap es la lista de
 * lo que MarketGT pide que se indexe, y pedir que se indexe una página que refleja lo que
 * escribe cualquier visitante es abrirle la puerta al envenenamiento por indexación.
 */
class GeneradorSitemap
{
    /** El catálogo cambia despacio; regenerar en cada visita del rastreador es regalarle carga. */
    public const MINUTOS_CACHE = 30;

    /** Un sitemap admite 50 000 direcciones; la tienda del proyecto no se acerca, pero el límite se respeta. */
    private const LIMITE_URLS = 5000;

    public function generar(): string
    {
        $entradas = $this->entradas();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($entradas as $entrada) {
            $xml .= '  <url>'."\n";
            $xml .= '    <loc>'.$this->escapar($entrada['loc']).'</loc>'."\n";
            $xml .= '    <lastmod>'.$entrada['lastmod'].'</lastmod>'."\n";
            $xml .= '    <changefreq>'.$entrada['changefreq'].'</changefreq>'."\n";
            $xml .= '    <priority>'.$entrada['priority'].'</priority>'."\n";
            $xml .= '  </url>'."\n";
        }

        return $xml.'</urlset>'."\n";
    }

    /**
     * Direcciones que MarketGT declara indexables. Es la misma lista que vigila el comando
     * de integridad: lo que se publica y lo que se vigila no pueden salir de dos sitios
     * distintos, porque entonces se desincronizan y la vigilancia deja de valer.
     *
     * @return array<int, array{loc: string, lastmod: string, changefreq: string, priority: string}>
     */
    public function entradas(): array
    {
        $entradas = [
            [
                'loc' => url('/'),
                'lastmod' => Carbon::now()->toDateString(),
                'changefreq' => 'daily',
                'priority' => '1.0',
            ],
        ];

        if (Route::has('tienda.catalogo')) {
            $entradas[] = [
                'loc' => route('tienda.catalogo'),
                'lastmod' => Carbon::now()->toDateString(),
                'changefreq' => 'daily',
                'priority' => '0.9',
            ];
        }

        foreach ($this->productosPublicables() as $producto) {
            $entradas[] = [
                'loc' => $this->urlDeProducto((string) $producto->slug),
                'lastmod' => ($producto->updated_at ?? Carbon::now())->toDateString(),
                'changefreq' => 'weekly',
                'priority' => '0.8',
            ];
        }

        // Cinturón y tirantes: aunque una URL se colara desde la base de datos apuntando a
        // otro anfitrión, aquí no sale. El sitemap solo puede hablar del propio dominio.
        $propio = strtolower((string) parse_url(url('/'), PHP_URL_HOST));

        return array_values(array_filter(
            $entradas,
            static fn (array $entrada): bool => strtolower((string) parse_url($entrada['loc'], PHP_URL_HOST)) === $propio,
        ));
    }

    /**
     * @return iterable<int, Producto>
     */
    private function productosPublicables(): iterable
    {
        try {
            // El sitemap se sirve aunque las migraciones del catálogo todavía no se hayan
            // ejecutado: un 500 en /sitemap.xml es una URL rota de cara al buscador.
            if (! Schema::hasTable('productos')) {
                return [];
            }

            return Producto::query()
                ->where('activo', true)
                ->orderBy('id')
                ->limit(self::LIMITE_URLS)
                ->get(['slug', 'updated_at']);
        } catch (Throwable) {
            return [];
        }
    }

    private function urlDeProducto(string $slug): string
    {
        return Route::has('tienda.producto')
            ? route('tienda.producto', ['slug' => $slug])
            : url('/tienda/producto/'.$slug);
    }

    private function escapar(string $valor): string
    {
        return htmlspecialchars($valor, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
