<?php

// =============================================================================
// MarketGT - Verificacion inversa de crawlers (FCrDNS)
//
// DESTINO: app/Services/Seguridad/VerificadorCrawler.php
//
// POR QUE ESTA CLASE EXISTE, Y POR QUE NO BASTA EL WAF
// ---------------------------------------------------------------------------
// La regla 15021 del WAF compara la IP contra la lista de rangos publicada por
// Google. Es el metodo que el propio Google recomienda "a gran escala", pero
// tiene dos debilidades:
//   1. La lista cambia. Si no se regenera, el Googlebot REAL acaba con 403 y el
//      sitio se desindexa. El falso positivo cuesta mas que el ataque.
//   2. ModSecurity v3 NO puede resolver DNS: el operador @rbl no hace consultas
//      PTR y libmodsecurity no permite resolucion sincrona dentro de una regla.
//
// El metodo que Google documenta como definitivo es FCrDNS
// (Forward-Confirmed reverse DNS), y son DOS pasos, no uno:
//
//   PASO 1 (PTR)  IP -> nombre.       66.249.66.1 -> crawl-66-249-66-1.googlebot.com
//                 El sufijo tiene que ser googlebot.com, google.com o
//                 googleusercontent.com. Y tiene que ser SUFIJO DE ETIQUETA
//                 COMPLETA: "googlebot.com.evil.tld" NO vale, y str_ends_with
//                 a secas tampoco sirve porque "malgooglebot.com" pasaria.
//
//   PASO 2 (A/AAAA) nombre -> IP.     Tiene que volver EXACTAMENTE a la IP
//                 original. Sin este segundo paso, cualquiera que controle el
//                 PTR de su propio rango se hace pasar por Googlebot.
//
// Fuente: https://developers.google.com/search/docs/crawling-indexing/verifying-googlebot
// =============================================================================

namespace App\Services\Seguridad;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class VerificadorCrawler
{
    /**
     * Dominios que Google reconoce como suyos en el PTR.
     * Bing usa search.msn.com. Cada entrada es un sufijo de etiqueta completa.
     */
    private const DOMINIOS_LEGITIMOS = [
        'googlebot'  => ['googlebot.com', 'google.com', 'googleusercontent.com'],
        'bingbot'    => ['search.msn.com'],
        'applebot'   => ['applebot.apple.com'],
        'duckduckbot' => ['duckduckgo.com'],
    ];

    /**
     * User-Agents que dicen ser un crawler. Si el UA no cae aqui, no hay nada
     * que verificar: es trafico normal.
     */
    private const PATRON_UA = '/\b(googlebot|storebot-google|google-inspectiontool|googleother|bingbot|adidxbot|applebot|duckduckbot)\b/i';

    /** El PTR tarda. Cacheamos el veredicto por IP para no pagarlo dos veces. */
    private const TTL_CACHE = 3600;   // 1 hora
    private const TIMEOUT_DNS = 2;    // segundos; nunca dejar colgada una peticion

    /**
     * Devuelve el veredicto completo de una peticion.
     *
     * @return array{declara_ser_bot:bool, familia:?string, verificado:bool,
     *               motivo:string, ptr:?string, ip:string}
     */
    public function verificar(string $ip, ?string $userAgent): array
    {
        $userAgent = $userAgent ?? '';

        if (!preg_match(self::PATRON_UA, $userAgent, $m)) {
            return $this->resultado($ip, false, null, false, 'no_declara_ser_crawler', null);
        }

        $familia = $this->familiaDe(strtolower($m[1]));

        // Cache por IP + familia: el veredicto no depende del resto del UA.
        $clave = 'crawler:fcrdns:' . $familia . ':' . $ip;

        return Cache::remember($clave, self::TTL_CACHE, function () use ($ip, $familia, $userAgent) {

            // -------- PASO 1: PTR (IP -> nombre) --------
            $ptr = $this->resolverPtr($ip);

            if ($ptr === null || $ptr === $ip) {
                return $this->resultado($ip, true, $familia, false, 'sin_registro_ptr', null);
            }

            if (!$this->sufijoPermitido($ptr, $familia)) {
                // Aqui cae el 99 % del Googlebot falso: PTR de un VPS cualquiera.
                $this->registrarSospecha($ip, $userAgent, $ptr, 'ptr_no_pertenece_al_buscador');
                return $this->resultado($ip, true, $familia, false, 'ptr_no_pertenece_al_buscador', $ptr);
            }

            // -------- PASO 2: DNS directo (nombre -> IP) --------
            // Sin este paso, quien controle el PTR de su rango se hace pasar por
            // Googlebot poniendo "crawl-1-2-3-4.googlebot.com" como PTR propio.
            if (!$this->resuelveDeVueltaA($ptr, $ip)) {
                $this->registrarSospecha($ip, $userAgent, $ptr, 'dns_directo_no_confirma');
                return $this->resultado($ip, true, $familia, false, 'dns_directo_no_confirma', $ptr);
            }

            return $this->resultado($ip, true, $familia, true, 'fcrdns_ok', $ptr);
        });
    }

