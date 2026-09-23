<?php

namespace Tests\Feature\Siem;

use App\Livewire\Siem\AccionesMasivas;
use App\Models\AlertaSeguridad;
use App\Models\EventoSeguridad;
use App\Models\Rol;
use App\Models\User;
use App\Services\Siem\TriajeAsistido;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Banco de pruebas del control de triaje.
 *
 * Lo que se comprueba aqui no es que el codigo corra, sino que ninguna cifra salga de otro
 * sitio que un hecho registrado: que la procedencia la escriba quien de verdad decidio, que
 * el instante de contencion sea el del corte del cortafuegos y no el del lote, y que cuando
 * falta el dato la metrica se declare sin datos en lugar de devolver cero o cien.
 *
 * Las pruebas del camino de la ausencia son tantas como las del calculo a proposito: por ahi
 * se rompe un panel de auditoria, no por el promedio.
 */
class TriajeAsistidoTest extends TestCase
{
    use RefreshDatabase;

    private function analista(): User
    {
        $rol = Rol::query()->create([
            'nombre' => Rol::AUDITOR,
            'etiqueta' => 'Auditor',
            'descripcion' => 'Lee el centro de monitoreo.',
        ]);

        $usuario = User::factory()->create(['name' => 'Ana Lista']);
        $usuario->roles()->attach($rol->id);

        return $usuario;
    }

    private function cliente(): User
    {
        $rol = Rol::query()->create([
            'nombre' => Rol::CLIENTE,
            'etiqueta' => 'Cliente',
            'descripcion' => 'Compra en la tienda.',
        ]);

        $usuario = User::factory()->create(['name' => 'Carlos Cliente']);
        $usuario->roles()->attach($rol->id);

        return $usuario;
    }

    /**
     * @param  array<int, string>|null  $reglas
     */
    private function alerta(
        bool $demostracion,
        string $severidad = 'alta',
        int $conteo = 3,
        ?array $reglas = null,
        ?string $ip = null,
        bool $bloqueado = true,
        bool $eventoDemostracion = true,
        bool $conEventos = true,
        ?CarbonImmutable $corte = null,
    ): AlertaSeguridad {
        static $n = 0;
        $n++;

        $direccion = $ip ?? '45.33.32.'.$n;
        $corte ??= CarbonImmutable::now()->subHour();

        $alerta = AlertaSeguridad::query()->create([
            'clave_regla' => 'prueba_masiva',
            'titulo' => 'Alerta de prueba '.$n,
            'descripcion' => 'Sirve para comprobar el triaje.',
            'severidad' => $severidad,
            'estado' => AlertaSeguridad::ESTADO_NUEVA,
            'direccion_ip' => $direccion,
            'detectada_en' => CarbonImmutable::now()->subHour(),
            'evidencia' => [],
            'accion_recomendada' => 'Revisar.',
            'conteo_eventos' => $conteo,
            'huella_agrupacion' => 'masiva-'.$n,
            'es_demostracion' => $demostracion,
        ]);

        if (! $conEventos) {
            return $alerta;
        }

        $evento = EventoSeguridad::query()->create([
            'fuente' => EventoSeguridad::FUENTE_WAF,
            'marca_tiempo' => $corte,
            'direccion_ip' => $direccion,
            'severidad' => $severidad,
            'puntuacion_anomalia' => 10,
            'fue_bloqueado' => $bloqueado,
            'codigo_respuesta' => $bloqueado ? 403 : 200,
            'identificadores_regla' => $reglas,
            'es_demostracion' => $eventoDemostracion,
            'huella' => 'masiva-evento-'.$n,
        ]);

        $alerta->eventos()->attach($evento->id);

        return $alerta;
    }

    // ---------------------------------------------------------------- acciones masivas

    public function test_lista_solo_alertas_reales_por_defecto(): void
    {
        $this->actingAs($this->analista());

        $real = $this->alerta(demostracion: false, eventoDemostracion: false);
        $demo = $this->alerta(demostracion: true);

        Livewire::test(AccionesMasivas::class)
            ->assertSee($real->titulo)
            ->assertDontSee($demo->titulo);
    }

