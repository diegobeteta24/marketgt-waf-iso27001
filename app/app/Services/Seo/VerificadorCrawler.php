<?php

declare(strict_types=1);

namespace App\Services\Seo;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Verificación inversa de rastreadores (FCrDNS: Forward-Confirmed reverse DNS).
 *
 * POR QUÉ ESTA CLASE VIVE EN LA APLICACIÓN Y NO EN EL WAF
 * ---------------------------------------------------------------------------
 * La regla 15021 de ModSecurity compara la dirección contra la lista de rangos que publica
 * Google. Es lo que Google recomienda "a gran escala", pero tiene dos debilidades que a un
 * proyecto pequeño le cuestan caro:
 *
 *   1. La lista caduca. Si nadie la regenera, el Googlebot de verdad empieza a recibir 403
 *      y la tienda desaparece del buscador en días. Aquí el falso positivo cuesta más que
 *      el ataque.
 *   2. libmodsecurity v3 no resuelve DNS dentro de una regla. El operador @rbl no hace
 *      consultas PTR y no hay resolución síncrona disponible. Sencillamente no puede.
 *
 * El método que Google documenta como definitivo son DOS pasos, no uno:
 *
 *   PASO 1 (PTR)     66.249.66.1 -> crawl-66-249-66-1.googlebot.com
 *                    El sufijo debe ser un dominio del buscador, y debe serlo POR ETIQUETA
 *                    COMPLETA: "malgooglebot.com" y "googlebot.com.sitio-del-atacante.tld"
 *                    tienen que fallar, y con str_ends_with a secas el primero pasaría.
 *
 *   PASO 2 (A/AAAA)  crawl-66-249-66-1.googlebot.com -> 66.249.66.1
 *                    Tiene que volver EXACTAMENTE a la dirección original. Sin este
 *                    segundo paso, cualquiera que controle el PTR de su propio rango se
 *                    hace pasar por Googlebot poniéndose ese nombre.
 *
 * Referencia: developers.google.com/search/docs/crawling-indexing/verifying-googlebot
 */
class VerificadorCrawler
{
    /**
     * Dominios que cada buscador reconoce como suyos en el registro PTR.
     *
     * @var array<string, array<int, string>>
     */
    private const DOMINIOS_LEGITIMOS = [
        'googlebot' => ['googlebot.com', 'google.com', 'googleusercontent.com'],
        'bingbot' => ['search.msn.com'],
        'applebot' => ['applebot.apple.com'],
        'duckduckbot' => ['duckduckgo.com'],
        'yandexbot' => ['yandex.ru', 'yandex.net', 'yandex.com'],
    ];

    /**
     * Agentes que dicen ser un rastreador. Si el agente no cae aquí no hay nada que
     * verificar: es tráfico normal y esta clase no tiene opinión sobre él.
     */
    private const PATRON_AGENTE = '/\b(googlebot|storebot-google|google-inspectiontool|googleother|bingbot|adidxbot|applebot|duckduckbot|yandexbot)\b/i';

    /** Un veredicto positivo se puede sostener una hora: los rangos del buscador son estables. */
    private const TTL_VERIFICADO = 3600;

    /**
     * Un veredicto negativo caduca antes. Si el servidor DNS estaba caído durante la
     * consulta, el Googlebot legítimo quedaría bloqueado una hora entera por un fallo que
     * no era suyo. Cinco minutos es suficiente para frenar una campaña y corto para
     * recuperarse de un fallo de resolución.
     */
    private const TTL_RECHAZADO = 300;

    /** Ninguna consulta DNS puede dejar colgada una petición de un cliente real. */
    private const TIMEOUT_DNS = 2;

    /**
     * Veredicto completo sobre una petición.
     *
     * @return array{ip: string, declara_ser_bot: bool, familia: string|null, verificado: bool, motivo: string, ptr: string|null}
     */
    public function verificar(?string $ip, ?string $agenteUsuario): array
    {
        $ip = $ip ?? '';
        $agenteUsuario ??= '';

        if ($ip === '' || preg_match(self::PATRON_AGENTE, $agenteUsuario, $coincidencia) !== 1) {
            return $this->veredicto($ip, false, null, false, 'no_declara_ser_rastreador', null);
        }

        $familia = $this->familiaDe(strtolower($coincidencia[1]));

        // Una dirección privada, de bucle o reservada no puede pertenecer a un buscador.
        // Se resuelve antes de tocar el DNS porque el PTR de 127.0.0.1 es "localhost" y la
        // comprobación de sufijo lo rechazaría igual, pero con una consulta de más.
        if (! $this->esDireccionEnrutable($ip)) {
            return $this->veredicto($ip, true, $familia, false, 'direccion_no_enrutable', null);
        }

        $clave = 'seo:fcrdns:'.$familia.':'.$ip;

        /** @var array{ip: string, declara_ser_bot: bool, familia: string|null, verificado: bool, motivo: string, ptr: string|null}|null $enCache */
        $enCache = Cache::get($clave);

        if (is_array($enCache)) {
            return $enCache;
        }

        $resultado = $this->resolverVeredicto($ip, $familia);

        try {
            Cache::put(
                $clave,
                $resultado,
                $resultado['verificado'] ? self::TTL_VERIFICADO : self::TTL_RECHAZADO,
            );
        } catch (Throwable) {
            // Sin caché el control sigue siendo correcto, solo más lento. Que el
            // almacén de caché falle no puede impedir que se verifique al rastreador.
        }

        return $resultado;
    }

