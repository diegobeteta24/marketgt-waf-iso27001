<?php

namespace Tests\Feature\Siem;

use App\Models\AlertaSeguridad;
use App\Models\EventoSeguridad;
use App\Services\Siem\CalculadoraMetricas;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plazos de triaje del plan de respuesta a incidentes, seccion 8.
 *
 * La cobertura de triaje exigia un cien por cien instantaneo, lo que contradecia el plan
 * del propio proyecto: una alerta generada hace tres minutos contaba igual que una
 * olvidada tres dias. Estas pruebas fijan cada frontera de la tabla del plan, a los dos
 * lados, para que el plazo no se pueda aflojar sin que alguien lo note.
 */
class PlazoTriajeTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $ahora;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ahora = CarbonImmutable::parse('2026-09-26 09:00:00');
    }

    private function alerta(string $severidad, int $minutosAtras, string $estado = AlertaSeguridad::ESTADO_NUEVA): void
    {
        static $n = 0;
        $n++;

        AlertaSeguridad::query()->create([
            'clave_regla' => 'prueba_plazo',
            'titulo' => 'Alerta '.$n,
            'descripcion' => 'Prueba de plazo de triaje.',
            'severidad' => $severidad,
            'estado' => $estado,
            'direccion_ip' => '198.51.100.'.$n,
            'detectada_en' => $this->ahora->subMinutes($minutosAtras),
            'evidencia' => [],
            'accion_recomendada' => 'Revisar.',
            'conteo_eventos' => 1,
            'huella_agrupacion' => 'plazo-'.$n,
            'es_demostracion' => false,
        ]);
    }

    private function vencidas(): int
    {
        return app(CalculadoraMetricas::class)->alertasFueraDePlazoDeTriaje(
            $this->ahora->subDays(30),
            $this->ahora,
        );
    }

    public function test_una_critica_tiene_quince_minutos(): void
    {
        $this->alerta(EventoSeguridad::SEVERIDAD_CRITICA, 10);
        $this->assertSame(0, $this->vencidas());

        $this->alerta(EventoSeguridad::SEVERIDAD_CRITICA, 20);
        $this->assertSame(1, $this->vencidas());
    }

    public function test_una_alta_tiene_una_hora(): void
    {
        $this->alerta(EventoSeguridad::SEVERIDAD_ALTA, 45);
        $this->assertSame(0, $this->vencidas());

        $this->alerta(EventoSeguridad::SEVERIDAD_ALTA, 90);
        $this->assertSame(1, $this->vencidas());
    }

    public function test_una_media_tiene_cuatro_horas(): void
    {
        $this->alerta(EventoSeguridad::SEVERIDAD_MEDIA, 180);
        $this->assertSame(0, $this->vencidas());

        $this->alerta(EventoSeguridad::SEVERIDAD_MEDIA, 300);
        $this->assertSame(1, $this->vencidas());
    }

    public function test_una_baja_tiene_hasta_el_siguiente_turno(): void
    {
        $this->alerta(EventoSeguridad::SEVERIDAD_BAJA, 20 * 60);
        $this->assertSame(0, $this->vencidas());

        $this->alerta(EventoSeguridad::SEVERIDAD_BAJA, 25 * 60);
        $this->assertSame(1, $this->vencidas());
    }

    public function test_una_alerta_revisada_nunca_esta_vencida(): void
    {
        // Revisada tarde sigue siendo revisada: esta metrica mide lo que quedo sin mirar,
        // no la puntualidad, que tiene su propia medida en el tiempo de deteccion.
        $this->alerta(EventoSeguridad::SEVERIDAD_CRITICA, 3 * 24 * 60, AlertaSeguridad::ESTADO_EN_TRIAJE);

        $this->assertSame(0, $this->vencidas());
    }

    public function test_una_severidad_desconocida_recibe_el_plazo_mas_estricto(): void
    {
        // Un dato raro tiene que hacer saltar la metrica, no escapar de ella.
        $this->alerta('inventada', 20);

        $this->assertSame(1, $this->vencidas());
    }

    public function test_lo_que_esta_fuera_de_la_ventana_no_cuenta(): void
    {
        $this->alerta(EventoSeguridad::SEVERIDAD_CRITICA, 31 * 24 * 60);

        $this->assertSame(0, $this->vencidas());
    }
}