    public function test_exige_nota_al_marcar_falso_positivo(): void
    {
        $this->actingAs($this->analista());

        $alerta = $this->alerta(demostracion: false, eventoDemostracion: false);

        Livewire::test(AccionesMasivas::class)
            ->set('seleccionadas', [(string) $alerta->id])
            ->set('destino', AlertaSeguridad::ESTADO_FALSO_POSITIVO)
            ->set('nota', '')
            ->call('aplicar')
            ->assertHasErrors(['nota' => 'required']);

        $this->assertSame(AlertaSeguridad::ESTADO_NUEVA, $alerta->fresh()->estado);
    }

    public function test_rechaza_una_nota_demasiado_corta(): void
    {
        $this->actingAs($this->analista());

        $alerta = $this->alerta(demostracion: false, eventoDemostracion: false);

        Livewire::test(AccionesMasivas::class)
            ->set('seleccionadas', [(string) $alerta->id])
            ->set('destino', AlertaSeguridad::ESTADO_FALSO_POSITIVO)
            ->set('nota', 'ruido')
            ->call('aplicar')
            ->assertHasErrors(['nota' => 'min']);
    }

    public function test_marca_en_lote_y_registra_procedencia_humana(): void
    {
        $analista = $this->analista();
        $this->actingAs($analista);

        $primera = $this->alerta(demostracion: false, eventoDemostracion: false);
        $segunda = $this->alerta(demostracion: false, eventoDemostracion: false);

        $nota = 'Peticiones del rastreador legitimo, verificadas por PTR inverso.';

        Livewire::test(AccionesMasivas::class)
            ->set('seleccionadas', [(string) $primera->id, (string) $segunda->id])
            ->set('destino', AlertaSeguridad::ESTADO_FALSO_POSITIVO)
            ->set('nota', $nota)
            ->call('aplicar')
            ->assertHasNoErrors();

        foreach ([$primera, $segunda] as $alerta) {
            $fresca = $alerta->fresh();

            $this->assertSame(AlertaSeguridad::ESTADO_FALSO_POSITIVO, $fresca->estado);
            $this->assertSame(TriajeAsistido::PROCEDENCIA_HUMANA, $fresca->getAttribute('procedencia_triaje'));
            $this->assertSame($nota, $fresca->getAttribute('triaje_criterio'));
            $this->assertSame($nota, $fresca->notas_triaje);
            $this->assertSame($analista->id, $fresca->atendida_por);
            $this->assertStringContainsString('Ana Lista', (string) $fresca->getAttribute('triado_por'));
            $this->assertStringContainsString('acciones masivas', (string) $fresca->getAttribute('triado_por'));
            $this->assertNotNull($fresca->getAttribute('triado_en'));
            $this->assertNotNull($fresca->cerrada_en);
            // Un falso positivo no es una confirmacion: nadie dijo que el ataque fuera real.
            $this->assertNull($fresca->confirmada_en);
        }
    }

    public function test_omite_las_transiciones_no_permitidas(): void
    {
        $this->actingAs($this->analista());

        $nueva = $this->alerta(demostracion: false, eventoDemostracion: false);
        $enTriaje = $this->alerta(demostracion: false, eventoDemostracion: false);
        $enTriaje->cambiarEstado(AlertaSeguridad::ESTADO_EN_TRIAJE);

        // "contenida" no se alcanza desde "nueva": esa alerta debe quedarse como estaba.
        Livewire::test(AccionesMasivas::class)
            ->set('filtroEstado', 'abiertas')
            ->set('seleccionadas', [(string) $nueva->id, (string) $enTriaje->id])
            ->set('destino', AlertaSeguridad::ESTADO_CONTENIDA)
            ->set('nota', 'Contenidas tras bloquear la direccion en el cortafuegos.')
            ->call('aplicar')
            ->assertHasNoErrors();

        $this->assertSame(AlertaSeguridad::ESTADO_NUEVA, $nueva->fresh()->estado);
        $this->assertSame(AlertaSeguridad::ESTADO_CONTENIDA, $enTriaje->fresh()->estado);
        $this->assertNull($nueva->fresh()->getAttribute('procedencia_triaje'));
    }

    public function test_un_cliente_no_puede_triar(): void
    {
        $this->actingAs($this->cliente());

        $alerta = $this->alerta(demostracion: false, eventoDemostracion: false);

        Livewire::test(AccionesMasivas::class)
            ->set('seleccionadas', [(string) $alerta->id])
            ->set('destino', AlertaSeguridad::ESTADO_EN_TRIAJE)
            ->call('aplicar')
            ->assertForbidden();

        $this->assertSame(AlertaSeguridad::ESTADO_NUEVA, $alerta->fresh()->estado);
    }

