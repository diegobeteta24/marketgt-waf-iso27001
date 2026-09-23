<?php

namespace Tests\Feature\Siem;

use App\Models\EstadoParche;
use App\Services\Siem\AnalizadorParches;
use App\Services\Siem\CalculadoraMetricas;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use ReflectionClass;
use Tests\TestCase;

/**
 * Banco de pruebas del control de parches del vertice de proteccion.
 *
 * Lo que se comprueba aqui no es que el codigo corra, sino que la metrica no mienta:
 * que el porcentaje salga siempre de filas con las dos fechas, que el desfase se pueda
 * reproducir desde la propia fila, y sobre todo que cuando falta el dato la metrica
 * vuelva a declararse SIN DATOS en lugar de devolver un cero que se lee como un cien.
 *
 * El camino de la ausencia tiene tantas pruebas como el camino del calculo a proposito:
 * un panel de auditoria se rompe por ahi, no por el promedio.
 */
class CoberturaParcheoTest extends TestCase
{
    use RefreshDatabase;

    /** Frontera de gestion de los accesorios: anterior a toda fecha de aplicacion usada aqui. */
    private const BAJO_GESTION_DESDE = '2026-08-25T00:00:00+00:00';

    private AnalizadorParches $analizador;

    /**
     * Archivos de inventario creados por la prueba, para borrarlos al terminar.
     *
     * @var array<int, string>
     */
    private array $temporales = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->analizador = app(AnalizadorParches::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }

