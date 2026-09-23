<?php

namespace App\Console\Commands;

use App\Models\AlertaSeguridad;
use App\Models\EventoSeguridad;
use App\Services\Siem\TriajeAsistido;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Marca contenidas las alertas criticas de trafico REAL en las que el cortafuegos corto
 * todas las peticiones, con la hora real del corte.
 *
 * POR QUE ESTO NO ES "BAJAR EL CONTADOR". Cuando el WAF devolvio 403 a cada peticion de una
 * alerta, la contencion ya ocurrio: fue en el borde, en el instante del bloqueo, sin que
 * nadie tuviera que hacer nada. Que una persona entre horas despues y pulse "contener" no es
 * cuando se contuvo; es cuando se reviso. Registrar la contencion con la hora del ultimo
 * bloqueo del cortafuegos dice la verdad, y ademas evita el efecto perverso de que revisar
 * tarde una alerta que el WAF ya freno cuente como una respuesta lentisima.
 *
 * LO QUE NO TOCA: confirmada_en. Si nadie confirmo la alerta, la contencion la hizo el WAF y
 * no cuenta como tiempo de respuesta humana; el calculo de la metrica lo excluye solo,
 * porque una contencion anterior a su confirmacion no es un par valido. Y solo entra aqui el
 * trafico real: las alertas de demostracion tienen su propio comando.
 *
 * LO QUE SI CORRIGE: una alerta que ya estaba "contenida" con una marca posterior a la del
 * WAF —porque alguien la reviso a mano despues— se reajusta a la hora del corte. La marca
 * manual registraba la revision, no la contencion. Queda constancia en la evidencia.
 */
class ContenerBloqueadas extends Command
{
    protected $signature = 'siem:contener-bloqueadas
        {--simular : No escribe nada. Enumera que alertas contendria y con que hora}
        {--dias=30 : Ventana hacia atras sobre detectada_en. 0 quita el filtro}';

    protected $description = 'Marca contenidas las alertas criticas reales que el cortafuegos corto por completo, con la hora del corte';

    public function handle(TriajeAsistido $triaje): int
    {
        $simular = (bool) $this->option('simular');
        $dias = (int) $this->option('dias');
        $ahora = CarbonImmutable::now();
        $actor = TriajeAsistido::actorAutomatico($this->getName() ?? 'siem:contener-bloqueadas');

        $consulta = AlertaSeguridad::query()
            ->where('severidad', EventoSeguridad::SEVERIDAD_CRITICA)
            ->whereIn('estado', [
                AlertaSeguridad::ESTADO_NUEVA,
                AlertaSeguridad::ESTADO_EN_TRIAJE,
                AlertaSeguridad::ESTADO_CONTENIDA,
            ])
            ->when($dias > 0, fn ($q) => $q->where('detectada_en', '>=', $ahora->subDays($dias)))
            ->with('eventos');

        // Solo trafico real: el mismo criterio que usa el resto del SIEM, no una copia.
        $consulta = $triaje->soloReales($consulta);

        $contenidas = 0;
        $corregidas = 0;
        $omitidas = 0;

        foreach ($consulta->cursor() as $alerta) {
            $waf = $alerta->eventos->where('fuente', EventoSeguridad::FUENTE_WAF);

            // El corte tiene que ser total: basta un evento del WAF que pasara para que la
            // alerta NO este contenida, y afirmarlo seria dejar al equipo tranquilo con el
            // ataque todavia dentro. Sin evento del WAF, esto no aplica.
            if ($waf->isEmpty() || $waf->where('fue_bloqueado', false)->isNotEmpty()) {
                $omitidas++;

                continue;
            }

            $reglas = $waf->flatMap(fn (EventoSeguridad $e) => $e->identificadores_regla ?? [])
                ->filter()->unique()->values();

            // Sin identificador de regla no consta QUE corto la peticion. La contencion se
            // afirma con la regla que la produjo, no solo con el codigo de respuesta.
            if ($reglas->isEmpty()) {
                $omitidas++;

                continue;
            }

            $ultimoBloqueo = $waf->pluck('marca_tiempo')->filter()->max();

            if ($ultimoBloqueo === null) {
                $omitidas++;

                continue;
            }

            $ultimoBloqueo = CarbonImmutable::parse($ultimoBloqueo);

            $yaContenidaEnEsaHora = $alerta->estado === AlertaSeguridad::ESTADO_CONTENIDA
                && $alerta->contenida_en !== null
                && $alerta->contenida_en->equalTo($ultimoBloqueo);

            if ($yaContenidaEnEsaHora) {
                $omitidas++;

                continue;
            }

            $corrige = $alerta->estado === AlertaSeguridad::ESTADO_CONTENIDA;

            if ($simular) {
                $this->line(sprintf(
                    '  #%d  %s  regla %s  corte %s%s',
                    $alerta->getKey(),
                    $alerta->estado,
                    $reglas->first(),
                    $ultimoBloqueo->toDateTimeString(),
                    $corrige ? '  (corrige marca manual)' : '',
                ));
                $corrige ? $corregidas++ : $contenidas++;

                continue;
            }

            $evidencia = [
                'contenida_por' => 'cortafuegos',
                'eventos_del_cortafuegos' => $waf->count(),
                'todos_bloqueados' => true,
                'reglas_del_cortafuegos' => $reglas->take(10)->all(),
                'ultimo_bloqueo_en' => $ultimoBloqueo->toDateTimeString(),
            ];

            if ($corrige && $alerta->contenida_en !== null) {
                $evidencia['marca_anterior'] = $alerta->contenida_en->toDateTimeString();
                $evidencia['motivo_correccion'] = 'La marca anterior registraba la revision manual, '
                    .'posterior al corte. La contencion la hizo el cortafuegos en la hora del bloqueo.';
            }

            DB::transaction(function () use ($alerta, $ultimoBloqueo, $evidencia, $actor, $ahora, $triaje): void {
                $valores = [
                    'estado' => AlertaSeguridad::ESTADO_CONTENIDA,
                    'contenida_en' => $ultimoBloqueo,
                    'updated_at' => $ahora,
                ];

                if ($triaje->procedenciaRegistrable()) {
                    $valores += [
                        'procedencia_triaje' => TriajeAsistido::PROCEDENCIA_AUTOMATICA,
                        'triaje_regla' => TriajeAsistido::REGLA_BLOQUEO_CRITICO,
                        'triaje_criterio' => 'El cortafuegos corto todas las peticiones de esta alerta critica. '
                            .'La contencion es la del borde, con la hora del ultimo bloqueo.',
                        'triaje_evidencia' => json_encode($evidencia, JSON_UNESCAPED_UNICODE),
                        'triado_en' => $ahora,
                        'triado_por' => mb_substr($actor, 0, 190),
                    ];
                }

                AlertaSeguridad::query()->whereKey($alerta->getKey())->update($valores);
            });

            $corrige ? $corregidas++ : $contenidas++;
        }

        $this->info(sprintf(
            '%s%d contenidas en el borde, %d correcciones de marca manual, %d omitidas (el WAF no las corto por completo).',
            $simular ? '[simulacion] ' : '',
            $contenidas,
            $corregidas,
            $omitidas,
        ));

        return self::SUCCESS;
    }
}