    public function test_las_cifras_se_muestran_por_separado(): void
    {
        $analista = $this->analista();
        $this->actingAs($analista);

        $humana = $this->alerta(demostracion: false, eventoDemostracion: false);
        $humana->cambiarEstado(AlertaSeguridad::ESTADO_EN_TRIAJE, $analista->id);
        app(TriajeAsistido::class)->registrarTriajeHumano($humana, $analista, 'prueba');

        // Critica y con la regla del cortafuegos que la corto: encaja en la primera regla.
        $lote = $this->alerta(demostracion: true, severidad: 'critica', conteo: 1, reglas: ['942100']);
        app(TriajeAsistido::class)->registrarTriajeAutomatico(
            $lote,
            app(TriajeAsistido::class)->clasificar($lote),
            'comando de prueba',
        );

        $sinRegistro = $this->alerta(demostracion: false, eventoDemostracion: false);
        $sinRegistro->cambiarEstado(AlertaSeguridad::ESTADO_CERRADA, $analista->id);

        $conteos = app(TriajeAsistido::class)->conteosProcedencia();

        $this->assertSame(1, $conteos['humana']);
        $this->assertSame(1, $conteos['automatica']);
        $this->assertSame(1, $conteos['sin_registro']);
        $this->assertSame(3, $conteos['triadas']);

        // El panel tiene que ensenar las tres cifras por separado, no solo el total.
        Livewire::test(AccionesMasivas::class)
            ->assertSee('A mano')
            ->assertSee('Por lote')
            ->assertSee('Sin procedencia')
            ->assertSee('Sin revisar')
            ->assertSee('Criterio del triaje automatico')
            ->assertSee('se deja para revision humana')
            ->assertSee('No se reparten entre las otras dos cifras')
            ->assertSee('Sobre trafico real');
    }

    /**
     * Una pantalla que no cuelga de ninguna ruta no la ve nadie. Esta prueba existe porque el
     * panel de procedencia estuvo escrito y montado en ningun sitio: el codigo pasaba las
     * pruebas de unidad y el auditor seguia sin poder abrirlo.
     */
    public function test_el_reparto_por_procedencia_se_ve_en_la_pagina_de_alertas(): void
    {
        $this->actingAs($this->analista());

        $this->alerta(demostracion: false, eventoDemostracion: false);

        // La pagina tiene que montarlo. No se comprueba pidiendo la ruta porque el otro
        // componente de esa pantalla ordena con FIELD(), que es propio de MariaDB y revienta
        // sobre el SQLite del conjunto de pruebas; eso es un defecto suyo, no de este control.
        $pagina = (string) file_get_contents(resource_path('views/pages/siem/⚡alertas.blade.php'));

        $this->assertStringContainsString('<livewire:siem.acciones-masivas', $pagina);

        Livewire::test(AccionesMasivas::class)
            ->assertOk()
            ->assertSee('Cobertura de triaje por procedencia')
            ->assertSee('Acciones masivas')
            ->assertSee('Criterio del triaje automatico');
    }

    public function test_el_panel_ensena_la_evidencia_de_cada_decision(): void
    {
        $this->actingAs($this->analista());

        $lote = $this->alerta(demostracion: true, severidad: 'critica', conteo: 1, reglas: ['932115']);

        $triaje = app(TriajeAsistido::class);
        $triaje->registrarTriajeAutomatico(
            $lote,
            $triaje->clasificar($lote),
            TriajeAsistido::actorAutomatico('siem:triar-sinteticas'),
        );

        Livewire::test(AccionesMasivas::class)
            ->assertSee('Ultimas decisiones y su procedencia')
            ->assertSee('triada por el lote automatico')
            ->assertSee(TriajeAsistido::REGLA_BLOQUEO_CRITICO)
            // El identificador de la regla del cortafuegos que corto la peticion, tal como lo
            // ingirio siem:ingerir-waf. Es el hecho del que cuelga la decision.
            ->assertSee('932115')
            ->assertSee('siem:triar-sinteticas')
            // Y la ventana con la que se calculo el porcentaje, para poder rehacer la consulta.
            ->assertSee('Ventana de observacion: ultimos 30 dias');
    }

    // ---------------------------------------------------------------- de donde sale el numero

