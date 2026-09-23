<?php

namespace Tests\Feature\Siem;

use App\Models\AlertaSeguridad;
use App\Models\EventoSeguridad;
use App\Services\Siem\CalculadoraMetricas;
use App\Services\Siem\TriajeAsistido;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Contención en el borde: la alerta crítica que el cortafuegos cortó por completo se marca
 * contenida con la hora del bloqueo, no con la de la revisión.
 *
 * El caso que lo origino: dos alertas reales, confirmadas en masa desde el panel y luego
 * contenidas a mano dias despues, ponian el tiempo medio de contencion en tres horas. No
 * median una respuesta lenta: median la distancia entre un lote y una revision, sobre algo
 * que el WAF ya habia frenado en el momento.
 */
class ContenerBloqueadasTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function alertaBloqueada(array $atributos = [], bool $conRegla = true, bool $todoBloqueado = true): AlertaSeguridad
    {
        $this->n++;
        $corte = CarbonImmutable::parse('2026-09-20 10:00:00');

        $alerta = AlertaSeguridad::query()->create(array_merge([
            'clave_regla' => 'evento_critico_unico',
            'titulo' => 'Alerta bloqueada '.$this->n,
            'descripcion' => 'Prueba.',
            'severidad' => EventoSeguridad::SEVERIDAD_CRITICA,
            'estado' => AlertaSeguridad::ESTADO_NUEVA,
            'direccion_ip' => '203.0.113.'.$this->n,
            'detectada_en' => $corte->addMinutes(1),
            'evidencia' => [],
            'accion_recomendada' => 'Revisar.',
            'conteo_eventos' => 1,
            'huella_agrupacion' => 'bloq-'.$this->n,
            'es_demostracion' => false,
        ], $atributos));

        $evento = EventoSeguridad::query()->create([
            'fuente' => EventoSeguridad::FUENTE_WAF,
            'marca_tiempo' => $corte,
            'direccion_ip' => $alerta->direccion_ip,
            'severidad' => EventoSeguridad::SEVERIDAD_CRITICA,
            'puntuacion_anomalia' => 30,
            'fue_bloqueado' => $todoBloqueado,
            'codigo_respuesta' => $todoBloqueado ? 403 : 200,
            'identificadores_regla' => $conRegla ? ['942100'] : null,
            'es_demostracion' => false,
            'huella' => 'bloq-ev-'.$this->n,
        ]);

        $alerta->eventos()->attach($evento->id);

        return $alerta;
    }

    public function test_marca_contenida_con_la_hora_del_bloqueo(): void
    {
        $alerta = $this->alertaBloqueada();

        Artisan::call('siem:contener-bloqueadas');

        $fresca = $alerta->fresh();
        $this->assertSame(AlertaSeguridad::ESTADO_CONTENIDA, $fresca->estado);
        $this->assertSame('2026-09-20 10:00:00', $fresca->contenida_en->format('Y-m-d H:i:s'));
        $this->assertSame(TriajeAsistido::PROCEDENCIA_AUTOMATICA, $fresca->getAttribute('procedencia_triaje'));
    }

    public function test_corrige_una_marca_manual_posterior_al_corte(): void
    {
        // El caso real: confirmada en un lote y contenida a mano dos dias despues.
        $alerta = $this->alertaBloqueada([
            'estado' => AlertaSeguridad::ESTADO_CONTENIDA,
            'confirmada_en' => CarbonImmutable::parse('2026-09-22 08:00:00'),
            'contenida_en' => CarbonImmutable::parse('2026-09-22 08:05:00'),
        ]);

        Artisan::call('siem:contener-bloqueadas');

        // La contencion se reajusta a la hora del corte del WAF, anterior a la confirmacion.
        $this->assertSame('2026-09-20 10:00:00', $alerta->fresh()->contenida_en->format('Y-m-d H:i:s'));
    }

    public function test_la_correccion_invierte_el_par_para_que_la_metrica_lo_excluya(): void
    {
        // El promedio de contención usa TIMESTAMPDIFF, propio de MariaDB, y no corre sobre el
        // SQLite de las pruebas. Aquí se comprueba EL MECANISMO del que depende: el guardián
        // de la métrica excluye los pares con contenida_en < confirmada_en. La prueba fija
        // que tras la corrección ese par queda invertido en la alerta inflada y sigue
        // ascendente en la humana. El valor del promedio se verificó aparte sobre MariaDB.
        $ahora = CarbonImmutable::parse('2026-09-23 09:00:00');

        $humana = AlertaSeguridad::query()->create([
            'clave_regla' => 'x', 'titulo' => 'humana', 'descripcion' => 'x',
            'severidad' => EventoSeguridad::SEVERIDAD_ALTA, 'estado' => AlertaSeguridad::ESTADO_CONTENIDA,
            'direccion_ip' => '203.0.113.200', 'detectada_en' => $ahora->subHours(2),
            'confirmada_en' => $ahora->subHours(2), 'contenida_en' => $ahora->subHours(2)->addMinutes(20),
            'evidencia' => [], 'accion_recomendada' => 'x', 'conteo_eventos' => 1,
            'huella_agrupacion' => 'humana-1', 'es_demostracion' => false,
        ]);

        $inflada = $this->alertaBloqueada([
            'estado' => AlertaSeguridad::ESTADO_CONTENIDA,
            'detectada_en' => $ahora->subDays(3),
            'confirmada_en' => $ahora->subHours(48),
            'contenida_en' => $ahora->subHours(2),
        ]);

        // Antes: el par de la inflada es ascendente (46 h), así que la métrica lo incluiría.
        $this->assertTrue($inflada->contenida_en->greaterThan($inflada->confirmada_en));

        Artisan::call('siem:contener-bloqueadas', ['--dias' => 0]);

        // Después: la inflada tiene la contención antes de la confirmación -> el guardián la
        // saca. La humana sigue ascendente -> se queda.
        $this->assertTrue($inflada->fresh()->contenida_en->lessThan($inflada->fresh()->confirmada_en));
        $this->assertTrue($humana->fresh()->contenida_en->greaterThanOrEqualTo($humana->fresh()->confirmada_en));
    }

    public function test_no_toca_una_alerta_que_el_waf_no_corto_del_todo(): void
    {
        $alerta = $this->alertaBloqueada(todoBloqueado: false);

        Artisan::call('siem:contener-bloqueadas');

        $this->assertSame(AlertaSeguridad::ESTADO_NUEVA, $alerta->fresh()->estado);
    }

    public function test_no_toca_las_alertas_de_demostracion(): void
    {
        $alerta = $this->alertaBloqueada(['es_demostracion' => true]);
        // El evento tambien es de demostracion, para que soloReales la excluya de verdad.
        $alerta->eventos()->update(['es_demostracion' => true]);

        Artisan::call('siem:contener-bloqueadas');

        $this->assertSame(AlertaSeguridad::ESTADO_NUEVA, $alerta->fresh()->estado);
    }

    public function test_es_idempotente(): void
    {
        $this->alertaBloqueada();

        Artisan::call('siem:contener-bloqueadas');
        Artisan::call('siem:contener-bloqueadas');

        $this->assertSame(1, AlertaSeguridad::query()->where('estado', AlertaSeguridad::ESTADO_CONTENIDA)->count());
    }

    /**
     * @param  array<string, mixed>  $vertices
     * @return array<string, mixed>
     */
    private function contencion(array $vertices): array
    {
        foreach ($vertices['respuesta']['metricas'] as $metrica) {
            if ($metrica['clave'] === 'tiempo_medio_contencion') {
                return $metrica;
            }
        }

        return [];
    }
}
