<?php

declare(strict_types=1);

namespace App\Livewire\Demo;

use App\Services\Demo\LanzadorAtaques;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Consola de demostración de ataques en vivo.
 *
 * Es la pantalla que se proyecta el sábado: un botón lanza un ataque real contra el propio
 * sitio y, en la misma vista, aparece el bloqueo del WAF con la regla activada, la
 * puntuación de anomalía y el identificador único de transacción. Toda la lógica de red y
 * de lectura del registro vive en el servicio LanzadorAtaques; aquí solo se orquesta la
 * interacción y se lleva la cuenta de la sesión de demostración.
 */
class Consola extends Component
{
    /**
     * Resultado del último disparo individual, para el panel de detalle.
     *
     * @var array<string, mixed>|null
     */
    public ?array $ultimo = null;

    /**
     * Resultado de la última comparación (WAF activo frente a solo detección).
     *
     * @var array<string, mixed>|null
     */
    public ?array $comparacion = null;

    /**
     * Bitácora de la sesión de demostración. Vive en memoria y se reinicia al recargar la
     * página: mide ESTA presentación, no el histórico (para eso está el SIEM).
     *
     * @var array<int, array<string, mixed>>
     */
    public array $historial = [];

    public function lanzar(string $id): void
    {
        $resultado = $this->lanzador()->lanzar($id, 'activo');

        $this->ultimo = $resultado;
        $this->comparacion = null;
        $this->anotar($resultado);
    }

    public function comparar(string $id): void
    {
        $comparacion = $this->lanzador()->comparar($id);

        $this->comparacion = $comparacion;
        $this->ultimo = null;

        // La comparación dispara dos peticiones: ambas cuentan en la bitácora de la sesión.
        $this->anotar($comparacion['activo']);
        $this->anotar($comparacion['deteccion']);
    }

    public function limpiarSesion(): void
    {
        $this->historial = [];
        $this->ultimo = null;
        $this->comparacion = null;
    }

    /**
     * Vuelve a leer el registro de auditoría para el último disparo, por si el asiento se
     * escribió después de que la respuesta HTTP ya hubiera vuelto. No relanza el ataque:
     * solo relee, usando la marca de correlación que ya se envió.
     */
    public function reintentarEvento(): void
    {
        if (is_array($this->ultimo) && isset($this->ultimo['marca'])) {
            $this->ultimo['evento'] = $this->lanzador()->buscarEvento((string) $this->ultimo['marca']);

            return;
        }

        if (is_array($this->comparacion)) {
            foreach (['activo', 'deteccion'] as $mitad) {
                if (isset($this->comparacion[$mitad]['marca'])) {
                    $this->comparacion[$mitad]['evento'] = $this->lanzador()
                        ->buscarEvento((string) $this->comparacion[$mitad]['marca']);
                }
            }
        }
    }

    /**
     * Registra un disparo en la bitácora de la sesión.
     *
     * @param  array<string, mixed>  $resultado
     */
    private function anotar(array $resultado): void
    {
        if (($resultado['ok'] ?? false) !== true) {
            $this->historial[] = [
                'nombre' => $resultado['nombre'] ?? $resultado['id'] ?? 'desconocido',
                'modo' => $resultado['modo'] ?? 'activo',
                'codigo' => null,
                'bloqueado' => false,
                'error' => true,
                'hora' => now()->format('H:i:s'),
            ];

            return;
        }

        $this->historial[] = [
            'nombre' => $resultado['nombre'],
            'modo' => $resultado['modo'],
            'codigo' => $resultado['respuesta']['codigo'] ?? null,
            'bloqueado' => $resultado['bloqueado'] ?? false,
            'error' => false,
            'hora' => now()->format('H:i:s'),
        ];
    }

    /**
     * Catálogo de ataques agrupado.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function catalogo(): array
    {
        return $this->lanzador()->catalogo();
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function diagnostico(): array
    {
        return $this->lanzador()->diagnostico();
    }

    /**
     * Contador de la sesión: lanzados, bloqueados por el WAF y porcentaje de eficacia.
     * Solo se miden los disparos en modo activo: en solo detección el WAF NO bloquea a
     * propósito, así que meterlos en la eficacia falsearía la cifra a la baja.
     *
     * @return array<string, int|float|null>
     */
    #[Computed]
    public function resumen(): array
    {
        $activos = array_filter(
            $this->historial,
            static fn (array $fila): bool => $fila['modo'] === 'activo' && $fila['error'] === false,
        );

        $lanzados = count($activos);
        $bloqueados = count(array_filter($activos, static fn (array $fila): bool => $fila['bloqueado'] === true));

        return [
            'total' => count($this->historial),
            'lanzados' => $lanzados,
            'bloqueados' => $bloqueados,
            'eficacia' => $lanzados > 0 ? round($bloqueados * 100 / $lanzados, 1) : null,
        ];
    }

    private function lanzador(): LanzadorAtaques
    {
        return app(LanzadorAtaques::class);
    }

    public function render(): View
    {
        return view('livewire.demo.consola');
    }
}