    /** Atajo para una vista o una condición: ¿es un rastreador legítimo y comprobado? */
    public function esRastreadorLegitimo(?string $ip, ?string $agenteUsuario): bool
    {
        return $this->verificar($ip, $agenteUsuario)['verificado'];
    }

    /**
     * @return array{ip: string, declara_ser_bot: bool, familia: string|null, verificado: bool, motivo: string, ptr: string|null}
     */
    private function resolverVeredicto(string $ip, string $familia): array
    {
        $ptr = $this->resolverPtr($ip);

        if ($ptr === null) {
            return $this->veredicto($ip, true, $familia, false, 'sin_registro_ptr', null);
        }

        if (! $this->sufijoPermitido($ptr, $familia)) {
            // Aquí cae casi todo el Googlebot falso: el PTR de un servidor virtual barato.
            return $this->veredicto($ip, true, $familia, false, 'ptr_no_pertenece_al_buscador', $ptr);
        }

        if (! $this->resuelveDeVueltaA($ptr, $ip)) {
            return $this->veredicto($ip, true, $familia, false, 'dns_directo_no_confirma', $ptr);
        }

        return $this->veredicto($ip, true, $familia, true, 'fcrdns_correcto', $ptr);
    }

    private function familiaDe(string $agente): string
    {
        return match (true) {
            str_contains($agente, 'google') => 'googlebot',
            str_contains($agente, 'bingbot'), str_contains($agente, 'adidxbot') => 'bingbot',
            str_contains($agente, 'applebot') => 'applebot',
            str_contains($agente, 'duckduckbot') => 'duckduckbot',
            str_contains($agente, 'yandex') => 'yandexbot',
            default => 'googlebot',
        };
    }

    /**
     * Rechaza direcciones privadas y reservadas. La bandera de rango reservado incluye
     * 127.0.0.0/8, 10/8, 172.16/12, 192.168/16, 169.254/16 y sus equivalentes en IPv6.
     */
    private function esDireccionEnrutable(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /** Dirección a nombre. gethostbyaddr devuelve la propia dirección cuando no hay PTR. */
    private function resolverPtr(string $ip): ?string
    {
        $anterior = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', (string) self::TIMEOUT_DNS);

        try {
            $ptr = @gethostbyaddr($ip);
        } catch (Throwable) {
            return null;
        } finally {
            ini_set('default_socket_timeout', $anterior === false ? '60' : $anterior);
        }

        if ($ptr === false || $ptr === '' || $ptr === $ip) {
            return null;
        }

        return rtrim(strtolower($ptr), '.');
    }

    /**
     * Comparación de sufijo POR ETIQUETA, no por cadena.
     *
     * Es el detalle que casi todas las implementaciones hacen mal:
     *   str_ends_with('malgooglebot.com', 'googlebot.com')          -> true  (incorrecto)
     *   str_contains('x.googlebot.com.atacante.tld', 'googlebot.com') -> true  (incorrecto)
     * Exigiendo el punto delante, ambos casos se caen.
     */
    private function sufijoPermitido(string $host, string $familia): bool
    {
        foreach (self::DOMINIOS_LEGITIMOS[$familia] ?? [] as $dominio) {
            if ($host === $dominio || str_ends_with($host, '.'.$dominio)) {
                return true;
            }
        }

        return false;
    }

    /** Nombre a dirección. Tiene que devolver exactamente la dirección que se consultó. */
    private function resuelveDeVueltaA(string $host, string $ip): bool
    {
        try {
            if (str_contains($ip, ':')) {
                $registros = @dns_get_record($host, DNS_AAAA);

                foreach ($registros === false ? [] : $registros as $registro) {
                    if (isset($registro['ipv6']) && $this->mismaDireccion((string) $registro['ipv6'], $ip)) {
                        return true;
                    }
                }

                return false;
            }

            $direcciones = @gethostbynamel($host);

            return is_array($direcciones) && in_array($ip, $direcciones, true);
        } catch (Throwable) {
            return false;
        }
    }

    /** Normaliza antes de comparar: 2001:4860:0:0::1 y 2001:4860::1 son la misma. */
    private function mismaDireccion(string $primera, string $segunda): bool
    {
        $a = @inet_pton($primera);
        $b = @inet_pton($segunda);

        return $a !== false && $b !== false && $a === $b;
    }

    /**
     * @return array{ip: string, declara_ser_bot: bool, familia: string|null, verificado: bool, motivo: string, ptr: string|null}
     */
    private function veredicto(string $ip, bool $declara, ?string $familia, bool $verificado, string $motivo, ?string $ptr): array
    {
        return [
            'ip' => $ip,
            'declara_ser_bot' => $declara,
            'familia' => $familia,
            'verificado' => $verificado,
            'motivo' => $motivo,
            'ptr' => $ptr,
        ];
    }
}
