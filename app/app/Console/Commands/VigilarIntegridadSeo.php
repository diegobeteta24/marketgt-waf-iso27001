<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\IncidenteSeo;
use App\Models\LineaBaseSeo;
use App\Services\Seo\ExtractorIndexable;
use App\Services\Seo\GeneradorSitemap;
use App\Services\Seo\RegistroIncidentesSeo;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Vigilancia de integridad de los artefactos de indexación.
 *
 * QUÉ PROBLEMA RESUELVE, Y POR QUÉ NO LO RESUELVE EL WAF
 * ---------------------------------------------------------------------------
 * La regla 15050 de ModSecurity impide ESCRIBIR robots.txt por HTTP. Pero si el atacante
 * entra por otra puerta —credenciales de SSH, un contenedor comprometido, una dependencia
 * con puerta trasera, un commit malicioso— el WAF no ve nada, porque el cambio no viaja por
 * HTTP. Este comando es el vértice de DETECCIÓN del triángulo de ciberresiliencia: se asume
 * que la protección puede fallar y se detecta igual.
 *
 * Un "Disallow: /" colado en robots.txt desindexa la tienda entera en días y nadie se entera
 * hasta que caen las ventas. Cinco minutos de detección frente a varios días de ceguera: eso
 * es lo que mide la métrica de tiempo medio de detección del proyecto.
 *
 * ISO/IEC 27001:2022 -> A.8.9 (gestión de configuraciones), A.8.16 (seguimiento de
 * actividades), A.8.32 (gestión de cambios).
 *
 * Programación sugerida (routes/console.php, lo hace el integrador):
 *     Schedule::command('seo:vigilar')->everyFiveMinutes();
 */
class VigilarIntegridadSeo extends Command
{
    protected $signature = 'seo:vigilar
        {--sellar : Guarda el estado actual como línea base autorizada}
        {--nota= : Justificación del sellado, para el registro de cambios}
        {--sin-red : Omite las comprobaciones que necesitan pedir páginas por HTTP}';

    protected $description = 'Compara robots.txt, sitemap.xml, canónicos y meta robots contra la línea base autorizada';

    /**
     * Directivas que nunca deben aparecer en el robots.txt de MarketGT.
     *
     * La comprobación es semántica y no solo de huella a propósito: si el atacante consigue
     * además sellar la línea base, la huella coincidiría y solo esto delataría el ataque.
     *
     * @var array<string, array{patron: string, explicacion: string}>
     */
    private const DIRECTIVAS_PROHIBIDAS = [
        'bloqueo_total' => [
            'patron' => '/^\s*Disallow:\s*\/\s*$/mi',
            'explicacion' => 'Un "Disallow: /" desindexa la tienda completa en cuestión de días',
        ],
        'noindex_en_robots' => [
            'patron' => '/^\s*Noindex:/mi',
            'explicacion' => 'Directiva Noindex en robots.txt: no es estándar y varios rastreadores la obedecen',
        ],
        'sitemap_ajeno' => [
            'patron' => '/^\s*Sitemap:\s*https?:\/\/(?!(?:[a-z0-9-]+\.)*marketgt\.gt)/mi',
            'explicacion' => 'Se declara un sitemap alojado en un dominio que no es MarketGT',
        ],
    ];

    /** Páginas cuya superficie indexable se vigila. */
    private const PAGINAS_VIGILADAS = ['/', '/tienda'];

    private int $incidencias = 0;

    private int $sellados = 0;

    /** @var array<int, array{artefacto: string, estado: string, detalle: string}> */
    private array $filas = [];

    public function handle(
        ExtractorIndexable $extractor,
        GeneradorSitemap $sitemap,
        RegistroIncidentesSeo $registro,
    ): int {
        if (! Schema::hasTable('lineas_base_seo')) {
            $this->error('Falta la tabla lineas_base_seo. Ejecute primero las migraciones del componente.');

            return self::FAILURE;
        }

        $this->line('Vigilancia de integridad de posicionamiento · '.Carbon::now()->format('d/m/Y H:i:s'));
        $this->newLine();

        $this->vigilarRobots($registro);
        $this->vigilarSitemap($sitemap, $registro);

        if (! $this->option('sin-red')) {
            $this->vigilarPaginas($extractor, $registro);
        }

        $this->newLine();
        $this->table(['Artefacto', 'Estado', 'Detalle'], $this->filas);

        if ($this->option('sellar')) {
            $this->info($this->sellados.' artefacto(s) sellados como línea base autorizada.');

            return self::SUCCESS;
        }

        if ($this->incidencias === 0) {
            $this->info('Integridad de posicionamiento correcta.');

            return self::SUCCESS;
        }

        $this->error($this->incidencias.' incidencia(s) registradas en incidentes_seo y en la bitácora de seguridad.');

        return self::FAILURE;
    }

