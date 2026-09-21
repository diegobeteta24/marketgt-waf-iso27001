<?php

// =============================================================================
// MarketGT - Vigilancia de integridad de los artefactos de indexacion
//
// DESTINO: app/Console/Commands/VigilarIntegridadSeo.php
// PROGRAMACION (routes/console.php):
//     Schedule::command('seo:vigilar')->everyFiveMinutes();
//
// QUE PROBLEMA RESUELVE
// ---------------------------------------------------------------------------
// El WAF (regla 15050) impide ESCRIBIR robots.txt por HTTP. Pero si el atacante
// entra por otra puerta -- credenciales de FTP, un contenedor comprometido, un
// commit malicioso, un plugin -- el WAF no lo ve, porque el cambio no viaja por
// HTTP. Esto es exactamente el vertice de DETECCION del Triangulo de la
// Ciberresiliencia: asumir que la proteccion puede fallar y notarlo igual.
//
// Un "Disallow: /" colado en robots.txt desindexa la tienda entera en dias y
// nadie se entera hasta que caen las ventas. Cinco minutos de deteccion contra
// varios dias: eso es lo que mide la metrica MTTD del proyecto.
//
// ISO/IEC 27001:2022 -> A.8.9 (gestion de configuraciones), A.8.16 (actividades
// de seguimiento), A.8.32 (gestion de cambios).
// =============================================================================

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class VigilarIntegridadSeo extends Command
{
    protected $signature = 'seo:vigilar {--sellar : Guarda el estado actual como linea base}';

    protected $description = 'Verifica que robots.txt, sitemap.xml y los canonical no han cambiado sin autorizacion';

    /** Ficheros publicos cuya integridad se vigila. */
    private const VIGILADOS = [
        'robots.txt',
        'sitemap.xml',
        'ads.txt',
    ];

    /**
     * Frases que NUNCA deben aparecer en robots.txt de MarketGT.
     * "Disallow: /" a secas desindexa el sitio completo.
     */
    private const PROHIBIDO_EN_ROBOTS = [
        '/^\s*Disallow:\s*\/\s*$/mi',
        '/^\s*User-agent:\s*\*\s*$\s*^\s*Disallow:\s*\/\s*$/mi',
        '/\bnoindex\b/i',
    ];

    public function handle(): int
    {
        $incidencias = 0;

        foreach (self::VIGILADOS as $nombre) {
            $ruta = public_path($nombre);

            if (!is_file($ruta)) {
                // Un robots.txt que DESAPARECE tambien es una incidencia.
                if ($nombre === 'robots.txt') {
                    $this->alertar($nombre, 'fichero_ausente', null, null);
                    $incidencias++;
                }
                continue;
            }

            $contenido = (string) file_get_contents($ruta);
            $hash      = hash('sha256', $contenido);
            $clave     = 'seo:integridad:' . $nombre;

            if ($this->option('sellar')) {
                Cache::forever($clave, $hash);
                $this->info("Linea base sellada  {$nombre}  sha256={$hash}");
                continue;
            }

            $esperado = Cache::get($clave);

            if ($esperado === null) {
                Cache::forever($clave, $hash);
                $this->warn("Sin linea base para {$nombre}; se sella la actual.");
                continue;
            }

            if (!hash_equals($esperado, $hash)) {
                $this->alertar($nombre, 'hash_distinto', $esperado, $hash);
                $this->error("CAMBIO NO AUTORIZADO en {$nombre}");
                $incidencias++;
            }

            // Comprobacion semantica, no solo de hash: aunque el hash estuviera
            // "sellado" por el atacante, un Disallow: / sigue siendo una alarma.
            if ($nombre === 'robots.txt') {
                foreach (self::PROHIBIDO_EN_ROBOTS as $patron) {
                    if (preg_match($patron, $contenido)) {
                        $this->alertar($nombre, 'directiva_peligrosa', null, $patron);
                        $this->error("robots.txt contiene una directiva que desindexaria el sitio");
                        $incidencias++;
                        break;
                    }
                }
            }
        }

        // -- Canonical de la portada: tiene que apuntar a nuestro propio host --
        $incidencias += $this->verificarCanonicalPortada();

        if ($incidencias === 0) {
            $this->info('Integridad SEO correcta.');
        }

        return $incidencias === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Pide la portada a la propia aplicacion y comprueba que el
     * <link rel="canonical"> no ha sido reescrito hacia un dominio ajeno.
     * Un canonical secuestrado le dice a Google que la version buena de tu
     * pagina esta en el dominio del atacante: te roba el posicionamiento sin
     * tocar una sola letra visible de tu contenido.
     */
    private function verificarCanonicalPortada(): int
    {
        $html = @file_get_contents(config('app.url'), false, stream_context_create([
            'http' => ['timeout' => 5, 'header' => "User-Agent: MarketGT-IntegridadSEO/1.0\r\n"],
        ]));

        if ($html === false) {
            return 0;   // la portada no responde: lo reporta otra sonda
        }

        if (!preg_match('#<link[^>]+rel=["\']?canonical["\']?[^>]*href=["\']([^"\']+)#i', $html, $m)) {
            return 0;
        }

        $hostCanonical = parse_url($m[1], PHP_URL_HOST);
        $hostPropio    = parse_url(config('app.url'), PHP_URL_HOST);

        if ($hostCanonical !== null && $hostCanonical !== $hostPropio) {
            $this->alertar('canonical', 'canonical_secuestrado', $hostPropio, $hostCanonical);
            $this->error("Canonical apunta a {$hostCanonical}, no a {$hostPropio}");
            return 1;
        }

        return 0;
    }

    private function alertar(string $artefacto, string $motivo, ?string $esperado, ?string $encontrado): void
    {
        Log::channel('seguridad')->critical('integridad_seo_rota', [
            'evento'     => 'SEO/INTEGRIDAD',
            'severidad'  => 'CRITICAL',
            'artefacto'  => $artefacto,
            'motivo'     => $motivo,
            'esperado'   => $esperado,
            'encontrado' => $encontrado,
            'regla'      => 'APP-15050',
            'iso27001'   => 'A.8.9,A.8.16,A.8.32',
            'ts'         => now()->toIso8601String(),
        ]);
    }
}