    public function test_la_contencion_se_fecha_con_el_corte_del_cortafuegos_no_con_el_lote(): void
    {
        $corte = CarbonImmutable::now()->subHours(6);

        $alerta = $this->alerta(demostracion: true, severidad: 'critica', conteo: 1, reglas: ['932115'], corte: $corte);

        // Una confirmacion humana ANTERIOR al corte: con ella el par si se puede restar.
        $alerta->forceFill(['confirmada_en' => $corte->subMinutes(20)])->save();

        $triaje = app(TriajeAsistido::class);
        $this->assertTrue($triaje->registrarTriajeAutomatico($alerta, $triaje->clasificar($alerta), 'comando de prueba'));

        $fresca = $alerta->fresh();

        $this->assertSame(AlertaSeguridad::ESTADO_CONTENIDA, $fresca->estado);
        // La marca es la del ultimo evento bloqueado, no "ahora".
        $this->assertSame($corte->toDateTimeString(), $fresca->contenida_en?->toDateTimeString());
        $this->assertSame(20.0, $fresca->minutosHastaContencion());

        $evidencia = json_decode((string) $fresca->getAttribute('triaje_evidencia'), true);
        $this->assertSame($corte->toDateTimeString(), $evidencia['ultimo_bloqueo_en']);
        $this->assertTrue($evidencia['contenida_en_sellada']);
    }

    /**
     * La regresion que motivo este control: el lote no confirma nada, asi que deja
     * confirmada_en vacia. Si ademas sellara contenida_en, el dia que una persona cerrara la
     * alerta el modelo sellaria la confirmacion con la hora del cierre, POSTERIOR al corte, y
     * el tiempo medio de contencion del panel pasaria a incluir una resta negativa que nadie
     * midio. Medido sobre la base de demostracion: -338 minutos en una sola alerta.
     */
    public function test_el_lote_no_sella_la_contencion_cuando_nadie_confirmo_la_alerta(): void
    {
        $corte = CarbonImmutable::now()->subHours(6);

        $alerta = $this->alerta(demostracion: true, severidad: 'critica', conteo: 1, reglas: ['932115'], corte: $corte);

        $triaje = app(TriajeAsistido::class);
        $triaje->registrarTriajeAutomatico($alerta, $triaje->clasificar($alerta), 'comando de prueba');

        $fresca = $alerta->fresh();

        $this->assertSame(AlertaSeguridad::ESTADO_CONTENIDA, $fresca->estado);
        $this->assertNull($fresca->confirmada_en);
        $this->assertNull($fresca->contenida_en);

        // El instante medido no se pierde: queda en la evidencia con el motivo de no sellarlo.
        $evidencia = json_decode((string) $fresca->getAttribute('triaje_evidencia'), true);
        $this->assertFalse($evidencia['contenida_en_sellada']);
        $this->assertSame($corte->toDateTimeString(), $evidencia['contencion_observada_en']);
        $this->assertStringContainsString('al reves', $evidencia['motivo_sin_sellar']);

        // Y al cerrarla una persona, el par que resta la metrica sigue sin existir.
        $fresca->cambiarEstado(AlertaSeguridad::ESTADO_CERRADA, null, 'Cerrada tras el informe.');

        $cerrada = $alerta->fresh();
        $this->assertNotNull($cerrada->confirmada_en);
        $this->assertNull($cerrada->contenida_en);
        $this->assertNull($cerrada->minutosHastaContencion());

        $invertidas = AlertaSeguridad::query()
            ->whereNotNull('confirmada_en')
            ->whereNotNull('contenida_en')
            ->whereColumn('contenida_en', '<', 'confirmada_en')
            ->count();

        $this->assertSame(0, $invertidas, 'Una contencion anterior a su propia confirmacion produce un tiempo de respuesta negativo.');
    }

    public function test_la_repeticion_se_consulta_contra_la_base_no_se_supone(): void
    {
        $triaje = app(TriajeAsistido::class);

        $sola = $this->alerta(demostracion: true, severidad: 'baja', conteo: 1, ip: '203.0.113.9');
        $decision = $triaje->clasificar($sola);

        $this->assertSame(TriajeAsistido::REGLA_EVENTO_UNICO_MENOR, $decision['regla']);
        $this->assertSame(AlertaSeguridad::ESTADO_FALSO_POSITIVO, $decision['estado']);
        $this->assertSame(0, $decision['evidencia']['alertas_repetidas_de_la_misma_regla']);

        // Otra alerta de la misma regla y la misma direccion: deja de ser un acierto aislado.
        $this->alerta(demostracion: true, severidad: 'baja', conteo: 1, ip: '203.0.113.9');

        $repetida = $triaje->clasificar($sola->fresh());

        $this->assertNotSame(TriajeAsistido::REGLA_EVENTO_UNICO_MENOR, $repetida['regla']);
    }

