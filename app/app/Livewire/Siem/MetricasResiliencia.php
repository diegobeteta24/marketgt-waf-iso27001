<?php

namespace App\Livewire\Siem;

use App\Services\Siem\CalculadoraMetricas;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Metricas del triangulo de la ciberresiliencia.
 *
 * Todo lo que se ve aqui sale de una consulta. Lo que no sale de una consulta se muestra
 * como "sin datos" y explica que haria falta para medirlo. Un panel que rellena huecos con
 * cifras bonitas es peor que no tener panel: convierte una carencia conocida en una
 * falsedad documentada.
 */
class MetricasResiliencia extends Component
{
    /**
     * @return array<string, array<string, mixed>>
     */
    #[Computed]
    public function vertices(): array
    {
        return $this->calculadora()->calcular();
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function conteos(): array
    {
        return $this->calculadora()->conteosAlertas();
    }

    #[Computed]
    public function diasObservacion(): int
    {
        return $this->calculadora()->diasObservacion();
    }

    /**
     * Cuantas metricas del tablero se pueden calcular hoy. Es la cifra que un auditor
     * apunta primero: mide la madurez de la instrumentacion, no la del control.
     *
     * @return array{medidas: int, total: int, cumplidas: int}
     */
    #[Computed]
    public function cobertura(): array
    {
        $medidas = 0;
        $total = 0;
        $cumplidas = 0;

        foreach ($this->vertices() as $vertice) {
            foreach ($vertice['metricas'] as $metrica) {
                $total++;

                if ($metrica['estado'] !== CalculadoraMetricas::SIN_DATOS) {
                    $medidas++;
                }

                if ($metrica['estado'] === CalculadoraMetricas::CUMPLE) {
                    $cumplidas++;
                }
            }
        }

        return ['medidas' => $medidas, 'total' => $total, 'cumplidas' => $cumplidas];
    }

    private function calculadora(): CalculadoraMetricas
    {
        return app(CalculadoraMetricas::class);
    }

    public function render(): View
    {
        return view('livewire.siem.metricas-resiliencia');
    }
}
