<?php

namespace App\Console\Commands;

use App\Models\AlertaSeguridad;
use App\Services\Siem\TriajeAsistido;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Triaje por lote de las alertas nacidas de eventos sembrados para la demostracion.
 *
 * Por que existe este comando y por que se limita tanto:
 *
 * La cobertura de triaje mide cuantas alertas reviso el equipo. Subirla marcando alertas de
 * trafico real sin que nadie las mire seria falsificar la metrica, que es exactamente el
 * hallazgo que el panel dice evitar. En cambio, las alertas que nacen de eventos sembrados
 * son datos de laboratorio: clasificarlas con un criterio escrito y publicado es legitimo,
 * siempre que quede constancia de que las clasifico un proceso y no una persona.
 *
 * De ahi las tres restricciones del comando, todas comprobadas contra la base y no supuestas:
 *   1. Solo alertas en estado "nueva".
 *   2. Solo alertas cuyos eventos enlazados son TODOS de demostracion.
 *   3. Solo alertas que encajan en una de las reglas del criterio declarado. El resto se
 *      quedan como estan, esperando a una persona.
 */
class TriarAlertasSinteticas extends Command
{
    protected $signature = 'siem:triar-sinteticas
        {--simular : No escribe nada. Enumera que haria, con que regla y sobre que hechos}
        {--dias=30 : Ventana hacia atras sobre detectada_en. 0 quita el filtro de fecha}
        {--limite=0 : Maximo de alertas a procesar. 0 significa todas las que encajen}
        {--actor= : Quien ordena la ejecucion. Por defecto el comando, el anfitrion y el usuario del sistema}
        {--detalle : Imprime tambien las alertas que se dejan sin clasificar y por que}';

    protected $description = 'Tria por lote las alertas de demostracion segun un criterio declarado, registrando la procedencia de cada decision';