        parent::tearDown();
    }

    public function test_la_tabla_vacia_se_declara_sin_datos_y_no_cero(): void
    {
        $metrica = $this->analizador->metrica();

        $this->assertSame(CalculadoraMetricas::SIN_DATOS, $metrica['estado']);
        $this->assertNull($metrica['valor']);
        $this->assertSame('sin datos', $metrica['valor_texto']);
        $this->assertSame(0, $metrica['muestra']);
        $this->assertStringContainsString('recolector del anfitrion todavia no ha escrito', $metrica['origen']);
    }

    public function test_el_arreglo_tiene_la_misma_forma_que_calculadora_metricas(): void
    {
        $reflexion = new ReflectionClass(CalculadoraMetricas::class);
        $calculadora = $reflexion->newInstance();

        $sinDatos = $reflexion->getMethod('sinDatos');
        $sinDatos->setAccessible(true);
        $medida = $reflexion->getMethod('medida');
        $medida->setAccessible(true);

        $referenciaSinDatos = $sinDatos->invoke($calculadora, 'x', 'X', 'meta', 'motivo');
        $referenciaMedida = $medida->invoke($calculadora, 'x', 'X', 'meta', 1.0, '%', true, 1, 'origen', null);

        $this->assertSame(array_keys($referenciaSinDatos), array_keys($this->analizador->metrica()));

        $this->ingerir([$this->parcheAplicado(['desfase_horas' => 12.0])]);

        $this->assertSame(array_keys($referenciaMedida), array_keys($this->analizador->metrica()));
    }

    public function test_calcula_el_porcentaje_solo_sobre_los_que_tienen_las_dos_fechas(): void
    {
        $this->ingerir([
            // Dentro del plazo.
            $this->parcheAplicado(['huella' => $this->huella('a'), 'paquete' => 'openssl', 'desfase_horas' => 12.0]),
            // Fuera del plazo.
            $this->parcheAplicado([
                'huella' => $this->huella('b'),
                'paquete' => 'curl',
                'publicado_en' => '2026-09-01T00:00:00+00:00',
                'aplicado_en' => '2026-09-20T00:00:00+00:00',
                'desfase_horas' => 456.0,
            ]),
            // Sin fecha de publicacion: cuenta en el total y NO en el plazo.
            $this->parcheAplicado([
                'huella' => $this->huella('c'),
                'paquete' => 'sudo',
                'publicado_en' => null,
                'desfase_horas' => null,
            ]),
        ]);

        $metrica = $this->analizador->metrica();

        $this->assertSame(50.0, $metrica['valor']);
        $this->assertSame(2, $metrica['muestra']);
        $this->assertSame(CalculadoraMetricas::INCUMPLE, $metrica['estado']);
        $this->assertStringContainsString('1 de 3 parches de seguridad aplicados no tiene fecha de publicacion', $metrica['origen']);
        $this->assertStringContainsString('cuenta en el total y no en el plazo', $metrica['origen']);
    }

    public function test_el_denominador_coincide_con_el_ambito_del_modelo(): void
    {
        $this->ingerir([
            $this->parcheAplicado(['huella' => $this->huella('a'), 'desfase_horas' => 12.0]),
            $this->parcheAplicado(['huella' => $this->huella('b'), 'paquete' => 'curl', 'publicado_en' => null, 'desfase_horas' => null]),
        ]);

        $delModelo = EstadoParche::query()->conPlazoMedible()->deSeguridad()->count();

        $this->assertSame($delModelo, $this->analizador->metrica()['muestra']);
    }

    public function test_el_desfase_guardado_se_reproduce_desde_las_dos_fechas_de_la_fila(): void
    {
        $this->ingerir([$this->parcheAplicado([
            'publicado_en' => '2026-09-01T00:00:00+00:00',
            'aplicado_en' => '2026-09-03T06:30:00+00:00',
            'desfase_horas' => 54.5,
        ])]);

        $fila = EstadoParche::query()->firstOrFail();
        $recalculado = round($fila->publicado_en->diffInSeconds($fila->aplicado_en, false) / 3600, 2);

        $this->assertEqualsWithDelta($recalculado, $fila->desfase_horas, 0.01);
    }

    public function test_un_desfase_declarado_que_no_cuadra_avisa_y_guarda_el_recalculado(): void
    {
        $salida = $this->ingerir([$this->parcheAplicado([
            'publicado_en' => '2026-09-01T00:00:00+00:00',
            'aplicado_en' => '2026-09-01T10:00:00+00:00',
            // El recolector dice una hora; las marcas de tiempo dicen diez.
            'desfase_horas' => 1.0,
        ])]);

        $this->assertStringContainsString('Se guarda la recalculada', $salida);
        $this->assertEqualsWithDelta(10.0, EstadoParche::query()->firstOrFail()->desfase_horas, 0.01);
    }

    public function test_un_parche_aplicado_antes_de_publicarse_queda_fuera_del_calculo(): void
    {
        $this->ingerir([
            $this->parcheAplicado(['huella' => $this->huella('a'), 'desfase_horas' => 12.0]),
            $this->parcheAplicado([
                'huella' => $this->huella('b'),
                'paquete' => 'curl',
                'publicado_en' => '2026-09-10T00:00:00+00:00',
                'aplicado_en' => '2026-09-05T00:00:00+00:00',
                'desfase_horas' => -120.0,
            ]),
        ]);

        $metrica = $this->analizador->metrica();

        $this->assertSame(1, $metrica['muestra']);
        $this->assertStringContainsString('antes de su fecha de publicacion', (string) $metrica['advertencia']);
        $this->assertStringContainsString('reloj del anfitrion', (string) $metrica['advertencia']);
    }

    public function test_sin_ningun_parche_de_seguridad_medible_vuelve_a_sin_datos(): void
    {
        $this->ingerir([
            $this->parcheAplicado(['huella' => $this->huella('a'), 'publicado_en' => null, 'desfase_horas' => null]),
            $this->parchePendiente(['huella' => $this->huella('b')]),
        ]);

        $metrica = $this->analizador->metrica();

        $this->assertSame(CalculadoraMetricas::SIN_DATOS, $metrica['estado']);
        $this->assertNull($metrica['valor']);
        $this->assertStringContainsString('ningun parche de seguridad tiene a la vez fecha de publicacion', $metrica['origen']);
        $this->assertStringContainsString('un pendiente no permite calcular un porcentaje', $metrica['origen']);
    }

    public function test_un_pendiente_vencido_incumple_aunque_el_porcentaje_sea_cien(): void
    {
        $this->ingerir([
            $this->parcheAplicado(['huella' => $this->huella('a'), 'desfase_horas' => 12.0]),
            $this->parchePendiente([
                'huella' => $this->huella('b'),
                'visto_pendiente_desde' => CarbonImmutable::now()->subHours(200)->toIso8601String(),
            ]),
        ]);

        $metrica = $this->analizador->metrica();

        $this->assertSame(100.0, $metrica['valor']);
        $this->assertSame(CalculadoraMetricas::INCUMPLE, $metrica['estado']);
        // El pendiente no tiene fecha de publicacion legible: no puede entrar en el plazo.
        $this->assertSame(1, $metrica['muestra']);
        $this->assertStringContainsString('el retraso real es como minimo ese', (string) $metrica['advertencia']);
    }

    public function test_la_transicion_de_pendiente_a_aplicado_conserva_la_primera_observacion(): void
    {
        $visto = '2026-09-14T14:32:53+00:00';

        $this->ingerir([$this->parchePendiente(['huella' => $this->huella('a'), 'visto_pendiente_desde' => $visto])]);
        $this->ingerir([$this->parcheAplicado([
            'huella' => $this->huella('a'),
            'visto_pendiente_desde' => null,
            'desfase_horas' => 12.0,
        ])]);

        $fila = EstadoParche::query()->firstOrFail();

        $this->assertSame(1, EstadoParche::query()->count());
        $this->assertSame(EstadoParche::ESTADO_APLICADO, $fila->estado);
        $this->assertSame('2026-09-14 14:32:53', $fila->visto_pendiente_desde->format('Y-m-d H:i:s'));
        $this->assertSame(1, $this->analizador->metrica()['muestra']);
    }

    public function test_la_segunda_pasada_sin_novedades_no_duplica_filas(): void
    {
        $inventario = $this->escribirInventario([$this->parcheAplicado(['desfase_horas' => 12.0])]);

        Artisan::call('siem:ingerir-parches', ['--archivo' => $inventario]);
        Artisan::call('siem:ingerir-parches', ['--archivo' => $inventario]);
        Artisan::call('siem:ingerir-parches', ['--archivo' => $inventario, '--reiniciar' => true]);

        $this->assertSame(1, EstadoParche::query()->count());
    }

    public function test_las_lineas_corruptas_no_contaminan_la_tabla_ni_detienen_la_ingesta(): void
    {
        $inventario = tempnam(sys_get_temp_dir(), 'parches');
        $this->temporales[] = $inventario;

        file_put_contents($inventario, implode("\n", [
            '{esto no es json',
            json_encode(['tipo' => 'parche', 'huella' => $this->huella('a'), 'estado' => 'aplicado']),
            json_encode(['tipo' => 'parche', 'huella' => $this->huella('b'), 'anfitrion' => 'h1', 'paquete' => 'x', 'version' => '1', 'estado' => 'inventado', 'recolectado_en' => CarbonImmutable::now()->toIso8601String()]),
        ])."\n");

        $codigo = Artisan::call('siem:ingerir-parches', ['--archivo' => $inventario]);

        $this->assertSame(0, $codigo);
        $this->assertSame(0, EstadoParche::query()->count());
    }

    public function test_una_fuente_que_el_recolector_no_pudo_leer_llega_al_operador(): void
    {
        $inventario = tempnam(sys_get_temp_dir(), 'parches');
        $this->temporales[] = $inventario;

        file_put_contents($inventario, json_encode([
            'tipo' => 'recoleccion',
            // Anterior a toda fecha de aplicacion de los accesorios: asi el parche cuenta
            // como aplicado BAJO GESTION y entra en el plazo, que es lo que estas pruebas
            // ejercitan. El camino del parche heredado tiene su propia prueba.
            'recolectado_en' => self::BAJO_GESTION_DESDE,
            'ejecutado_como_root' => false,
            'fuentes' => [['ruta' => '/var/log/dpkg.log', 'leida' => false, 'detalle' => 'Permission denied']],
            'avisos' => [],
        ])."\n");

        Artisan::call('siem:ingerir-parches', ['--archivo' => $inventario]);
        $salida = Artisan::output();

        // Un inventario corto por un permiso se lee como un problema de parcheo si nadie
        // lo dice. Son dos hallazgos distintos y el operador tiene que poder separarlos.
        $this->assertStringContainsString('El recolector no pudo leer /var/log/dpkg.log', $salida);
        $this->assertStringContainsString('no corrio como root', $salida);
    }

    public function test_un_inventario_viejo_se_declara_en_la_advertencia(): void
    {
        $this->ingerir([$this->parcheAplicado(['desfase_horas' => 12.0])]);

        EstadoParche::query()->update(['recolectado_en' => CarbonImmutable::now()->subHours(216)]);

        $this->assertStringContainsString(
            'se recolecto por ultima vez hace',
            (string) $this->analizador->metrica()['advertencia'],
        );
    }

    public function test_un_inventario_fechado_en_el_futuro_se_declara_en_lugar_de_callarse(): void
    {
        $this->ingerir([$this->parcheAplicado(['desfase_horas' => 12.0])]);

        EstadoParche::query()->update(['recolectado_en' => CarbonImmutable::now()->addDays(2)]);

        $this->assertStringContainsString(
            'despues de la hora actual',
            (string) $this->analizador->metrica()['advertencia'],
        );
    }

    public function test_un_inventario_ausente_se_declara_como_fallo(): void
    {
        $codigo = Artisan::call('siem:ingerir-parches', ['--archivo' => sys_get_temp_dir().'/no-existe-jamas.jsonl']);

        $this->assertSame(1, $codigo);
        $this->assertSame(CalculadoraMetricas::SIN_DATOS, $this->analizador->metrica()['estado']);
    }

    public function test_lo_no_clasificado_queda_fuera_del_calculo_y_se_declara(): void
    {
        $this->ingerir([
            $this->parcheAplicado(['huella' => $this->huella('a'), 'desfase_horas' => 12.0]),
            $this->parcheAplicado([
                'huella' => $this->huella('b'),
                'paquete' => 'dmsetup',
                'es_seguridad' => null,
                'desfase_horas' => 500.0,
            ]),
        ]);

        $metrica = $this->analizador->metrica();

        $this->assertSame(1, $metrica['muestra']);
        $this->assertSame(1, EstadoParche::query()->sinClasificar()->count());
        $this->assertStringContainsString('no se pudo clasificar como de seguridad', $metrica['origen']);
    }

    public function test_un_pendiente_que_deja_de_figurar_se_cierra_y_deja_de_hundir_la_metrica(): void
    {
        $huella = $this->huella('a');

        $this->ingerir([$this->parchePendiente([
            'huella' => $huella,
            'visto_pendiente_desde' => CarbonImmutable::now()->subHours(500)->toIso8601String(),
        ])]);

        $this->ingerir([[
            'tipo' => 'parche',
            'huella' => $huella,
            'anfitrion' => 'anfitrion-de-prueba',
            'estado' => EstadoParche::ESTADO_NO_APLICABLE,
            'nota_publicacion' => 'Retenido.',
            // Anterior a toda fecha de aplicacion de los accesorios: asi el parche cuenta
            // como aplicado BAJO GESTION y entra en el plazo, que es lo que estas pruebas
            // ejercitan. El camino del parche heredado tiene su propia prueba.
            'recolectado_en' => self::BAJO_GESTION_DESDE,
            'recolectado_por' => 'proceso:cron',
        ]]);

        $this->assertSame(EstadoParche::ESTADO_NO_APLICABLE, EstadoParche::query()->firstOrFail()->estado);
        $this->assertSame(0, $this->analizador->metrica()['muestra']);
    }


    public function test_un_servidor_recien_aprovisionado_no_se_hunde_por_los_parches_de_la_imagen(): void
    {
        // Lo que ve el recolector la primera vez que corre en una maquina nueva: todo lo
        // instalado se aplico antes de que el anfitrion existiera, porque lo aplico quien
        // construyo la imagen. Medir ese plazo mide al proveedor de la imagen.
        $this->ingerir([
            $this->parcheAplicado([
                "huella" => $this->huella("a"),
                "publicado_en" => "2026-07-01T00:00:00+00:00",
                "aplicado_en" => "2026-08-01T00:00:00+00:00",
                "desfase_horas" => 744.0,
                "recolectado_en" => CarbonImmutable::now()->toIso8601String(),
            ]),
        ]);

        $metrica = $this->analizador->metrica();

        // No incumple: nadie aqui pudo aplicar ese parche antes.
        $this->assertSame(CalculadoraMetricas::CUMPLE, $metrica["estado"]);
        $this->assertStringContainsString("quedan fuera del porcentaje", $metrica["origen"]);
        $this->assertStringContainsString("antes de que este anfitrion existiera", $metrica["origen"]);
        $this->assertStringContainsString("en cuanto se aplique el primer parche de seguridad bajo gestion", $metrica["origen"]);
    }

    public function test_un_pendiente_vencido_manda_aunque_todo_lo_demas_sea_heredado(): void
    {
        // El cumplimiento de la rama heredada se apoya en una sola cosa: que no haya
        // vulnerabilidad abierta. Si la hay, no hay nada que declarar cumplido.
        $this->ingerir([
            $this->parcheAplicado([
                "huella" => $this->huella("a"),
                "aplicado_en" => "2026-08-01T00:00:00+00:00",
                "desfase_horas" => 744.0,
                "recolectado_en" => CarbonImmutable::now()->toIso8601String(),
            ]),
            $this->parchePendiente([
                "huella" => $this->huella("b"),
                "visto_pendiente_desde" => CarbonImmutable::now()->subHours(300)->toIso8601String(),
                "recolectado_en" => CarbonImmutable::now()->toIso8601String(),
            ]),
        ]);

        $this->assertNotSame(CalculadoraMetricas::CUMPLE, $this->analizador->metrica()["estado"]);
    }

    public function test_una_laguna_de_datos_no_se_declara_cumplimiento(): void
    {
        // Aplicado BAJO gestion pero sin fecha de publicacion: no se puede medir el plazo,
        // y eso es una laguna, no una buena noticia.
        $this->ingerir([
            $this->parcheAplicado([
                "huella" => $this->huella("a"),
                "publicado_en" => null,
                "desfase_horas" => null,
            ]),
        ]);

        $this->assertSame(CalculadoraMetricas::SIN_DATOS, $this->analizador->metrica()["estado"]);
    }

    public function test_lo_publicado_antes_del_alta_cuenta_desde_el_alta(): void
    {
        // Publicado el 1 de agosto, el anfitrion entra en gestion el 25 y el parche se aplica
        // el 26: seiscientas horas desde la publicacion, veinticuatro desde el alta. Solo las
        // veinticuatro son imputables al equipo.
        $this->ingerir([$this->parcheAplicado([
            "publicado_en" => "2026-08-01T00:00:00+00:00",
            "aplicado_en" => "2026-08-26T00:00:00+00:00",
            "desfase_horas" => 600.0,
        ])]);

        $metrica = $this->analizador->metrica();

        $this->assertSame(CalculadoraMetricas::CUMPLE, $metrica["estado"]);
        $this->assertSame(100.0, $metrica["valor"]);
        $this->assertStringContainsString("se cuenta desde esa alta", $metrica["origen"]);
        // El desfase guardado sigue siendo el real desde la publicacion: la regla cambia
        // desde cuando se cuenta el plazo, no reescribe el hecho.
        $this->assertEqualsWithDelta(600.0, EstadoParche::query()->firstOrFail()->desfase_horas, 0.01);
    }

    public function test_lo_heredado_no_tiene_plazo_ilimitado(): void
    {
        // La regla no perdona el atraso heredado: le da las mismas 72 h desde el alta que a
        // cualquier parche desde su publicacion. Aplicado diez dias despues del alta, incumple.
        $this->ingerir([$this->parcheAplicado([
            "publicado_en" => "2026-08-01T00:00:00+00:00",
            "aplicado_en" => "2026-09-04T00:00:00+00:00",
            "desfase_horas" => 816.0,
        ])]);

        $metrica = $this->analizador->metrica();

        $this->assertSame(CalculadoraMetricas::INCUMPLE, $metrica["estado"]);
        $this->assertSame(0.0, $metrica["valor"]);
    }
    // ─── Ayudas de la prueba ────────────────────────────────────────────────

    /**
     * @param  array<int, array<string, mixed>>  $registros
     */
    private function ingerir(array $registros): string
    {
        Artisan::call('siem:ingerir-parches', ['--archivo' => $this->escribirInventario($registros)]);

        return Artisan::output();
    }

    /**
     * @param  array<int, array<string, mixed>>  $registros
     */
    private function escribirInventario(array $registros): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'parches');
        $this->temporales[] = $ruta;

        $lineas = array_map(
            static fn (array $registro): string => (string) json_encode($registro),
            $registros,
        );

        file_put_contents($ruta, implode("\n", $lineas)."\n");

        return $ruta;
    }

    /**
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function parcheAplicado(array $cambios = []): array
    {
        return array_merge([
            'tipo' => 'parche',
            'huella' => $this->huella('a'),
            'anfitrion' => 'anfitrion-de-prueba',
            'paquete' => 'openssl',
            'arquitectura' => 'amd64',
            'version' => '3.0.13-0ubuntu3.5',
            'version_anterior' => '3.0.13-0ubuntu3.4',
            'estado' => EstadoParche::ESTADO_APLICADO,
            'es_seguridad' => true,
            'publicado_en' => '2026-09-01T00:00:00+00:00',
            'fuente_publicacion' => '/usr/share/doc/openssl/changelog.Debian.gz (entrada 3.0.13-0ubuntu3.5, distribucion noble-security)',
            'identificadores_cve' => ['CVE-2026-1111'],
            'aplicado_en' => '2026-09-01T12:00:00+00:00',
            'fuente_aplicacion' => '/var/log/dpkg.log',
            'aplicado_por' => 'persona o automatismo externo (apt/dpkg)',
            'desfase_horas' => 12.0,
            // Anterior a toda fecha de aplicacion de los accesorios: asi el parche cuenta
            // como aplicado BAJO GESTION y entra en el plazo, que es lo que estas pruebas
            // ejercitan. El camino del parche heredado tiene su propia prueba.
            'recolectado_en' => self::BAJO_GESTION_DESDE,
            'recolectado_por' => 'proceso:cron',
            'version_recolector' => '1.0.0',
        ], $cambios);
    }

    /**
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function parchePendiente(array $cambios = []): array
    {
        return array_merge([
            'tipo' => 'parche',
            'huella' => $this->huella('b'),
            'anfitrion' => 'anfitrion-de-prueba',
            'paquete' => 'sudo',
            'arquitectura' => 'amd64',
            'version' => '1.9.15p5-3ubuntu5.24.04.3',
            'estado' => EstadoParche::ESTADO_PENDIENTE,
            'es_seguridad' => true,
            'origen_archivo' => 'Ubuntu:24.04/noble-security',
            'publicado_en' => null,
            'nota_publicacion' => 'El paquete no esta instalado.',
            'visto_pendiente_desde' => CarbonImmutable::now()->subHours(2)->toIso8601String(),
            // Anterior a toda fecha de aplicacion de los accesorios: asi el parche cuenta
            // como aplicado BAJO GESTION y entra en el plazo, que es lo que estas pruebas
            // ejercitan. El camino del parche heredado tiene su propia prueba.
            'recolectado_en' => self::BAJO_GESTION_DESDE,
            'recolectado_por' => 'proceso:cron',
            'version_recolector' => '1.0.0',
        ], $cambios);
    }

    private function huella(string $semilla): string
    {
        return str_repeat($semilla, 64);
    }
}
