<?php

declare(strict_types=1);

namespace App\Services\Seo;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Extrae de una página lo único que le importa a un buscador y lo resume en una huella.
 *
 * POR QUÉ NO SE COMPARA EL HTML COMPLETO
 * ---------------------------------------------------------------------------
 * Dos respuestas de la misma página nunca son idénticas byte a byte: cambian el testigo
 * CSRF, el nonce de la CSP, el identificador de Livewire, el contador del carrito y la hora.
 * Comparar el HTML entero produciría una alarma por cada visita, y una alarma que salta
 * siempre se acaba apagando; ese es el modo real en que muere un control de detección.
 *
 * Lo que se compara es el RESUMEN INDEXABLE: título, canónico, meta robots, encabezados y
 * los anfitriones a los que la página enlaza hacia fuera. Es exactamente la superficie que
 * manipulan el cloaking, el secuestro de canónico y la inyección de enlaces, y es estable
 * entre una visita y la siguiente.
 */
class ExtractorIndexable
{
    /**
     * @return array{titulo: string, canonico: string, meta_robots: string, meta_descripcion: string, encabezados: array<int, string>, hosts_enlazados: array<int, string>, enlaces_externos: int, enlaces_sin_nofollow: int, longitud_texto: int, base_href: string}
     */
    public function resumen(string $html, ?string $hostPropio = null): array
    {
        $documento = new DOMDocument('1.0', 'UTF-8');
        $estadoAnterior = libxml_use_internal_errors(true);

        $documento->loadHTML(
            '<?xml encoding="UTF-8" ?>'.$html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($estadoAnterior);

        $xpath = new DOMXPath($documento);

        $hostPropio = $hostPropio !== null ? strtolower($hostPropio) : null;

        $hosts = [];
        $externos = 0;
        $sinNofollow = 0;

        foreach ($xpath->query('//a[@href]') ?: [] as $enlace) {
            if (! $enlace instanceof DOMElement) {
                continue;
            }

            $host = strtolower((string) parse_url($enlace->getAttribute('href'), PHP_URL_HOST));

            if ($host === '' || ($hostPropio !== null && $host === $hostPropio)) {
                continue;
            }

            $externos++;
            $hosts[$host] = true;

            // Un enlace externo sin nofollow cede autoridad de MarketGT al destino. Es el
            // premio que busca quien inyecta enlaces, y por eso se cuenta aparte.
            if (! str_contains(strtolower($enlace->getAttribute('rel')), 'nofollow')) {
                $sinNofollow++;
            }
        }

        $hosts = array_keys($hosts);
        sort($hosts);

        $encabezados = [];

        foreach ($xpath->query('//h1|//h2') ?: [] as $encabezado) {
            $texto = $this->normalizarTexto($encabezado->textContent);

            if ($texto !== '') {
                $encabezados[] = $texto;
            }
        }

        // Se limita el número de encabezados: una página de catálogo con cien productos
        // haría una huella enorme y frágil. Los primeros son los que definen el tema.
        $encabezados = array_slice($encabezados, 0, 12);

        return [
            'titulo' => $this->normalizarTexto($this->primerTexto($xpath, '//title')),
            'canonico' => trim($this->primerAtributo($xpath, '//link[translate(@rel,"CANOICL","canoicl")="canonical"]', 'href')),
            'meta_robots' => strtolower(trim($this->primerAtributo($xpath, '//meta[translate(@name,"ROBTS","robts")="robots"]', 'content'))),
            'meta_descripcion' => $this->normalizarTexto($this->primerAtributo($xpath, '//meta[translate(@name,"DESCRIPTON","descripton")="description"]', 'content')),
            'encabezados' => $encabezados,
            'hosts_enlazados' => $hosts,
            'enlaces_externos' => $externos,
            'enlaces_sin_nofollow' => $sinNofollow,
            'longitud_texto' => mb_strlen($this->normalizarTexto($documento->textContent)),
            // Un <base href> inyectado reescribe todas las rutas relativas de la página
            // hacia el servidor del atacante sin tocar ningún enlace a la vista.
            'base_href' => trim($this->primerAtributo($xpath, '//base', 'href')),
        ];
    }

    /**
     * Huella del resumen.
     *
     * La longitud del texto queda FUERA del cálculo a propósito: cambia cada vez que se
     * edita una descripción o cambia un precio, y eso no es un incidente de seguridad. Lo
     * que entra en la huella es lo que un atacante manipularía y el equipo no toca a diario.
     *
     * @param  array<string, mixed>  $resumen
     */
    public function huella(array $resumen): string
    {
        $comparable = [
            'titulo' => $resumen['titulo'] ?? '',
            'canonico' => $resumen['canonico'] ?? '',
            'meta_robots' => $resumen['meta_robots'] ?? '',
            'encabezados' => $resumen['encabezados'] ?? [],
            'hosts_enlazados' => $resumen['hosts_enlazados'] ?? [],
            'base_href' => $resumen['base_href'] ?? '',
        ];

        return hash('sha256', (string) json_encode($comparable, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Diferencias legibles entre dos resúmenes, para que el incidente diga QUÉ cambió y no
     * solo que algo cambió. Un hash que no coincide no se puede investigar.
     *
     * @param  array<string, mixed>  $esperado
     * @param  array<string, mixed>  $encontrado
     * @return array<int, array{campo: string, esperado: string, encontrado: string}>
     */
    public function diferencias(array $esperado, array $encontrado): array
    {
        $campos = ['titulo', 'canonico', 'meta_robots', 'base_href', 'encabezados', 'hosts_enlazados'];
        $diferencias = [];

        foreach ($campos as $campo) {
            $antes = $esperado[$campo] ?? null;
            $ahora = $encontrado[$campo] ?? null;

            if ($antes === $ahora) {
                continue;
            }

            $diferencias[] = [
                'campo' => $campo,
                'esperado' => $this->aTexto($antes),
                'encontrado' => $this->aTexto($ahora),
            ];
        }

        return $diferencias;
    }

    private function aTexto(mixed $valor): string
    {
        if (is_array($valor)) {
            return implode(', ', array_map(strval(...), $valor));
        }

        return mb_substr((string) $valor, 0, 300);
    }

    private function primerTexto(DOMXPath $xpath, string $consulta): string
    {
        $nodos = $xpath->query($consulta);

        return $nodos !== false && $nodos->length > 0 ? (string) $nodos->item(0)?->textContent : '';
    }

    private function primerAtributo(DOMXPath $xpath, string $consulta, string $atributo): string
    {
        $nodos = $xpath->query($consulta);

        if ($nodos === false || $nodos->length === 0) {
            return '';
        }

        $nodo = $nodos->item(0);

        return $nodo instanceof DOMElement ? $nodo->getAttribute($atributo) : '';
    }

    private function normalizarTexto(string $texto): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }
}
