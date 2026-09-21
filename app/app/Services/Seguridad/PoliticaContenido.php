<?php

declare(strict_types=1);

namespace App\Services\Seguridad;

use Illuminate\Support\Facades\Vite;

/**
 * Construye la política de seguridad de contenido (CSP) de cada petición.
 *
 * Vive fuera del middleware porque la política es la parte que hay que poder leer,
 * discutir y probar por separado; el middleware solo la pega en la respuesta.
 */
class PoliticaContenido
{
    public const MODO_APLICAR = 'aplicar';

    public const MODO_REPORTAR = 'reportar';

    public const MODO_DESACTIVADA = 'desactivada';

    /**
     * Genera el nonce de la petición y lo deja registrado en Vite.
     *
     * Hay que llamarlo ANTES de que se renderice la vista: Vite y Livewire leen
     * Vite::cspNonce() al emitir sus etiquetas <script>, y si el nonce nace después
     * las etiquetas salen sin él y la propia aplicación se autobloquea.
     */
    public function generarNonce(): string
    {
        return Vite::useCspNonce();
    }

    /**
     * Modo efectivo: lo que diga la configuración y, si no dice nada, aplicar en
     * producción y reportar fuera de ella.
     */
    public function modo(): string
    {
        /** @var string|null $configurado */
        $configurado = config('seguridad.cabeceras.politica_contenido.modo');

        if (is_string($configurado) && $configurado !== '') {
            return $configurado;
        }

        return app()->isProduction() ? self::MODO_APLICAR : self::MODO_REPORTAR;
    }

    public function nombreCabecera(): string
    {
        return $this->modo() === self::MODO_APLICAR
            ? 'Content-Security-Policy'
            : 'Content-Security-Policy-Report-Only';
    }

    /**
     * Arma la cadena de la política.
     */
    public function construir(string $nonce): string
    {
        /** @var array<string, mixed> $opciones */
        $opciones = config('seguridad.cabeceras.politica_contenido', []);

        $enLocal = ! app()->isProduction();

        /** @var array<int, string> $vite */
        $vite = $enLocal ? (array) ($opciones['origenes_vite'] ?? []) : [];

        // Orígenes de http/ws para el servidor de desarrollo: en producción no
        // aparecen, así que la política del servidor real no los hereda.
        $viteHttp = array_values(array_filter($vite, static fn (string $o): bool => str_starts_with($o, 'http')));
        $viteWs = array_values(array_filter($vite, static fn (string $o): bool => str_starts_with($o, 'ws')));

        $script = ["'self'", "'nonce-".$nonce."'"];

        if ((bool) ($opciones['permitir_eval'] ?? true)) {
            // Concesión consciente y acotada a script-src: Alpine, que Livewire
            // incrusta, compila cada expresión de x-data con "new Function". Sin
            // 'unsafe-eval' no hay interfaz. Se prefiere esto antes que
            // 'unsafe-inline', que es la concesión que de verdad abre la puerta al
            // XSS reflejado: con nonce, un <script> inyectado por el atacante no
            // se ejecuta aunque exista 'unsafe-eval', porque el atacante no puede
            // adivinar el nonce de esta petición.
            $script[] = "'unsafe-eval'";
        }

        $estilo = ["'self'", "'nonce-".$nonce."'"];

        $directivas = [
            // Todo lo que no tenga directiva propia cae aquí: por omisión, nada.
            'default-src' => ["'none'"],

            // XSS: el vector clásico es inyectar <script> en un campo que se
            // refleja. Con nonce por petición, el script inyectado no lleva el
            // nonce correcto y el navegador se niega a ejecutarlo.
            'script-src' => array_merge($script, $viteHttp),

            // Estilos: mismo razonamiento, aplicado al robo de datos por CSS
            // (selectores de atributo que filtran el valor de un campo).
            'style-src' => array_merge($estilo, $viteHttp),

            // Los atributos style="..." sueltos que emiten Flux y Livewire no
            // pueden llevar nonce. En vez de abrir 'unsafe-inline' en style-src
            // —que también permitiría bloques <style> inyectados— se abre solo en
            // style-src-attr, que es la rendija más estrecha que resuelve el caso.
            'style-src-attr' => ["'unsafe-inline'"],

            'img-src' => ["'self'", 'data:', 'blob:'],

            'font-src' => ["'self'", 'data:'],

            // Exfiltración: aunque un script logre ejecutarse, no puede mandar lo
            // robado a un servidor ajeno si connect-src no lo permite.
            'connect-src' => array_merge(["'self'"], $viteHttp, $viteWs),

            // Secuestro de clics: nadie puede meter la tienda en un marco.
            // frame-ancestors es lo que de verdad respetan los navegadores
            // modernos; X-Frame-Options se sigue enviando por los antiguos.
            'frame-ancestors' => ["'none'"],

            // Robo de credenciales: un <form action="https://sitio-del-atacante">
            // inyectado en la página de pago no llegaría a enviarse.
            'form-action' => ["'self'"],

            // Inyección de <base href> para desviar todas las rutas relativas.
            'base-uri' => ["'self'"],

            'object-src' => ["'none'"],

            'frame-src' => ["'self'"],

            'worker-src' => ["'self'", 'blob:'],

            'manifest-src' => ["'self'"],
        ];

        foreach ((array) ($opciones['origenes_adicionales'] ?? []) as $directiva => $origenes) {
            if (isset($directivas[$directiva]) && is_array($origenes) && $origenes !== []) {
                $directivas[$directiva] = array_merge($directivas[$directiva], $origenes);
            }
        }

        $partes = [];

        foreach ($directivas as $nombre => $valores) {
            $partes[] = $nombre.' '.implode(' ', array_values(array_unique($valores)));
        }

        // Contenido mixto: en producción se fuerza que todo recurso viaje por TLS.
        // En local rompería el desarrollo sobre http://localhost.
        if (app()->isProduction()) {
            $partes[] = 'upgrade-insecure-requests';
        }

        /** @var string|null $reporte */
        $reporte = $opciones['uri_reporte'] ?? null;

        if (is_string($reporte) && $reporte !== '') {
            $partes[] = 'report-uri '.$reporte;
        }

        return implode('; ', $partes);
    }
}
