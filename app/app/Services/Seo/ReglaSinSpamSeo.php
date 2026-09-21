<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\IncidenteSeo;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Regla de validación para cualquier campo que acabe publicado y, por tanto, indexado.
 *
 * Uso desde el componente que guarda una reseña:
 *
 *     $this->validate([
 *         'comentario' => ['required', 'string', 'max:2000', new ReglaSinSpamSeo('reseña de producto')],
 *     ]);
 *
 * Se entrega como regla y no como una comprobación suelta porque así el mensaje llega al
 * formulario por el camino normal de Laravel y el componente que la usa no tiene que saber
 * nada de puntuaciones ni de incidentes.
 */
class ReglaSinSpamSeo implements ValidationRule
{
    public function __construct(
        private readonly string $origen = 'contenido publicable',
        private readonly bool $registrarIncidente = true,
    ) {}

    /**
     * @param  Closure(string): void  $fallar
     */
    public function validate(string $atributo, mixed $valor, Closure $fallar): void
    {
        if (! is_string($valor) || trim($valor) === '') {
            return;
        }

        $veredicto = app(DetectorSpamSeo::class)->analizar($valor);

        if ($veredicto['publicable']) {
            return;
        }

        if ($this->registrarIncidente) {
            app(RegistroIncidentesSeo::class)->registrar(
                IncidenteSeo::TIPO_CONTENIDO_SPAM,
                'Contenido rechazado en validación ('.$this->origen.'): '.$veredicto['puntuacion'].' puntos de anomalía',
                [
                    'ip' => request()->ip(),
                    'agente_usuario' => request()->userAgent(),
                    'ruta' => request()->path(),
                    'metodo' => request()->method(),
                    'usuario_id' => Auth::id(),
                    'detalle' => [
                        'campo' => $atributo,
                        'puntuacion' => $veredicto['puntuacion'],
                        'umbral' => DetectorSpamSeo::UMBRAL,
                        'reglas' => array_column($veredicto['motivos'], 'regla'),
                        'enlaces' => array_slice($veredicto['enlaces'], 0, 10),
                        'fragmento' => Str::limit($valor, 500),
                        'origen' => $this->origen,
                    ],
                ],
            );
        }

        // El mensaje no enumera qué patrón saltó. Decírselo al atacante le ahorra el trabajo
        // de averiguar qué palabra cambiar para que la próxima pase; la persona honesta, en
        // cambio, no necesita el detalle para entender que su texto tiene demasiados enlaces.
        $fallar('Este contenido quedó retenido para revisión porque coincide con patrones de publicidad no deseada.');
    }
}
