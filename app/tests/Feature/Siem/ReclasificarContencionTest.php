<?php

namespace Tests\Feature\Siem;

use App\Models\AlertaSeguridad;
use App\Models\EventoSeguridad;
use App\Models\RegistroAuditoria;
use App\Models\Rol;
use App\Models\User;
use App\Services\Siem\CalculadoraMetricas;
use App\Services\Siem\TriajeAsistido;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Reclasificación de contenciones marcadas por error, con los controles que impiden usarla
 * para esconder una respuesta lenta de verdad.
 */
class ReclasificarContencionTest extends TestCase
{
    use RefreshDatabase;

    private const MOTIVO = 'Sondeo de paranoia 2 sin impacto, revisado en lote; no había amenaza que contener.';

    private int $n = 0;

    private User $auditor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);
        $this->auditor = User::factory()->create(['email' => 'auditora@marketgt.test', 'name' => 'Ana Auditora']);
        $this->auditor->asignarRol(Rol::AUDITOR);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function contenida(float $horas, array $extra = [], bool $bloqueado = true, ?int $codigo = null): AlertaSeguridad
    {
        $this->n++;
        $confirmada = CarbonImmutable::now()->subHours(50);

        $alerta = AlertaSeguridad::query()->create(array_merge([
            'clave_regla' => 'evento_critico_unico', 'titulo' => 'A'.$this->n, 'descripcion' => 'x',
            'severidad' => EventoSeguridad::SEVERIDAD_CRITICA, 'estado' => AlertaSeguridad::ESTADO_CONTENIDA,
            'direccion_ip' => '198.51.100.'.$this->n, 'detectada_en' => $confirmada->subMinutes(5),
            'confirmada_en' => $confirmada, 'contenida_en' => $confirmada->addMinutes((int) round($horas * 60)),
            'evidencia' => [], 'accion_recomendada' => 'x', 'conteo_eventos' => 1,
            'huella_agrupacion' => 'rc-'.$this->n, 'es_demostracion' => false,
        ], $extra));

        $evento = EventoSeguridad::query()->create([
            'fuente' => EventoSeguridad::FUENTE_WAF, 'marca_tiempo' => $confirmada->subMinutes(6),
            'direccion_ip' => $alerta->direccion_ip, 'severidad' => EventoSeguridad::SEVERIDAD_CRITICA,
            'puntuacion_anomalia' => $bloqueado ? 10 : 5, 'fue_bloqueado' => $bloqueado,
            'codigo_respuesta' => $codigo ?? ($bloqueado ? 403 : 200),
            'identificadores_regla' => ['942100'], 'identificador_transaccion' => 'tx-'.$this->n,
            'es_demostracion' => false, 'huella' => 'rc-ev-'.$this->n,
        ]);
        $alerta->eventos()->attach($evento->id);

        return $alerta;
    }

    /**
     * @param  array<int, int>  $ids
     * @param  array<string, mixed>  $extra
     */
    private function reclasificar(array $ids, array $extra = []): int
    {
        return Artisan::call('siem:reclasificar-contencion', array_merge([
            'ids' => array_map('strval', $ids),
            '--motivo' => self::MOTIVO,
            '--responsable' => 'auditora@marketgt.test',
        ], $extra));
    }

    private function metrica(): array
    {
        $ahora = CarbonImmutable::now();

        return app(CalculadoraMetricas::class)->metricaTiempoContencion($ahora->subDays(30), $ahora);
    }

    public function test_la_reclasificada_sale_del_promedio_y_la_metrica_lo_enumera(): void
    {
        $this->contenida(0.5);
        $ruido = $this->contenida(43.1);

        $this->assertSame(0, $this->reclasificar([$ruido->id]));

        $metrica = $this->metrica();
        $this->assertSame(30.0, $metrica['valor']);
        $this->assertSame(CalculadoraMetricas::CUMPLE, $metrica['estado']);
        $this->assertStringContainsString('1 reclasificadas', $metrica['origen']);
        $this->assertStringContainsString('#'.$ruido->id, $metrica['origen']);
        // Y la cifra con ellas dentro queda a la vista.
        $this->assertStringContainsString('Contando las reclasificadas, el promedio seria', (string) $metrica['advertencia']);
    }

    public function test_conserva_la_decision_original_y_firma_con_el_responsable(): void
    {
        $ruido = $this->contenida(40.4, ['notas_triaje' => 'Nota original del analista']);
        AlertaSeguridad::query()->whereKey($ruido->id)->update([
            'procedencia_triaje' => TriajeAsistido::PROCEDENCIA_HUMANA,
            'triado_por' => 'Diego (cuenta #1) desde acciones masivas',
            'triaje_criterio' => 'Criterio original',
        ]);
        $contenidaOriginal = $ruido->fresh()->contenida_en->toDateTimeString();

        $this->reclasificar([$ruido->id]);

        $fresca = $ruido->fresh();
        $this->assertSame($contenidaOriginal, $fresca->contenida_en->toDateTimeString());
        $this->assertSame(AlertaSeguridad::ESTADO_CERRADA, $fresca->estado);
        $this->assertStringContainsString('Ana Auditora', (string) $fresca->getAttribute('triado_por'));

        $asiento = RegistroAuditoria::query()->where('accion', 'alerta.contencion_reclasificada')->firstOrFail();
        $this->assertSame($this->auditor->id, $asiento->usuario_id);
        $this->assertSame('auditora@marketgt.test', $asiento->correo);
        // Lo que se pisó queda guardado entero.
        $this->assertSame('Diego (cuenta #1) desde acciones masivas', $asiento->detalle['antes']['triado_por']);
        $this->assertSame('Criterio original', $asiento->detalle['antes']['triaje_criterio']);
        $this->assertSame('Nota original del analista', $asiento->detalle['antes']['notas_triaje']);
    }

    public function test_se_puede_revertir_y_vuelve_a_contar(): void
    {
        $ruido = $this->contenida(43.1);
        $this->reclasificar([$ruido->id]);

        $codigo = $this->reclasificar([$ruido->id], ['--revertir' => true, '--motivo' => 'Revertido: la alerta sí tenía impacto, revisado de nuevo.']);

        $this->assertSame(0, $codigo);
        $this->assertSame(AlertaSeguridad::ESTADO_CONTENIDA, $ruido->fresh()->estado);
        $this->assertNull($ruido->fresh()->getAttribute('triaje_regla'));
        $this->assertSame(1, $this->metrica()['muestra']);
        $this->assertSame(1, RegistroAuditoria::query()->where('accion', 'alerta.reclasificacion_revertida')->count());
    }

    public function test_sin_responsable_o_con_uno_sin_rol_no_toca_nada(): void
    {
        $ruido = $this->contenida(42.2);
        $cliente = User::factory()->create(['email' => 'cliente@marketgt.test']);
        $cliente->asignarRol(Rol::CLIENTE);

        $this->assertSame(1, $this->reclasificar([$ruido->id], ['--responsable' => null]));
        $this->assertSame(1, $this->reclasificar([$ruido->id], ['--responsable' => 'cliente@marketgt.test']));
        $this->assertSame(AlertaSeguridad::ESTADO_CONTENIDA, $ruido->fresh()->estado);
    }

    public function test_sin_motivo_no_toca_nada(): void
    {
        $ruido = $this->contenida(42.2);

        $this->assertSame(1, $this->reclasificar([$ruido->id], ['--motivo' => null]));
        $this->assertSame(AlertaSeguridad::ESTADO_CONTENIDA, $ruido->fresh()->estado);
    }

    public function test_no_toca_lo_que_ya_siguio_su_ciclo(): void
    {
        $cerrada = $this->contenida(30, ['estado' => AlertaSeguridad::ESTADO_CERRADA]);

        $this->reclasificar([$cerrada->id]);

        $this->assertNull($cerrada->fresh()->getAttribute('triaje_regla'));
    }

    public function test_nunca_toca_las_de_credenciales(): void
    {
        $credencial = $this->contenida(30, ['clave_regla' => 'segundo_factor_fallido']);

        $this->reclasificar([$credencial->id]);

        $this->assertSame(AlertaSeguridad::ESTADO_CONTENIDA, $credencial->fresh()->estado);
    }

    public function test_si_algo_paso_el_waf_exige_haber_revisado_los_registros(): void
    {
        $paso = $this->contenida(40, [], bloqueado: false, codigo: 200);

        $this->reclasificar([$paso->id]);
        $this->assertSame(AlertaSeguridad::ESTADO_CONTENIDA, $paso->fresh()->estado);

        $this->reclasificar([$paso->id], ['--revisados-logs' => true]);
        $this->assertSame(AlertaSeguridad::ESTADO_CERRADA, $paso->fresh()->estado);
        $this->assertTrue(RegistroAuditoria::query()->firstOrFail()->detalle['revisados_logs']);
    }

    public function test_identificadores_mal_escritos_fallan_en_lugar_de_ignorarse(): void
    {
        $a = $this->contenida(40);
        $b = $this->contenida(41);

        $codigo = Artisan::call('siem:reclasificar-contencion', [
            'ids' => [$a->id.','.$b->id],
            '--motivo' => self::MOTIVO, '--responsable' => 'auditora@marketgt.test',
        ]);

        $this->assertSame(1, $codigo);
        $this->assertSame(AlertaSeguridad::ESTADO_CONTENIDA, $a->fresh()->estado);

        // Con el # delante, como aparece en el panel, sí vale.
        Artisan::call('siem:reclasificar-contencion', [
            'ids' => ['#'.$a->id], '--motivo' => self::MOTIVO, '--responsable' => 'auditora@marketgt.test',
        ]);
        $this->assertSame(AlertaSeguridad::ESTADO_CERRADA, $a->fresh()->estado);
    }

    public function test_una_marca_sin_asiento_no_saca_a_nadie_del_promedio(): void
    {
        $alerta = $this->contenida(30);
        // Alguien pone la marca directamente en la base, sin pasar por el comando.
        AlertaSeguridad::query()->whereKey($alerta->id)->update([
            'triaje_regla' => TriajeAsistido::REGLA_RECLASIFICADA_SIN_IMPACTO,
        ]);

        $metrica = $this->metrica();

        $this->assertSame(1, $metrica['muestra']);
        $this->assertStringContainsString('no tienen asiento de auditoria', (string) $metrica['advertencia']);
    }

    public function test_las_de_demostracion_se_declaran_en_el_origen(): void
    {
        $this->contenida(0.5, ['es_demostracion' => true]);
        $this->contenida(0.5);

        $this->assertStringContainsString('1 de demostracion', $this->metrica()['origen']);
    }

    public function test_una_demo_contenida_a_mano_no_cuenta_porque_su_confirmacion_es_sembrada(): void
    {
        // El caso real: demo en triaje con confirmacion sembrada horas atras, contenida a mano
        // desde el panel. Restar contra esa fecha daba cuarenta horas inventadas.
        $this->contenida(0.5);
        $demo = $this->contenida(40, ['es_demostracion' => true]);
        AlertaSeguridad::query()->whereKey($demo->id)->update(['procedencia_triaje' => TriajeAsistido::PROCEDENCIA_HUMANA]);

        $metrica = $this->metrica();

        $this->assertSame(30.0, $metrica['valor']);
        $this->assertStringContainsString('1 de demostracion contenidas a mano', $metrica['origen']);
    }

    public function test_sin_identificadores_solo_lista_y_no_cambia_nada(): void
    {
        $paso = $this->contenida(43.1, [], bloqueado: false, codigo: 200);

        $this->assertSame(0, Artisan::call('siem:reclasificar-contencion'));
        $salida = Artisan::output();

        $this->assertStringContainsString('#'.$paso->id, $salida);
        $this->assertStringContainsString('REVISAR REGISTROS', $salida);
        $this->assertSame(AlertaSeguridad::ESTADO_CONTENIDA, $paso->fresh()->estado);
    }
}