    private function vigilarRobots(RegistroIncidentesSeo $registro): void
    {
        $ruta = public_path('robots.txt');

        if (! is_file($ruta)) {
            // Un robots.txt que desaparece también es un incidente: sin él, el rastreador
            // entra a todas partes, incluidos los resultados de búsqueda interna.
            $this->alertar($registro, LineaBaseSeo::ARTEFACTO_ROBOTS, 'archivo_ausente', [
                'explicacion' => 'public/robots.txt no existe',
            ]);

            return;
        }

        $contenido = (string) file_get_contents($ruta);
        $huella = hash('sha256', $contenido);

        foreach (self::DIRECTIVAS_PROHIBIDAS as $nombre => $definicion) {
            if (preg_match($definicion['patron'], $contenido, $coincidencia) === 1) {
                $this->alertar($registro, LineaBaseSeo::ARTEFACTO_ROBOTS, 'directiva_peligrosa', [
                    'regla' => $nombre,
                    'explicacion' => $definicion['explicacion'],
                    'linea' => trim((string) $coincidencia[0]),
                ]);
            }
        }

        $this->compararConLineaBase(
            $registro,
            LineaBaseSeo::ARTEFACTO_ROBOTS,
            $huella,
            ['bytes' => strlen($contenido), 'contenido' => $contenido],
            url('/robots.txt'),
        );
    }

    private function vigilarSitemap(GeneradorSitemap $sitemap, RegistroIncidentesSeo $registro): void
    {
        try {
            $entradas = $sitemap->entradas();
        } catch (Throwable $error) {
            $this->fila(LineaBaseSeo::ARTEFACTO_SITEMAP, 'sin datos', 'No se pudo generar: '.$error->getMessage());

            return;
        }

        $direcciones = array_column($entradas, 'loc');
        sort($direcciones);

        $propio = strtolower((string) parse_url(url('/'), PHP_URL_HOST));

        $ajenas = array_values(array_filter(
            $direcciones,
            static fn (string $url): bool => strtolower((string) parse_url($url, PHP_URL_HOST)) !== $propio,
        ));

        if ($ajenas !== []) {
            // El generador ya filtra las direcciones ajenas, así que llegar aquí significa
            // que el filtro se saltó: se registra igual, porque un control que confía en
            // otro control sin verificarlo no es defensa en profundidad.
            $this->alertar($registro, LineaBaseSeo::ARTEFACTO_SITEMAP, 'urls_ajenas', [
                'explicacion' => 'El sitemap declara direcciones fuera del dominio propio',
                'urls' => array_slice($ajenas, 0, 20),
            ], IncidenteSeo::TIPO_SITEMAP_AJENO);
        }

        $this->compararConLineaBase(
            $registro,
            LineaBaseSeo::ARTEFACTO_SITEMAP,
            hash('sha256', implode("\n", $direcciones)),
            ['cantidad_urls' => count($direcciones), 'urls' => array_slice($direcciones, 0, 100)],
            url('/sitemap.xml'),
        );
    }

