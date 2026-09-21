<?php

namespace App\Console\Commands;

use App\Models\ReglaCorrelacion;
use App\Services\Siem\MotorCorrelacion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;

/**
 * Pasa el motor de correlacion sobre los eventos ingeridos y levanta las alertas.
 *
 * Se ejecuta en bucle desde el planificador (cada minuto) o en modo continuo durante una
 * demostracion. La deteccion no sirve de nada si depende de que alguien recuerde lanzarla.
 */
class CorrelacionarEventos extends Command
{
    protected $signature = 'siem:correlacionar
        {--sembrar-reglas : Crea las reglas por defecto que falten y termina}
        {--ahora= : Instante que el motor tomara como presente, en formato Y-m-d H:i:s}
        {--continuo : Repite la pasada de forma indefinida}
        {--intervalo=60 : Segundos entre pasadas en modo continuo}
        {--duracion=0 : Segundos maximos en modo continuo. 0 significa sin limite}';

    protected $description = 'Ejecuta las reglas de correlacion sobre los eventos de seguridad y genera alertas';

    public function handle(MotorCorrelacion $motor): int
    {
        // Sin catalogo de reglas el motor no detecta nada, asi que la primera ejecucion lo
        // siembra sola. Reejecutarlo respeta los umbrales que el analista haya afinado.
        if (ReglaCorrelacion::query()->count() === 0 || $this->option('sembrar-reglas')) {
            $creadas = $motor->sembrarReglas();
            $this->info("Reglas de correlacion sembradas: {$creadas} nuevas.");

            if ($this->option('sembrar-reglas')) {
                return self::SUCCESS;
            }
        }

        $ahora = $this->instante();

        if ($ahora === false) {
            $this->error('El valor de --ahora no es una fecha valida. Use el formato Y-m-d H:i:s.');

            return self::INVALID;
        }

        $continuo = (bool) $this->option('continuo');
        $intervalo = max((int) $this->option('intervalo'), 1);
        $duracion = max((int) $this->option('duracion'), 0);
        $limite = CarbonImmutable::now()->addSeconds($duracion);

        do {
            $resumen = $motor->ejecutar($ahora);
            $total = array_sum($resumen);

            if ($total > 0) {
                $this->table(
                    ['Regla', 'Alertas nuevas'],
                    collect($resumen)
                        ->filter(static fn (int $cantidad): bool => $cantidad > 0)
                        ->map(static fn (int $cantidad, string $clave): array => [$clave, $cantidad])
                        ->values()
                        ->all(),
                );
            }

            $this->line(sprintf(
                '[%s] Pasada completa: %d alertas nuevas.',
                CarbonImmutable::now()->format('H:i:s'),
                $total,
            ));

            if (! $continuo) {
                break;
            }

            if ($duracion > 0 && CarbonImmutable::now()->greaterThanOrEqualTo($limite)) {
                $this->comment('Se alcanzo la duracion maxima indicada. Fin de la correlacion continua.');
                break;
            }

            sleep($intervalo);
        } while (true);

        return self::SUCCESS;
    }

    private function instante(): CarbonInterface|false|null
    {
        $valor = $this->option('ahora');

        if (! is_string($valor) || $valor === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($valor);
        } catch (\Throwable) {
            return false;
        }
    }
}