    public function test_los_rangos_de_documentacion_se_comparan_como_numeros(): void
    {
        $casos = [
            '192.0.2.1' => true,
            '198.51.100.7' => true,
            '203.0.113.255' => true,
            '203.0.114.1' => false,
            '192.0.3.1' => false,
            '45.33.32.156' => false,
            '2001:db8::1' => false,
            'no-es-ip' => false,
            // El caso que una comparacion de prefijos de texto habria aceptado por error.
            '203.0.1130' => false,
        ];

        foreach ($casos as $direccion => $esperado) {
            $this->assertSame(
                $esperado,
                TriajeAsistido::enRangoDocumentacion((string) $direccion),
                'Fallo el rango de '.$direccion,
            );
        }
    }

    // ---------------------------------------------------------------- el camino de la ausencia

    public function test_una_alerta_que_no_encaja_en_ninguna_regla_se_queda_como_esta(): void
    {
        // Critica, pero una peticion atraveso el cortafuegos: la contencion no es cierta.
        $alerta = $this->alerta(
            demostracion: true,
            severidad: 'critica',
            conteo: 1,
            reglas: ['942100'],
            ip: '45.33.32.200',
            bloqueado: false,
        );

        $triaje = app(TriajeAsistido::class);
        $decision = $triaje->clasificar($alerta);

        $this->assertSame(TriajeAsistido::SIN_ENCAJE, $decision['regla']);
        $this->assertNull($decision['estado']);
        $this->assertFalse($triaje->registrarTriajeAutomatico($alerta, $decision, 'comando de prueba'));

        $fresca = $alerta->fresh();
        $this->assertSame(AlertaSeguridad::ESTADO_NUEVA, $fresca->estado);
        $this->assertNull($fresca->getAttribute('procedencia_triaje'));
        $this->assertNull($fresca->notas_triaje);
    }

    public function test_una_alerta_que_no_puede_demostrar_su_origen_queda_fuera_del_lote(): void
    {
        $triaje = app(TriajeAsistido::class);

        // Marcada de demostracion, pero con un evento de trafico real entre los suyos.
        $mezclada = $this->alerta(demostracion: true, severidad: 'baja', conteo: 1, eventoDemostracion: false);
        // Marcada de demostracion y sin eventos: no hay con que demostrar el origen.
        $huerfana = $this->alerta(demostracion: true, severidad: 'baja', conteo: 1, conEventos: false);

        $this->assertFalse($triaje->esDeDemostracion($mezclada));
        $this->assertFalse($triaje->esDeDemostracion($huerfana));

        $demostrables = $triaje->soloDemostrables(AlertaSeguridad::query())->pluck('id')->all();
        $this->assertNotContains($mezclada->id, $demostrables);
        $this->assertNotContains($huerfana->id, $demostrables);

        // Y el comando no las toca.
        $this->assertSame(0, Artisan::call('siem:triar-sinteticas'));
        $this->assertSame(AlertaSeguridad::ESTADO_NUEVA, $mezclada->fresh()->estado);
        $this->assertSame(AlertaSeguridad::ESTADO_NUEVA, $huerfana->fresh()->estado);
    }

    public function test_sin_alertas_en_la_ventana_la_cobertura_se_declara_sin_datos(): void
    {
        $this->actingAs($this->analista());

        $conteos = app(TriajeAsistido::class)->conteosProcedencia(
            CarbonImmutable::now()->subDays(30),
            CarbonImmutable::now(),
        );

        $this->assertSame(0, $conteos['total']);
        $this->assertSame(0, $conteos['triadas']);

        // Con denominador cero el panel no puede devolver ni 0 % ni 100 %.
        Livewire::test(AccionesMasivas::class)
            ->assertSee('sin datos')
            ->assertDontSee('100.0 %')
            ->assertDontSee('0.0 %');
    }

    public function test_una_alerta_fuera_de_la_ventana_no_entra_en_la_cobertura(): void
    {
        $this->actingAs($this->analista());

        $vieja = $this->alerta(demostracion: false, eventoDemostracion: false);
        $vieja->forceFill(['detectada_en' => CarbonImmutable::now()->subDays(45)])->save();

        $conteos = app(TriajeAsistido::class)->conteosProcedencia(
            CarbonImmutable::now()->subDays(30),
            CarbonImmutable::now(),
        );

        $this->assertSame(0, $conteos['total']);
    }