    /** Atajo booleano para usar en un middleware o en una vista. */
    public function esCrawlerLegitimo(string $ip, ?string $userAgent): bool
    {
        return $this->verificar($ip, $userAgent)['verificado'] === true;
    }

    // -------------------------------------------------------------------------
    // Internos
    // -------------------------------------------------------------------------

    private function familiaDe(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'google')      => 'googlebot',
            str_contains($ua, 'bingbot'),
            str_contains($ua, 'adidxbot')    => 'bingbot',
            str_contains($ua, 'applebot')    => 'applebot',
            str_contains($ua, 'duckduckbot') => 'duckduckbot',
            default                          => 'googlebot',
        };
    }

    /** IP -> nombre. gethostbyaddr devuelve la propia IP si no hay PTR. */
    private function resolverPtr(string $ip): ?string
    {
        $anterior = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', (string) self::TIMEOUT_DNS);

        try {
            $ptr = @gethostbyaddr($ip);
        } finally {
            ini_set('default_socket_timeout', (string) $anterior);
        }

        if ($ptr === false || $ptr === '' || $ptr === $ip) {
            return null;
        }

        return rtrim(strtolower($ptr), '.');
    }

    /**
     * Comprobacion de sufijo POR ETIQUETA, no por cadena.
     *
     * Este es el detalle que casi todo el mundo hace mal:
     *   str_ends_with('malgooglebot.com', 'googlebot.com')        -> true  (MAL)
     *   str_contains('x.googlebot.com.evil.tld', 'googlebot.com') -> true  (MAL)
     * Exigiendo el punto delante, ambos casos se caen.
     */
    private function sufijoPermitido(string $host, string $familia): bool
    {
        foreach (self::DOMINIOS_LEGITIMOS[$familia] ?? [] as $dominio) {
            if ($host === $dominio || str_ends_with($host, '.' . $dominio)) {
                return true;
            }
        }

        return false;
    }

    /** nombre -> IP. Tiene que devolver EXACTAMENTE la IP que pidio. */
    private function resuelveDeVueltaA(string $host, string $ip): bool
    {
        $esIpv6 = str_contains($ip, ':');

        if ($esIpv6) {
            $registros = @dns_get_record($host, DNS_AAAA) ?: [];
            foreach ($registros as $r) {
                if (isset($r['ipv6']) && $this->mismaIp($r['ipv6'], $ip)) {
                    return true;
                }
            }
            return false;
        }

        $ips = @gethostbynamel($host);

        return is_array($ips) && in_array($ip, $ips, true);
    }

    /** Normaliza IPv6 antes de comparar: 2001:4860:0:0::1 == 2001:4860::1 */
    private function mismaIp(string $a, string $b): bool
    {
        return @inet_pton($a) !== false
            && @inet_pton($a) === @inet_pton($b);
    }

    /**
     * Manda el evento al canal que lee el panel SIEM.
     * El canal 'seguridad' se declara en config/logging.php (ver mas abajo).
     */
    private function registrarSospecha(string $ip, string $ua, ?string $ptr, string $motivo): void
    {
        Log::channel('seguridad')->warning('crawler_falsificado', [
            'evento'     => 'SEO/CRAWLER-FALSO',
            'severidad'  => 'CRITICAL',
            'ip'         => $ip,
            'user_agent' => $ua,
            'ptr'        => $ptr,
            'motivo'     => $motivo,
            'regla'      => 'APP-15021',      // hermana de la regla 15021 del WAF
            'iso27001'   => 'A.8.16',
            'ts'         => now()->toIso8601String(),
        ]);
    }

    private function resultado(string $ip, bool $declara, ?string $familia, bool $ok, string $motivo, ?string $ptr): array
    {
        return [
            'ip'              => $ip,
            'declara_ser_bot' => $declara,
            'familia'         => $familia,
            'verificado'      => $ok,
            'motivo'          => $motivo,
            'ptr'             => $ptr,
        ];
    }
}

// =============================================================================
// COMPROBACION MANUAL, LA MISMA QUE HACE LA CLASE (util en la demo del sabado)
// -----------------------------------------------------------------------------
//   # Paso 1: PTR
//   host 66.249.66.1
//     -> 1.66.249.66.in-addr.arpa domain name pointer crawl-66-249-66-1.googlebot.com.
//   # Paso 2: DNS directo, tiene que volver a la misma IP
//   host crawl-66-249-66-1.googlebot.com
//     -> crawl-66-249-66-1.googlebot.com has address 66.249.66.1   <- CONFIRMADO
//
//   # Contraste con una IP cualquiera que diga ser Googlebot:
//   host 45.79.0.1   -> PTR de Linode, no de googlebot.com  -> FALSO
//
// En PowerShell (Windows 10, sin WSL):
//   Resolve-DnsName -Type PTR 1.66.249.66.in-addr.arpa
//   Resolve-DnsName crawl-66-249-66-1.googlebot.com
// =============================================================================
