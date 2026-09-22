<?php

declare(strict_types=1);

namespace App\Livewire\Seo;

use App\Models\ComparacionContenido;
use App\Services\Seo\DetectorContenidoDiferenciado;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Pantalla de la detección de contenido diferenciado.
 *
 * Dos botones y no uno, porque hacen cosas distintas y una de ellas toca un sitio ajeno:
 *
 *   - "Ver el caso real" no pide nada a nadie. Reproduce las cinco respuestas del caso de la
 *     empresa de cámaras a partir de contenido guardado. Es lo que se enseña el sábado: no
 *     depende de la red del aula y no apunta la herramienta a un dominio de terceros delante
 *     de treinta personas.
 *   - "Comparar" sí descarga la dirección cinco veces desde el servidor, y por eso exige que
 *     quien lo pulsa declare que el sitio es suyo o que tiene autorización. La casilla no es
 *     un adorno legal: es el control de autorización de una herramienta que, usada sobre un
 *     dominio ajeno, es exactamente lo que parece.
 *
 * Comparar NO guarda nada, igual que en el resto del capítulo: hay que poder corregir la
 * dirección y repetir sin llenar el historial de tanteos. Guardar es un acto aparte.
 */
class DetectorCloaking extends Component
{
    public string $url = '';

    /**
     * Declaración de autorización. Empieza en falso siempre y NO se recuerda entre visitas
     * a propósito: una autorización que se marca sola deja de ser una decisión.
     */
    public bool $autorizado = false;

    /**
     * Resultado de la última comparación. Se guarda en la propiedad y no se recalcula en
     * cada render porque recalcularlo significaría volver a pedir la página cinco veces cada
     * vez que se redibuja la pantalla.
     *
     * @var array<string, mixed>|null
     */
    public ?array $resultado = null;

    public bool $registrado = false;

    public function mount(DetectorContenidoDiferenciado $detector): void
    {
        $this->url = (string) config('app.url');

        // La pantalla arranca con el caso real ya comparado. Permite abrir la página,
        // señalar el veredicto y explicar el control sin escribir nada en vivo y sin
        // depender de que haya red.
        $this->resultado = $detector->demostracionCasoReal();
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:490'],
            'autorizado' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'url' => 'dirección',
            'autorizado' => 'declaración de autorización',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'autorizado.accepted' => 'Marque la declaración: esta herramienta solo puede apuntarse a sitios propios o autorizados.',
        ];
    }

    /**
     * El aviso de uso se pinta desde el servicio y no se copia en la plantilla: si alguien
     * endurece la condición, tiene que cambiar en un solo sitio y aparecer en todos.
     */
    #[Computed]
    public function aviso(): string
    {
        return app(DetectorContenidoDiferenciado::class)->avisoDeUso();
    }

    /**
     * Comparaciones anteriores de la misma dirección.
     *
     * Es la mitad del control que no se ve en una sola ejecución: el cloaking se limpia y
     * vuelve, y lo que demuestra que volvió es tener las dos mediciones con su fecha.
     *
     * @return array<int, ComparacionContenido>
     */
    #[Computed]
    public function historial(): array
    {
        $url = (string) ($this->resultado['url'] ?? '');

        if ($url === '' || ($this->resultado['simulada'] ?? false)) {
            return [];
        }

        return app(DetectorContenidoDiferenciado::class)->historial($url);
    }

    public function demostrar(DetectorContenidoDiferenciado $detector): void
    {
        $this->resultado = $detector->demostracionCasoReal();
        $this->registrado = false;

        Flux::toast(
            variant: 'danger',
            text: 'Caso real reproducido: '.$this->resultado['resumen']['puntuacion'].' puntos de divergencia.',
        );
    }

    public function comparar(DetectorContenidoDiferenciado $detector): void
    {
        abort_unless(Auth::check(), 403);

        $this->validate();
        $this->registrado = false;

        try {
            $normalizada = $detector->normalizarUrl($this->url);
        } catch (InvalidArgumentException $error) {
            $this->addError('url', $error->getMessage());

            return;
        }

        // El límite protege al sitio de destino, no a esta aplicación. Cada comparación son
        // cinco peticiones; sin techo, un dedo nervioso sobre el botón convierte una
        // herramienta de diagnóstico en una pequeña denegación de servicio contra el propio
        // sitio que se quería revisar.
        $clave = 'cloaking:'.Auth::id().':'.parse_url($normalizada, PHP_URL_HOST);

        if (RateLimiter::tooManyAttempts($clave, 4)) {
            Flux::toast(
                variant: 'warning',
                text: 'Espere '.RateLimiter::availableIn($clave).' segundos: cada comparación son cinco peticiones al sitio.',
            );

            return;
        }

        RateLimiter::hit($clave, 60);

        try {
            $this->resultado = $detector->comparar($this->url);
        } catch (InvalidArgumentException $error) {
            $this->addError('url', $error->getMessage());

            return;
        }

        unset($this->historial);

        $resumen = $this->resultado['resumen'];

        Flux::toast(
            variant: match ($resumen['veredicto']) {
                ComparacionContenido::VEREDICTO_CLOAKING => 'danger',
                ComparacionContenido::VEREDICTO_SOSPECHOSO => 'warning',
                ComparacionContenido::VEREDICTO_INCOMPLETO => 'warning',
                default => 'success',
            },
            text: $resumen['etiqueta_veredicto'].': '.$resumen['puntuacion'].' puntos de divergencia.',
        );
    }

    /**
     * Guarda la comparación y, si procede, abre el incidente que aparecerá en el panel y en
     * la bitácora que lee el motor de correlación del SIEM.
     */
    public function registrarComparacion(DetectorContenidoDiferenciado $detector): void
    {
        abort_unless(Auth::check(), 403);

        if ($this->resultado === null) {
            Flux::toast(variant: 'warning', text: 'Primero hay que comparar una dirección.');

            return;
        }

        $fila = $detector->registrar($this->resultado, [
            'ip' => request()->ip(),
            'agente_usuario' => request()->userAgent(),
            'ruta' => request()->path(),
            'usuario_id' => Auth::id(),
        ]);

        $this->registrado = $fila !== null;

        unset($this->historial);

        if ($fila === null) {
            // Se avisa del fallo en lugar de enseñar un éxito silencioso: si la tabla no
            // está migrada o la base de datos no responde, quien mira la pantalla tiene que
            // enterarse. El incidente y la bitácora sí salieron.
            Flux::toast(
                variant: 'danger',
                text: 'No se pudo guardar la comparación. Revise la bitácora: el incidente sí se emitió.',
            );

            return;
        }

        Flux::toast(
            variant: 'success',
            text: $fila->incidente_seo_id !== null
                ? 'Comparación guardada e incidente abierto en el panel.'
                : 'Comparación guardada en el historial.',
        );
    }

    public function limpiar(): void
    {
        $this->resultado = null;
        $this->registrado = false;
        $this->resetValidation();

        unset($this->historial);
    }

    public function render(): View
    {
        return view('livewire.seo.detector-cloaking');
    }
}
