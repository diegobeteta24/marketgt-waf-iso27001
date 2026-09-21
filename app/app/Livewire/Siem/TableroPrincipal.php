<?php

namespace App\Livewire\Siem;

use App\Services\Siem\ResumenOperativo;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Cabecera del centro de monitoreo: contadores, grafica temporal, reglas mas activadas
 * y direcciones mas agresivas.
 *
 * La grafica se dibuja con SVG generado en el servidor. No se carga ninguna libreria de
 * terceros: el panel de seguridad del proyecto no puede depender de un CDN externo, que
 * seria justo el tipo de dependencia que el propio WAF esta para vigilar.
 */
class TableroPrincipal extends Component
{
    /**
     * Ventana de la grafica y de los rankings. Se limita a valores conocidos para que nadie
     * pueda pedir seis meses por la barra de direcciones y tumbar la base.
     */
    #[Url(as: 'horas')]
    public int $horas = 24;

    /**
     * @var array<int, int>
     */
    public const VENTANAS = [6, 12, 24, 48, 72];

    public function cambiarVentana(int $horas): void
    {
        if (in_array($horas, self::VENTANAS, true)) {
            $this->horas = $horas;
        }
    }

    public function mount(): void
    {
        if (! in_array($this->horas, self::VENTANAS, true)) {
            $this->horas = 24;
        }
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function contadores(): array
    {
        return $this->resumen()->contadores();
    }

    /**
     * @return array<int, array{etiqueta: string, momento: string, bloqueados: int, permitidos: int, total: int}>
     */
    #[Computed]
    public function serie(): array
    {
        return $this->resumen()->serieHoraria($this->horas);
    }

    /**
     * @return array{reglas: array<int, array<string, mixed>>, truncado: bool}
     */
    #[Computed]
    public function reglas(): array
    {
        return $this->resumen()->reglasMasActivadas(10, $this->horas);
    }

    /**
     * @return Collection<int, object>
     */
    #[Computed]
    public function direcciones(): Collection
    {
        return $this->resumen()->direccionesMasAgresivas(10, $this->horas);
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function reparto(): array
    {
        return $this->resumen()->repartoSeveridad($this->horas);
    }

    #[Computed]
    public function ultimoEvento(): ?CarbonInterface
    {
        return $this->resumen()->ultimoEventoEn();
    }

    /**
     * Un panel en cero puede significar calma o puede significar que la ingesta murio.
     * Distinguirlo es media operacion, asi que el panel lo dice en voz alta.
     */
    #[Computed]
    public function ingestaDetenida(): bool
    {
        $ultimo = $this->ultimoEvento();

        return $ultimo === null || $ultimo->lessThan(CarbonImmutable::now()->subMinutes(30));
    }

    /**
     * Geometria de la grafica de barras apiladas, resuelta en el servidor.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function grafica(): array
    {
        $serie = $this->serie();
        $puntos = count($serie);

        $ancho = 760.0;
        $alto = 240.0;
        $margenIzquierdo = 46.0;
        $margenDerecho = 12.0;
        $margenSuperior = 16.0;
        $margenInferior = 42.0;

        $anchoUtil = $ancho - $margenIzquierdo - $margenDerecho;
        $altoUtil = $alto - $margenSuperior - $margenInferior;
        $linaBase = $margenSuperior + $altoUtil;

        $maximo = max(1, max(array_map(static fn (array $fila): int => $fila['total'], $serie) ?: [1]));
        $techo = $this->techoAgradable($maximo);

        $paso = $puntos > 0 ? $anchoUtil / $puntos : $anchoUtil;
        $anchoBarra = max(3.0, min(26.0, $paso * 0.62));

        $barras = [];
        $indiceMaximo = 0;

        foreach ($serie as $indice => $fila) {
            if ($fila['total'] === $maximo) {
                $indiceMaximo = $indice;
            }

            $centro = $margenIzquierdo + ($indice + 0.5) * $paso;
            $x = $centro - $anchoBarra / 2;

            $altoBloqueados = $techo > 0 ? ($fila['bloqueados'] / $techo) * $altoUtil : 0.0;
            $altoPermitidos = $techo > 0 ? ($fila['permitidos'] / $techo) * $altoUtil : 0.0;

            // Dos pixeles de aire entre los segmentos apilados: sin ellos, en la pantalla de
            // un proyector los dos colores se leen como una sola barra.
            $separacion = ($altoBloqueados > 0 && $altoPermitidos > 0) ? 2.0 : 0.0;

            $barras[] = [
                'indice' => $indice,
                'x' => round($x, 2),
                'centro' => round($centro, 2),
                'ancho' => round($anchoBarra, 2),
                'bloqueados' => [
                    'y' => round($linaBase - $altoBloqueados, 2),
                    'alto' => round(max($altoBloqueados, $fila['bloqueados'] > 0 ? 2.0 : 0.0), 2),
                    'valor' => $fila['bloqueados'],
                ],
                'permitidos' => [
                    'y' => round($linaBase - $altoBloqueados - $separacion - $altoPermitidos, 2),
                    'alto' => round(max($altoPermitidos, $fila['permitidos'] > 0 ? 2.0 : 0.0), 2),
                    'valor' => $fila['permitidos'],
                ],
                'etiqueta' => $fila['etiqueta'],
                'momento' => $fila['momento'],
                'total' => $fila['total'],
                // Con muchas horas en pantalla las etiquetas del eje chocan entre si.
                'mostrar_etiqueta' => $puntos <= 12 || $indice % (int) ceil($puntos / 12) === 0,
            ];
        }

        $rejilla = [];

        foreach ([0, 0.25, 0.5, 0.75, 1.0] as $fraccion) {
            $rejilla[] = [
                'y' => round($linaBase - $fraccion * $altoUtil, 2),
                'valor' => (int) round($techo * $fraccion),
            ];
        }

        return [
            'ancho' => $ancho,
            'alto' => $alto,
            'margen_izquierdo' => $margenIzquierdo,
            'linea_base' => round($linaBase, 2),
            'barras' => $barras,
            'rejilla' => $rejilla,
            'techo' => $techo,
            'indice_maximo' => $indiceMaximo,
            'sin_datos' => $maximo <= 1 && array_sum(array_map(static fn (array $f): int => $f['total'], $serie)) === 0,
        ];
    }

    /**
     * Redondea el techo del eje a una cifra legible para que las lineas de rejilla caigan en
     * numeros que una persona puede leer de un vistazo desde el fondo del aula.
     */
    private function techoAgradable(int $maximo): int
    {
        if ($maximo <= 4) {
            return 4;
        }

        $magnitud = 10 ** (int) floor(log10($maximo));

        foreach ([1, 2, 2.5, 5, 10] as $multiplo) {
            $candidato = (int) ceil($magnitud * $multiplo);

            if ($candidato >= $maximo) {
                return $candidato;
            }
        }

        return (int) ($magnitud * 10);
    }

    private function resumen(): ResumenOperativo
    {
        return app(ResumenOperativo::class);
    }

    public function render(): View
    {
        return view('livewire.siem.tablero-principal');
    }
}