    private function vigilarPaginas(ExtractorIndexable $extractor, RegistroIncidentesSeo $registro): void
    {
        $base = rtrim((string) config('app.url'), '/');
        $hostPropio = strtolower((string) parse_url($base, PHP_URL_HOST));

        foreach (self::PAGINAS_VIGILADAS as $ruta) {
            $url = $base.($ruta === '/' ? '/' : $ruta);
            $artefacto = LineaBaseSeo::PREFIJO_PAGINA.$ruta;

            try {
                $respuesta = Http::withHeaders([
                    // Se pide como una persona, no como un rastreador: lo que se vigila es
                    // la versión pública, que es la que ve cualquier visitante.
                    'User-Agent' => 'MarketGT-IntegridadSEO/1.0',
                ])->timeout(5)->get($url);
            } catch (Throwable $error) {
                // Que la tienda no responda es un problema de disponibilidad y lo reporta
                // otra sonda. Aquí solo se deja constancia para no confundirlo con "todo bien".
                $this->fila($artefacto, 'sin respuesta', substr($error->getMessage(), 0, 60));

                continue;
            }

            if (! $respuesta->successful()) {
                $this->fila($artefacto, 'sin respuesta', 'HTTP '.$respuesta->status());

                continue;
            }

            $resumen = $extractor->resumen($respuesta->body(), $hostPropio);

            // Canónico secuestrado: le dice a Google que la versión buena de esta página
            // está en el dominio del atacante. Roba el posicionamiento sin tocar una sola
            // letra visible del contenido, así que a simple vista la página está perfecta.
            $hostCanonico = strtolower((string) parse_url($resumen['canonico'], PHP_URL_HOST));

            if ($resumen['canonico'] !== '' && $hostCanonico !== '' && $hostCanonico !== $hostPropio) {
                $this->alertar($registro, $artefacto, 'canonico_secuestrado', [
                    'explicacion' => 'El canónico apunta a otro dominio',
                    'esperado' => $hostPropio,
                    'encontrado' => $hostCanonico,
                    'canonico' => $resumen['canonico'],
                ]);
            }

            if ($resumen['base_href'] !== '') {
                $this->alertar($registro, $artefacto, 'base_href_presente', [
                    'explicacion' => 'Un <base href> reescribe todas las rutas relativas de la página; MarketGT no lo usa',
                    'encontrado' => $resumen['base_href'],
                ]);
            }

            if (str_contains($resumen['meta_robots'], 'noindex')) {
                $this->alertar($registro, $artefacto, 'noindex_inesperado', [
                    'explicacion' => 'Una página que debe indexarse está marcada como noindex: desindexación silenciosa',
                    'meta_robots' => $resumen['meta_robots'],
                ]);
            }

            if ($resumen['enlaces_sin_nofollow'] > 0) {
                $this->alertar($registro, $artefacto, 'enlaces_salientes_sin_nofollow', [
                    'explicacion' => 'Hay enlaces externos que ceden autoridad; en MarketGT todo enlace externo lleva nofollow',
                    'cantidad' => $resumen['enlaces_sin_nofollow'],
                    'hosts' => $resumen['hosts_enlazados'],
                ]);
            }

            $this->compararConLineaBase($registro, $artefacto, $extractor->huella($resumen), $resumen, $url, $extractor);
        }
    }

    /**
     * @param  array<string, mixed>  $resumen
     */
    private function compararConLineaBase(
        RegistroIncidentesSeo $registro,
        string $artefacto,
        string $huella,
        array $resumen,
        string $url,
        ?ExtractorIndexable $extractor = null,
    ): void {
        $linea = LineaBaseSeo::query()->where('artefacto', $artefacto)->first();

        if ($this->option('sellar') || ! $linea instanceof LineaBaseSeo) {
            $primera = ! $linea instanceof LineaBaseSeo;

            LineaBaseSeo::query()->updateOrCreate(
                ['artefacto' => $artefacto],
                [
                    'url' => $url,
                    'huella' => $huella,
                    'resumen' => $resumen,
                    'sellada_en' => Carbon::now(),
                    'notas' => (string) ($this->option('nota') ?: ($primera ? 'Sellado automático en la primera ejecución' : 'Sellado manual desde la consola')),
                ],
            );

            $this->sellados++;
            $this->fila($artefacto, $primera ? 'línea base creada' : 'sellado', substr($huella, 0, 16));

            return;
        }

        if (hash_equals($linea->huella, $huella)) {
            $this->fila($artefacto, 'íntegro', substr($huella, 0, 16));

            return;
        }

        $detalle = [
            'explicacion' => 'El artefacto cambió respecto de la línea base autorizada',
            'huella_esperada' => $linea->huella,
            'huella_encontrada' => $huella,
            'sellada_en' => $linea->sellada_en->toIso8601String(),
        ];

        if ($extractor !== null) {
            $detalle['diferencias'] = $extractor->diferencias($linea->resumen ?? [], $resumen);
        }

        $this->alertar($registro, $artefacto, 'huella_distinta', $detalle);
    }

    /**
     * @param  array<string, mixed>  $detalle
     */
    private function alertar(
        RegistroIncidentesSeo $registro,
        string $artefacto,
        string $motivo,
        array $detalle,
        string $tipo = IncidenteSeo::TIPO_INTEGRIDAD,
    ): void {
        $this->incidencias++;

        $registro->registrar(
            $tipo,
            'Integridad de '.$artefacto.': '.$motivo,
            [
                'ruta' => $artefacto,
                'agrupar_por' => $artefacto.':'.$motivo,
                'detalle' => array_merge(['artefacto' => $artefacto, 'motivo' => $motivo], $detalle),
            ],
        );

        $this->fila($artefacto, 'ALERTA', $motivo.' · '.((string) ($detalle['explicacion'] ?? '')));
    }

    private function fila(string $artefacto, string $estado, string $detalle): void
    {
        $this->filas[] = [
            'artefacto' => $artefacto,
            'estado' => $estado,
            'detalle' => mb_substr($detalle, 0, 80),
        ];
    }
}