    public function handle(TriajeAsistido $triaje): int
    {
        $dias = max((int) $this->option('dias'), 0);
        $limite = max((int) $this->option('limite'), 0);
        $simular = (bool) $this->option('simular');

        $ahora = CarbonImmutable::now();
        $desde = $dias > 0 ? $ahora->subDays($dias) : null;

        // Sin las columnas de procedencia el comando podria escribir el estado igualmente,
        // pero dejaria alertas triadas sin constancia de quien las decidio. Eso es peor que
        // no triarlas: convierte una cifra comprobable en una cifra que nadie puede auditar.
        if (! $triaje->procedenciaRegistrable() && ! $simular) {
            $this->error('Faltan las columnas de procedencia en alertas_seguridad.');
            $this->line('Aplique la migracion 2026_09_24_000605_agregar_procedencia_triaje_a_alertas antes de triar por lote.');
            $this->line('Mientras tanto puede ver que haria el comando con la opcion --simular.');

            return self::FAILURE;
        }

        $this->mostrarCriterio();

        $consulta = $this->consultaCandidatas($desde, $ahora);
        $candidatas = (clone $consulta)->count();

        if ($limite > 0) {
            $consulta->limit($limite);
        }

        $reales = $this->contarRealesSinTriar($desde, $ahora);

        $this->line('');
        $this->info(sprintf(
            'Alertas de demostracion sin triar: %d%s',
            $candidatas,
            $dias > 0 ? ' (ultimos '.$dias.' dias)' : '',
        ));
        $this->line(sprintf(
            '  Alertas de trafico real sin triar: %d. Este comando no las toca: las revisa una persona.',
            $reales,
        ));

        if ($candidatas === 0) {
            $this->line('');
            $this->comment('No hay nada que triar por lote.');

            return self::SUCCESS;
        }

        $actor = $this->resolverActor();
        $resultados = ['contenidas' => 0, 'falsos_positivos' => 0, 'sin_clasificar' => 0, 'perdidas' => 0];
        $porRegla = [];
        $filas = [];

        $this->line('');

        $consulta->with('eventos')->chunkById(100, function ($alertas) use (
            $triaje, $actor, $ahora, $simular, &$resultados, &$porRegla, &$filas
        ): void {
            foreach ($alertas as $alerta) {
                // Segunda comprobacion del origen, esta vez sobre los objetos ya cargados.
                // La consulta filtra por relacion; esto lo verifica evento por evento.
                if (! $triaje->esDeDemostracion($alerta, $alerta->eventos)) {
                    $resultados['sin_clasificar']++;

                    continue;
                }

                $decision = $triaje->clasificar($alerta, $alerta->eventos);
                $porRegla[$decision['regla']] = ($porRegla[$decision['regla']] ?? 0) + 1;

                if ($decision['estado'] === null) {
                    $resultados['sin_clasificar']++;

                    if ($this->option('detalle')) {
                        $filas[] = [
                            $alerta->id,
                            $alerta->severidad,
                            $alerta->clave_regla,
                            'se deja como esta',
                            'ninguna regla encaja',
                        ];
                    }

                    continue;
                }

                if (! $simular && ! $triaje->registrarTriajeAutomatico($alerta, $decision, $actor, $ahora)) {
                    // La alerta dejo de estar en "nueva" entre la lectura y la escritura:
                    // alguien la tria desde el panel ahora mismo. Su decision es la buena.
                    $resultados['perdidas']++;

                    continue;
                }

                $decision['estado'] === AlertaSeguridad::ESTADO_CONTENIDA
                    ? $resultados['contenidas']++
                    : $resultados['falsos_positivos']++;

                $filas[] = [
                    $alerta->id,
                    $alerta->severidad,
                    $alerta->clave_regla,
                    AlertaSeguridad::ETIQUETAS_ESTADO[$decision['estado']],
                    $decision['regla'],
                ];
            }
        });

        if ($filas !== []) {
            $this->table(
                ['Alerta', 'Severidad', 'Regla de correlacion', $simular ? 'Quedaria' : 'Queda', 'Criterio aplicado'],
                array_slice($filas, 0, 60),
            );

            if (count($filas) > 60) {
                $this->line(sprintf('  ... y %d mas. La tabla completa esta en la base.', count($filas) - 60));
            }
        }

        $this->resumen($resultados, $porRegla, $simular);

        if (! $simular) {
            $this->cobertura($triaje, $desde, $ahora, $actor);
        }

        return self::SUCCESS;
    }

    /**
     * Candidatas: nuevas, marcadas como demostracion, con eventos enlazados y sin un solo
     * evento real entre ellos. Las tres condiciones se preguntan a la base.
     *
     * @return Builder<AlertaSeguridad>
     */
    private function consultaCandidatas(?CarbonImmutable $desde, CarbonImmutable $hasta): Builder
    {
        return AlertaSeguridad::query()
            ->where('estado', AlertaSeguridad::ESTADO_NUEVA)
            ->where('es_demostracion', true)
            ->whereHas('eventos')
            ->whereDoesntHave('eventos', fn (Builder $consulta) => $consulta->where('es_demostracion', false))
            ->when($desde !== null, fn (Builder $consulta) => $consulta->whereBetween('detectada_en', [$desde, $hasta]));
    }

    private function contarRealesSinTriar(?CarbonImmutable $desde, CarbonImmutable $hasta): int
    {
        return AlertaSeguridad::query()
            ->where('estado', AlertaSeguridad::ESTADO_NUEVA)
            ->where(fn (Builder $consulta) => $consulta
                ->where('es_demostracion', false)
                ->orWhereDoesntHave('eventos')
                ->orWhereHas('eventos', fn (Builder $interna) => $interna->where('es_demostracion', false)))
            ->when($desde !== null, fn (Builder $consulta) => $consulta->whereBetween('detectada_en', [$desde, $hasta]))
            ->count();
    }

