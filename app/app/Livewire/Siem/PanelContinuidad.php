<?php

namespace App\Livewire\Siem;

use App\Models\PruebaRestauracion;
use App\Services\Siem\CalculadoraMetricas;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Historial de pruebas de restauracion: de donde salen el RTO y el RPO.
 *
 * Este panel no calcula nada sobre la configuracion de los respaldos. Lee actas de pruebas
 * que se ejecutaron, con su fecha, su duracion por fases y quien las lanzo. Mientras no haya
 * ninguna prueba satisfactoria, las dos metricas se declaran sin datos, y eso es un resultado
 * legitimo: nadie puede afirmar cuanto tarda en recuperarse de algo que nunca ha recuperado.
 */
class PanelContinuidad extends Component
{
    /**
     * Cuantas actas se listan. Suficientes para ver la tendencia de varios meses de pruebas
     * mensuales sin convertir el panel en un volcado de la tabla.
     */
    public int $limite = 12;

    /**
     * Acta desplegada en el detalle. Null significa que ninguna esta abierta.
     */
    public ?int $detalle = null;

    public function alternarDetalle(int $id): void
    {
        $this->detalle = $this->detalle === $id ? null : $id;
    }

    /**
     * @return Collection<int, PruebaRestauracion>
     */
    #[Computed]
    public function pruebas(): Collection
    {
        return PruebaRestauracion::query()
            ->with('operador')
            ->orderByDesc('iniciada_en')
            ->limit(max(1, min($this->limite, 50)))
            ->get();
    }

    /**
     * Los hechos medidos, tal y como los leera tambien la calculadora del triangulo.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function medicion(): array
    {
        return PruebaRestauracion::medicionRecuperacion();
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function resumen(): array
    {
        $total = PruebaRestauracion::query()->count();
        $satisfactorias = PruebaRestauracion::query()->satisfactorias()->count();

        return [
            'total' => $total,
            'satisfactorias' => $satisfactorias,
            'fallidas' => $total - $satisfactorias,
        ];
    }

    /**
     * El equivalente al aviso que ya vigila la ingesta de eventos: un panel que calla cuando
     * la evidencia caduca acaba presentando como vigente una medicion del semestre pasado.
     */
    #[Computed]
    public function pruebaVencida(): bool
    {
        return (bool) $this->medicion()['vencida'];
    }

    /**
     * Las dos metricas del vertice de respuesta, con la misma forma que produce
     * CalculadoraMetricas para que el panel las pinte igual que a las demas.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function metricas(): array
    {
        $medicion = $this->medicion();

        $metaRto = (float) config('siem.metas.objetivo_tiempo_recuperacion_horas', 4);
        $metaRpo = (float) config('siem.metas.objetivo_punto_recuperacion_horas', 24);

        return [
            $this->metrica(
                clave: 'objetivo_tiempo_recuperacion',
                nombre: 'Objetivo de tiempo de recuperacion (RTO)',
                meta: $this->horasLegibles($metaRto).' o menos',
                horas: $medicion['rto_horas'],
                texto: PruebaRestauracion::duracionLegible($medicion['prueba']?->segundos_recuperacion),
                cumple: $medicion['rto_horas'] !== null && $medicion['rto_horas'] <= $metaRto,
                muestra: (int) $medicion['rto_muestra'],
                origen: (string) $medicion['rto_origen'],
                advertencia: $medicion['advertencia'],
            ),
            $this->metrica(
                clave: 'objetivo_punto_recuperacion',
                nombre: 'Objetivo de punto de recuperacion (RPO)',
                meta: 'perdida maxima de '.$this->horasLegibles($metaRpo),
                horas: $medicion['rpo_horas'],
                texto: PruebaRestauracion::antiguedadLegible($medicion['prueba']?->antiguedad_respaldo_minutos),
                cumple: $medicion['rpo_horas'] !== null && $medicion['rpo_horas'] <= $metaRpo,
                muestra: $medicion['prueba']?->respaldos_encontrados ?? 0,
                origen: (string) $medicion['rpo_origen'],
                advertencia: $medicion['vencida'] && $medicion['rpo_horas'] !== null
                    ? 'La medicion se tomo hace '.$medicion['dias_desde_prueba'].' dias: desde entonces nadie ha vuelto a mirar el directorio de respaldos.'
                    : null,
            ),
        ];
    }

    /**
     * Fraccion de la meta que consumio la ultima recuperacion medida, acotada a uno para que
     * un incumplimiento no desborde la barra.
     */
    #[Computed]
    public function ocupacionRto(): ?float
    {
        $horas = $this->medicion()['rto_horas'];

        if ($horas === null) {
            return null;
        }

        $meta = (float) config('siem.metas.objetivo_tiempo_recuperacion_horas', 4);

        return $meta > 0 ? min(1.0, $horas / $meta) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function metrica(
        string $clave,
        string $nombre,
        string $meta,
        ?float $horas,
        string $texto,
        bool $cumple,
        int $muestra,
        string $origen,
        ?string $advertencia,
    ): array {
        $hayDato = $horas !== null;

        return [
            'clave' => $clave,
            'nombre' => $nombre,
            'meta' => $meta,
            'valor' => $horas,
            'valor_texto' => $hayDato ? $texto : 'sin datos',
            'unidad' => $hayDato ? 'h' : null,
            'estado' => match (true) {
                ! $hayDato => CalculadoraMetricas::SIN_DATOS,
                $cumple => CalculadoraMetricas::CUMPLE,
                default => CalculadoraMetricas::INCUMPLE,
            },
            'muestra' => $hayDato ? $muestra : 0,
            'origen' => $origen,
            'advertencia' => $hayDato ? $advertencia : null,
        ];
    }

    private function horasLegibles(float $horas): string
    {
        return $horas == (int) $horas
            ? ((int) $horas).' '.((int) $horas === 1 ? 'hora' : 'horas')
            : number_format($horas, 1).' horas';
    }

    #[Computed]
    public function periodicidad(): int
    {
        return PruebaRestauracion::PERIODICIDAD_DIAS;
    }

    #[Computed]
    public function ahora(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }

    public function render(): View
    {
        return view('livewire.siem.panel-continuidad');
    }
}