    /**
     * Sin las columnas de procedencia el comando se niega a escribir. Triar sin dejar
     * constancia de quien decidio convierte una cifra comprobable en una incomprobable, que
     * es peor que no triar.
     */
    public function test_sin_las_columnas_de_procedencia_el_comando_se_niega_a_escribir(): void
    {
        $alerta = $this->alerta(demostracion: true, severidad: 'critica', conteo: 1, reglas: ['932115']);

        Schema::table('alertas_seguridad', function ($tabla): void {
            // SQLite se niega a soltar una columna que un indice todavia nombra, igual que
            // hace el down() de la migracion: primero el indice, despues las columnas.
            $tabla->dropIndex(['procedencia_triaje', 'triado_en']);
            $tabla->dropColumn([
                'procedencia_triaje',
                'triaje_regla',
                'triaje_criterio',
                'triaje_evidencia',
                'triado_en',
                'triado_por',
            ]);
        });

        $this->assertFalse(Schema::hasColumn('alertas_seguridad', 'procedencia_triaje'));
        $this->assertFalse(app(TriajeAsistido::class)->procedenciaRegistrable());

        $this->assertSame(1, Artisan::call('siem:triar-sinteticas'));
        $this->assertStringContainsString('2026_09_24_000605', Artisan::output());

        // Y la alerta sigue intacta: no se escribio un estado sin procedencia.
        $this->assertSame(AlertaSeguridad::ESTADO_NUEVA, $alerta->fresh()->estado);

        // La simulacion si funciona: ensena que haria sin tocar nada.
        $this->assertSame(0, Artisan::call('siem:triar-sinteticas', ['--simular' => true]));
        $this->assertSame(AlertaSeguridad::ESTADO_NUEVA, $alerta->fresh()->estado);
    }

    public function test_sin_las_columnas_la_procedencia_se_declara_no_registrada(): void
    {
        $analista = $this->analista();
        $this->actingAs($analista);

        $alerta = $this->alerta(demostracion: false, eventoDemostracion: false);
        $alerta->cambiarEstado(AlertaSeguridad::ESTADO_EN_TRIAJE, $analista->id);

        Schema::table('alertas_seguridad', function ($tabla): void {
            // SQLite se niega a soltar una columna que un indice todavia nombra, igual que
            // hace el down() de la migracion: primero el indice, despues las columnas.
            $tabla->dropIndex(['procedencia_triaje', 'triado_en']);
            $tabla->dropColumn([
                'procedencia_triaje',
                'triaje_regla',
                'triaje_criterio',
                'triaje_evidencia',
                'triado_en',
                'triado_por',
            ]);
        });

        $conteos = app(TriajeAsistido::class)->conteosProcedencia();

        $this->assertFalse($conteos['procedencia_registrable']);
        // Nada se supone humano: lo triado cae entero en "sin registro".
        $this->assertSame(0, $conteos['humana']);
        $this->assertSame(0, $conteos['automatica']);
        $this->assertSame(1, $conteos['sin_registro']);

        Livewire::test(AccionesMasivas::class)
            ->assertSee('La procedencia no se esta registrando')
            ->assertSee('2026_09_24_000605_agregar_procedencia_triaje_a_alertas');
    }

    public function test_el_lote_es_idempotente(): void
    {
        $this->alerta(demostracion: true, severidad: 'critica', conteo: 1, reglas: ['932115']);

        $this->assertSame(0, Artisan::call('siem:triar-sinteticas'));

        $primera = AlertaSeguridad::query()->whereNotNull('procedencia_triaje')->get()
            ->map(static fn (AlertaSeguridad $a): string => $a->id.'|'.$a->estado.'|'.$a->getAttribute('triado_en'))
            ->all();

        $this->assertNotEmpty($primera);

        $this->assertSame(0, Artisan::call('siem:triar-sinteticas'));

        $segunda = AlertaSeguridad::query()->whereNotNull('procedencia_triaje')->get()
            ->map(static fn (AlertaSeguridad $a): string => $a->id.'|'.$a->estado.'|'.$a->getAttribute('triado_en'))
            ->all();

        $this->assertSame($primera, $segunda);
    }
}