    private function mostrarCriterio(): void
    {
        $this->info('Criterio de triaje automatico, en el orden en que se evalua:');

        foreach (TriajeAsistido::CRITERIOS as $indice => $criterio) {
            $this->line(sprintf(
                '  %d. %s -> %s',
                $indice + 1,
                $criterio['nombre'],
                $criterio['clave'] === TriajeAsistido::SIN_ENCAJE
                    ? 'se deja como esta, para revision humana'
                    : AlertaSeguridad::ETIQUETAS_ESTADO[$criterio['resultado']],
            ));
            $this->line('     '.$criterio['condicion']);
        }
    }

    /**
     * @param  array<string, int>  $resultados
     * @param  array<string, int>  $porRegla
     */
    private function resumen(array $resultados, array $porRegla, bool $simular): void
    {
        $this->line('');
        $this->info($simular ? 'Simulacion. No se escribio nada.' : 'Resultado del lote:');

        $this->line(sprintf('  Contenidas:        %d', $resultados['contenidas']));
        $this->line(sprintf('  Falsos positivos:  %d', $resultados['falsos_positivos']));
        $this->line(sprintf('  Sin clasificar:    %d  (quedan para revision humana)', $resultados['sin_clasificar']));

        if ($resultados['perdidas'] > 0) {
            $this->line(sprintf('  Ya triadas a mano: %d  (alguien se adelanto desde el panel)', $resultados['perdidas']));
        }

        if ($porRegla !== []) {
            $this->line('');
            $this->line('  Alertas decididas por cada regla:');

            foreach ($porRegla as $regla => $total) {
                $this->line(sprintf('    %-24s %d', $regla, $total));
            }
        }

        // La cifra que hace honesto al comando: si saliera cero, el criterio estaria
        // absorbiendo todo y habria dejado de ser un triaje.
        if ($resultados['sin_clasificar'] === 0 && ($resultados['contenidas'] + $resultados['falsos_positivos']) > 0) {
            $this->line('');
            $this->warn('  Ninguna alerta quedo sin clasificar. Revise el criterio: si lo clasifica todo,');
            $this->warn('  deja de ser un triaje y pasa a ser un relleno.');
        }
    }

    private function cobertura(TriajeAsistido $triaje, ?CarbonImmutable $desde, CarbonImmutable $hasta, string $actor): void
    {
        $conteos = $triaje->conteosProcedencia($desde, $hasta);

        $this->line('');
        $this->info('Cobertura de triaje despues del lote:');

        if ($conteos['total'] === 0) {
            $this->line('  Sin alertas en la ventana: la cobertura se declara sin datos.');

            return;
        }

        $this->line(sprintf(
            '  Total %d · revisadas %d (%.1f %%) · sin revisar %d',
            $conteos['total'],
            $conteos['triadas'],
            $conteos['triadas'] * 100 / $conteos['total'],
            $conteos['sin_triar'],
        ));
        $this->line(sprintf(
            '  De las revisadas: %d por una persona, %d por este lote, %d sin registro de procedencia.',
            $conteos['humana'],
            $conteos['automatica'],
            $conteos['sin_registro'],
        ));

        if ($conteos['reales_total'] > 0) {
            $this->line(sprintf(
                '  Sobre trafico real: %d de %d revisadas (%.1f %%). Esta es la cifra que el lote no puede mover.',
                $conteos['reales_triadas'],
                $conteos['reales_total'],
                $conteos['reales_triadas'] * 100 / $conteos['reales_total'],
            ));
        }

        $this->line('');
        $this->line('  Procedencia registrada en cada alerta: '.$actor);
    }

    private function resolverActor(): string
    {
        $actor = $this->option('actor');

        if (is_string($actor) && trim($actor) !== '') {
            // Se deja constancia de que el nombre lo dio quien lanzo el comando, no el sistema:
            // un actor declarado a mano vale menos como prueba que uno observado.
            return 'declarado en la linea de ordenes: '.trim($actor);
        }

        return TriajeAsistido::actorAutomatico($this->getName() ?? 'siem:triar-sinteticas');
    }
}
