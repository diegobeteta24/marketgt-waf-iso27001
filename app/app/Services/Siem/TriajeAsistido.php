<?php

namespace App\Services\Siem;

use App\Models\AlertaSeguridad;
use App\Models\EventoSeguridad;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Triaje asistido de alertas y registro de la procedencia de cada decision.
 *
 * Este servicio existe para sostener una distincion que el panel no puede perder: no es lo
 * mismo una alerta que reviso una persona que una que clasifico un lote. Las dos cuentan
 * para la cobertura de triaje, pero solo la primera demuestra que hay un turno operando.
 * Por eso cada decision se guarda con su regla, su criterio en palabras, los hechos que la
 * dispararon y quien la tomo.
 *
 * El criterio automatico es deliberadamente incompleto. Una alerta que no encaja en ninguna
 * regla se queda como esta: un clasificador que no deja nada sin clasificar no esta triando,
 * esta rellenando, y rellenar es exactamente el hallazgo que este proyecto dice evitar.
 */
class TriajeAsistido
{
    public const PROCEDENCIA_HUMANA = 'humana';

    public const PROCEDENCIA_AUTOMATICA = 'automatica';

    public const REGLA_BLOQUEO_CRITICO = 'bloqueo_waf_critico';

    public const REGLA_EVENTO_UNICO_MENOR = 'evento_unico_menor';

    public const REGLA_RANGO_DOCUMENTACION = 'rango_documentacion';

    /**
     * Ninguna regla encajo. No es un error ni un estado intermedio: es el resultado correcto
     * para todo lo que un criterio escrito no alcanza a explicar.
     */
    public const SIN_ENCAJE = 'sin_encaje';

    /**
     * Rangos reservados por la RFC 5737 para documentacion y ejemplos. Ningun operador los
     * anuncia en Internet, de modo que nada que venga de ahi llego por la red publica.
     *
     * @var array<int, array{0: string, 1: int}>
     */
    private const RANGOS_DOCUMENTACION = [
        ['192.0.2.0', 24],
        ['198.51.100.0', 24],
        ['203.0.113.0', 24],
    ];

    /**
     * Ventana, en dias, para comprobar si una alerta menor se repitio. Coincide con la de la
     * calculadora de metricas para que "sin repeticion" signifique lo mismo en los dos sitios.
     */
    private const DIAS_REPETICION = 30;

    /**
     * El criterio completo, en el orden exacto en que se evalua, para que el panel lo muestre
     * palabra por palabra y nadie tenga que abrir el codigo para saber que se aplico.
     *
     * El orden importa: la regla mas especifica decide primero. Si el rango de documentacion
     * se evaluara antes que el evento unico de severidad menor, se tragaria todas las alertas
     * de la demostracion y todas saldrian "contenidas", afirmando una contencion que nadie
     * observo. Evaluando primero lo especifico, cada alerta queda clasificada por la razon
     * que de verdad la explica.
     *
     * @var array<int, array<string, string>>
     */
    public const CRITERIOS = [
        [
            'clave' => self::REGLA_BLOQUEO_CRITICO,
            'nombre' => 'Peticion cortada por el cortafuegos y severidad critica',
            'condicion' => 'La alerta es critica, todos sus eventos del cortafuegos quedaron bloqueados y consta '
                .'el identificador de la regla que los corto.',
            'resultado' => AlertaSeguridad::ESTADO_CONTENIDA,
            'porque' => 'El ataque llego y fue detenido. La contencion es un hecho con marca de tiempo propia: '
                .'la del ultimo evento bloqueado, no la del momento en que se ejecuta el triaje.',
        ],
        [
            'clave' => self::REGLA_EVENTO_UNICO_MENOR,
            'nombre' => 'Evento unico de severidad informativa o baja, sin repeticion',
            'condicion' => 'La alerta agrupa un solo evento, su severidad es baja o informativa y ninguna otra '
                .'alerta de la misma regla y la misma direccion aparece en los ultimos '.self::DIAS_REPETICION.' dias.',
            'resultado' => AlertaSeguridad::ESTADO_FALSO_POSITIVO,
            'porque' => 'Un acierto aislado de una regla permisiva es ruido. Si se repitiera dejaria de serlo, '
                .'y por eso la repeticion se comprueba contra la base en lugar de suponerse.',
        ],
        [
            'clave' => self::REGLA_RANGO_DOCUMENTACION,
            'nombre' => 'Todas las direcciones en rangos reservados para documentacion',
            'condicion' => 'Todas las direcciones de la alerta y de sus eventos caen en 192.0.2.0/24, '
                .'198.51.100.0/24 o 203.0.113.0/24, y la alerta no es critica ni alta.',
            'resultado' => AlertaSeguridad::ESTADO_CONTENIDA,
            'porque' => 'Esas direcciones no son enrutables en Internet (RFC 5737), asi que el trafico no llego '
                .'de la red publica. Se excluye lo critico y lo alto a proposito: que la direccion no sea '
                .'enrutable explica de donde vino el trafico, no que el hallazgo sea inofensivo.',
        ],
        [
            'clave' => self::SIN_ENCAJE,
            'nombre' => 'Cualquier otro caso',
            'condicion' => 'La alerta no cumple ninguna de las reglas anteriores.',
            'resultado' => AlertaSeguridad::ESTADO_NUEVA,
            'porque' => 'Se deja como esta, para revision humana. Esta linea es la que convierte lo anterior en '
                .'un triaje y no en un relleno.',
        ],
    ];

    /**
     * Se resuelve una sola vez por proceso: la existencia de las columnas no cambia a mitad
     * de una peticion, y preguntarlo por alerta convertiria un panel en decenas de consultas
     * al diccionario de datos.
     */
    private ?bool $columnasPresentes = null;

    /**
     * Si la migracion de procedencia no se ha aplicado, la procedencia no se puede registrar
     * ni medir. Se declara y punto: el panel dira "sin datos" en lugar de suponer que todo
     * lo triado lo hizo una persona.
     */
    public function procedenciaRegistrable(): bool
    {
        if ($this->columnasPresentes === null) {
            $this->columnasPresentes = Schema::hasColumn('alertas_seguridad', 'procedencia_triaje');
        }

        return $this->columnasPresentes;
    }

    /**
     * Una alerta es de demostracion solo si lo son TODOS los eventos que la sostienen.
     *
     * La marca de la propia alerta no basta como unica prueba: la escribe el motor de
     * correlacion en el momento de crearla, y si una rafaga real cayera dentro de la misma
     * ventana de agrupacion que una sembrada, la alerta llevaria la marca de demostracion
     * apoyada en trafico real. Preguntar por los eventos es preguntar por el hecho.
     *
     * @param  Collection<int, EventoSeguridad>|null  $eventos
     */
    public function esDeDemostracion(AlertaSeguridad $alerta, ?Collection $eventos = null): bool
    {
        $eventos ??= $this->eventosDe($alerta);

        // Sin eventos enlazados no hay forma de demostrar el origen. Una alerta huerfana
        // (por ejemplo, con sus eventos ya purgados por retencion) nunca se tria por lote.
        if ($eventos->isEmpty()) {
            return false;
        }

        if (! $alerta->es_demostracion) {
            return false;
        }

        return $eventos->every(static fn (EventoSeguridad $evento): bool => $evento->es_demostracion === true);
    }

    /**
     * Aplica el criterio declarado y devuelve la decision con los hechos que la sostienen.
     *
     * Devuelve siempre una decision, incluso cuando ninguna regla encaja: en ese caso la clave
     * es SIN_ENCAJE y el estado propuesto es nulo, que es como este servicio dice "esta no la
     * clasifico yo".
     *
     * @param  Collection<int, EventoSeguridad>|null  $eventos
     * @return array{regla: string, estado: string|null, criterio: string, evidencia: array<string, mixed>, contenida_en: CarbonInterface|null}
     */
    public function clasificar(AlertaSeguridad $alerta, ?Collection $eventos = null): array
    {
        $eventos ??= $this->eventosDe($alerta);

        $decision = $this->reglaBloqueoCritico($alerta, $eventos)
            ?? $this->reglaEventoUnicoMenor($alerta, $eventos)
            ?? $this->reglaRangoDocumentacion($alerta, $eventos);

        if ($decision !== null) {
            return $decision;
        }

        return [
            'regla' => self::SIN_ENCAJE,
            'estado' => null,
            'criterio' => $this->textoCriterio(self::SIN_ENCAJE),
            'evidencia' => [
                'eventos_enlazados' => $eventos->count(),
                'eventos_del_cortafuegos' => $eventos->where('fuente', EventoSeguridad::FUENTE_WAF)->count(),
                'direcciones' => $this->direcciones($alerta, $eventos),
                'severidad' => $alerta->severidad,
            ],
            'contenida_en' => null,
        ];
    }

    /**
     * Regla 1: el cortafuegos corto la peticion y la alerta es critica.
     *
     * @param  Collection<int, EventoSeguridad>  $eventos
     * @return array{regla: string, estado: string, criterio: string, evidencia: array<string, mixed>, contenida_en: CarbonInterface|null}|null
     */
    private function reglaBloqueoCritico(AlertaSeguridad $alerta, Collection $eventos): ?array
    {
        if ($alerta->severidad !== EventoSeguridad::SEVERIDAD_CRITICA) {
            return null;
        }

        $delCortafuegos = $eventos->where('fuente', EventoSeguridad::FUENTE_WAF);

        if ($delCortafuegos->isEmpty()) {
            return null;
        }

        // Basta un evento que pasara para que la contencion no sea cierta. Marcar "contenida"
        // una alerta en la que algo atraveso el cortafuegos es la clase de afirmacion que
        // deja al equipo tranquilo mientras el ataque sigue dentro.
        $noBloqueados = $delCortafuegos->where('fue_bloqueado', false);

        if ($noBloqueados->isNotEmpty()) {
            return null;
        }

        $reglas = $delCortafuegos
            ->flatMap(static fn (EventoSeguridad $evento): array => $evento->identificadores_regla ?? [])
            ->map(static fn (mixed $identificador): string => (string) $identificador)
            ->unique()
            ->values();

        // Sin identificador de regla no consta QUE corto la peticion, y el criterio exige
        // nombrar la regla del cortafuegos, no solo el codigo de respuesta.
        if ($reglas->isEmpty()) {
            return null;
        }

        $ultimoBloqueo = $delCortafuegos
            ->pluck('marca_tiempo')
            ->filter()
            ->max();

        return [
            'regla' => self::REGLA_BLOQUEO_CRITICO,
            'estado' => AlertaSeguridad::ESTADO_CONTENIDA,
            'criterio' => $this->textoCriterio(self::REGLA_BLOQUEO_CRITICO),
            'evidencia' => [
                'eventos_del_cortafuegos' => $delCortafuegos->count(),
                'bloqueados' => $delCortafuegos->where('fue_bloqueado', true)->count(),
                'reglas_del_cortafuegos' => $reglas->take(10)->all(),
                'codigos_respuesta' => $delCortafuegos->pluck('codigo_respuesta')->filter()->unique()->values()->all(),
                'ultimo_bloqueo_en' => $ultimoBloqueo?->toDateTimeString(),
            ],
            // La contencion ocurrio cuando el cortafuegos corto, no cuando se ejecuto el lote.
            'contenida_en' => $ultimoBloqueo,
        ];
    }

    /**
     * Regla 2: un unico evento menor que no se repitio.
     *
     * @param  Collection<int, EventoSeguridad>  $eventos
     * @return array{regla: string, estado: string, criterio: string, evidencia: array<string, mixed>, contenida_en: CarbonInterface|null}|null
     */
    private function reglaEventoUnicoMenor(AlertaSeguridad $alerta, Collection $eventos): ?array
    {
        $menores = [EventoSeguridad::SEVERIDAD_BAJA, EventoSeguridad::SEVERIDAD_INFORMATIVA];

        if (! in_array($alerta->severidad, $menores, true)) {
            return null;
        }

        if ($eventos->count() > 1 || $alerta->conteo_eventos > 1) {
            return null;
        }

        // "Sin repeticion" se comprueba contra la base, no se supone. Si la misma regla salto
        // otra vez contra el mismo objetivo, deja de ser un acierto aislado y pasa a ser un
        // patron, que es justo lo que un analista tiene que mirar.
        $repeticiones = AlertaSeguridad::query()
            ->where('clave_regla', $alerta->clave_regla)
            ->whereKeyNot($alerta->getKey())
            ->where('detectada_en', '>=', CarbonImmutable::now()->subDays(self::DIAS_REPETICION))
            ->when(
                $alerta->direccion_ip !== null,
                fn ($consulta) => $consulta->where('direccion_ip', $alerta->direccion_ip),
                fn ($consulta) => $consulta->whereNull('direccion_ip'),
            )
            ->count();

        if ($repeticiones > 0) {
            return null;
        }

        return [
            'regla' => self::REGLA_EVENTO_UNICO_MENOR,
            'estado' => AlertaSeguridad::ESTADO_FALSO_POSITIVO,
            'criterio' => $this->textoCriterio(self::REGLA_EVENTO_UNICO_MENOR),
            'evidencia' => [
                'eventos_enlazados' => $eventos->count(),
                'conteo_eventos_de_la_alerta' => $alerta->conteo_eventos,
                'severidad' => $alerta->severidad,
                'alertas_repetidas_de_la_misma_regla' => $repeticiones,
                'dias_comprobados' => self::DIAS_REPETICION,
            ],
            'contenida_en' => null,
        ];
    }

    /**
     * Regla 3: todas las direcciones implicadas son de documentacion.
     *
     * @param  Collection<int, EventoSeguridad>  $eventos
     * @return array{regla: string, estado: string, criterio: string, evidencia: array<string, mixed>, contenida_en: CarbonInterface|null}|null
     */
    private function reglaRangoDocumentacion(AlertaSeguridad $alerta, Collection $eventos): ?array
    {
        // Lo critico y lo alto no se cierran por el rango de origen. Que la direccion no sea
        // enrutable explica por donde entro el trafico; no dice nada sobre la gravedad de lo
        // que hizo, y esa diferencia es la que separa un criterio de una excusa.
        $graves = [EventoSeguridad::SEVERIDAD_CRITICA, EventoSeguridad::SEVERIDAD_ALTA];

        if (in_array($alerta->severidad, $graves, true)) {
            return null;
        }

        $direcciones = $this->direcciones($alerta, $eventos);

        if ($direcciones === []) {
            return null;
        }

        $fuera = array_values(array_filter(
            $direcciones,
            static fn (string $direccion): bool => ! self::enRangoDocumentacion($direccion),
        ));

        if ($fuera !== []) {
            return null;
        }

        return [
            'regla' => self::REGLA_RANGO_DOCUMENTACION,
            'estado' => AlertaSeguridad::ESTADO_CONTENIDA,
            'criterio' => $this->textoCriterio(self::REGLA_RANGO_DOCUMENTACION),
            'evidencia' => [
                'direcciones' => $direcciones,
                'rangos_comprobados' => array_map(
                    static fn (array $rango): string => $rango[0].'/'.$rango[1],
                    self::RANGOS_DOCUMENTACION,
                ),
                'eventos_enlazados' => $eventos->count(),
                // Se deja escrito que aqui NO hay instante de contencion medido: la contencion
                // es estructural, no un corte observado. Por eso contenida_en se queda vacia y
                // esta alerta jamas entra en el promedio del tiempo de contencion.
                'contencion_medida' => false,
            ],
            'contenida_en' => null,
        ];
    }

    /**
     * Escribe la decision automatica y su procedencia.
     *
     * Aqui hay una omision deliberada: NO se sella confirmada_en. Esa marca significa que una
     * persona miro la alerta y dijo que era real, y un lote no confirma nada. Como el tiempo
     * medio de contencion se calcula entre confirmada_en y contenida_en, dejarla vacia hace
     * que ninguna alerta triada por lote pueda mejorar el tiempo de respuesta del equipo.
     * Sellarla seria regalarle al panel un promedio de cero minutos.
     *
     * Se escribe con el constructor de consultas y no con el modelo para que el valor guardado
     * no dependa de que alguien anada o quite conversiones en AlertaSeguridad.
     *
     * @param  array{regla: string, estado: string|null, criterio: string, evidencia: array<string, mixed>, contenida_en: CarbonInterface|null}  $decision
     */
    public function registrarTriajeAutomatico(
        AlertaSeguridad $alerta,
        array $decision,
        string $actor,
        ?CarbonInterface $momento = null,
    ): bool {
        if ($decision['estado'] === null) {
            return false;
        }

        $momento = $momento?->copy() ?? CarbonImmutable::now();

        $valores = [
            'estado' => $decision['estado'],
            'notas_triaje' => $decision['criterio'],
            'updated_at' => $momento,
        ];

        // Las marcas de tiempo no se sobrescriben: la primera vez que algo ocurrio es la que
        // cuenta, y volver a pasar el lote no debe mover el historico.
        if ($decision['estado'] === AlertaSeguridad::ESTADO_CONTENIDA
            && $decision['contenida_en'] !== null
            && $alerta->contenida_en === null) {
            $valores['contenida_en'] = $decision['contenida_en'];
        }

        if ($decision['estado'] === AlertaSeguridad::ESTADO_FALSO_POSITIVO && $alerta->cerrada_en === null) {
            // Cerrar es un acto administrativo y ocurre ahora: esta marca si es del lote.
            $valores['cerrada_en'] = $momento;
        }

        if ($this->procedenciaRegistrable()) {
            $valores += [
                'procedencia_triaje' => self::PROCEDENCIA_AUTOMATICA,
                'triaje_regla' => $decision['regla'],
                'triaje_criterio' => $decision['criterio'],
                'triaje_evidencia' => json_encode($decision['evidencia'], JSON_UNESCAPED_UNICODE),
                'triado_en' => $momento,
                'triado_por' => mb_substr($actor, 0, 190),
            ];
        }

        $afectadas = AlertaSeguridad::query()
            ->whereKey($alerta->getKey())
            // Condicion de carrera real: entre que el lote leyo la alerta y la escribe, un
            // analista pudo haberla triado desde el panel. El estado humano manda siempre.
            ->where('estado', AlertaSeguridad::ESTADO_NUEVA)
            ->update($valores);

        return $afectadas > 0;
    }

    /**
     * Sella la procedencia de una decision tomada por una persona.
     *
     * No toca el estado ni las marcas del ciclo de vida: de eso ya se encargo
     * AlertaSeguridad::cambiarEstado(). Aqui solo se anota quien decidio y con que nota, que
     * es la parte que faltaba para poder partir la cobertura de triaje en dos cifras.
     */
    public function registrarTriajeHumano(
        AlertaSeguridad $alerta,
        ?User $analista,
        string $origen,
        ?string $nota = null,
        ?CarbonInterface $momento = null,
    ): void {
        if (! $this->procedenciaRegistrable()) {
            return;
        }

        $momento = $momento?->copy() ?? CarbonImmutable::now();

        AlertaSeguridad::query()->whereKey($alerta->getKey())->update([
            'procedencia_triaje' => self::PROCEDENCIA_HUMANA,
            // En el triaje humano no hay regla: el criterio es el juicio del analista, y por
            // eso la nota que escribio es el criterio que queda registrado.
            'triaje_regla' => null,
            'triaje_criterio' => $nota !== null && trim($nota) !== '' ? trim($nota) : null,
            'triaje_evidencia' => json_encode([
                'origen' => $origen,
                'estado_resultante' => $alerta->estado,
                'analista_id' => $analista?->getKey(),
            ], JSON_UNESCAPED_UNICODE),
            'triado_en' => $momento,
            'triado_por' => mb_substr($this->actorHumano($analista, $origen), 0, 190),
            'updated_at' => $momento,
        ]);
    }

    /**
     * Cobertura de triaje partida por procedencia dentro de una ventana.
     *
     * Devuelve las cifras crudas, no porcentajes: quien las muestre decide como redondearlas,
     * y asi el numerador y el denominador siempre se pueden ver por separado.
     *
     * @return array{
     *     total: int, sin_triar: int, triadas: int,
     *     humana: int, automatica: int, sin_registro: int,
     *     reales_total: int, reales_sin_triar: int, reales_triadas: int,
     *     demostracion_total: int, demostracion_triadas: int,
     *     procedencia_registrable: bool
     * }
     */
    public function conteosProcedencia(?CarbonInterface $desde = null, ?CarbonInterface $hasta = null): array
    {
        $consulta = AlertaSeguridad::query()
            ->when($desde !== null && $hasta !== null, fn ($c) => $c->whereBetween('detectada_en', [$desde, $hasta]));

        $base = (clone $consulta)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN estado = ? THEN 1 ELSE 0 END) as sin_triar', [AlertaSeguridad::ESTADO_NUEVA])
            ->selectRaw('SUM(CASE WHEN es_demostracion = 1 THEN 1 ELSE 0 END) as demostracion_total')
            ->selectRaw('SUM(CASE WHEN es_demostracion = 1 AND estado <> ? THEN 1 ELSE 0 END) as demostracion_triadas', [AlertaSeguridad::ESTADO_NUEVA])
            ->selectRaw('SUM(CASE WHEN es_demostracion = 0 THEN 1 ELSE 0 END) as reales_total')
            ->selectRaw('SUM(CASE WHEN es_demostracion = 0 AND estado = ? THEN 1 ELSE 0 END) as reales_sin_triar', [AlertaSeguridad::ESTADO_NUEVA])
            ->first();

        $total = (int) ($base->total ?? 0);
        $sinTriar = (int) ($base->sin_triar ?? 0);
        $realesTotal = (int) ($base->reales_total ?? 0);
        $realesSinTriar = (int) ($base->reales_sin_triar ?? 0);
        $triadas = $total - $sinTriar;

        $humana = 0;
        $automatica = 0;

        if ($this->procedenciaRegistrable()) {
            $porProcedencia = (clone $consulta)
                ->where('estado', '<>', AlertaSeguridad::ESTADO_NUEVA)
                ->select('procedencia_triaje', DB::raw('COUNT(*) as total'))
                ->groupBy('procedencia_triaje')
                ->pluck('total', 'procedencia_triaje');

            $humana = (int) ($porProcedencia[self::PROCEDENCIA_HUMANA] ?? 0);
            $automatica = (int) ($porProcedencia[self::PROCEDENCIA_AUTOMATICA] ?? 0);
        }

        return [
            'total' => $total,
            'sin_triar' => $sinTriar,
            'triadas' => $triadas,
            'humana' => $humana,
            'automatica' => $automatica,
            // Alertas que alguien saco del estado "nueva" antes de que existiera esta columna,
            // o con la migracion sin aplicar. No se reparten entre las otras dos: no consta
            // quien las decidio, y repartirlas seria inventar la mitad de la metrica.
            'sin_registro' => max($triadas - $humana - $automatica, 0),
            'reales_total' => $realesTotal,
            'reales_sin_triar' => $realesSinTriar,
            'reales_triadas' => $realesTotal - $realesSinTriar,
            'demostracion_total' => (int) ($base->demostracion_total ?? 0),
            'demostracion_triadas' => (int) ($base->demostracion_triadas ?? 0),
            'procedencia_registrable' => $this->procedenciaRegistrable(),
        ];
    }

    /**
     * Desglose por regla de lo que clasifico el lote, para que el panel pueda ensenar cuantas
     * alertas decidio cada criterio en lugar de un total opaco.
     *
     * @return array<string, int>
     */
    public function conteosPorRegla(?CarbonInterface $desde = null, ?CarbonInterface $hasta = null): array
    {
        if (! $this->procedenciaRegistrable()) {
            return [];
        }

        return AlertaSeguridad::query()
            ->when($desde !== null && $hasta !== null, fn ($c) => $c->whereBetween('detectada_en', [$desde, $hasta]))
            ->where('procedencia_triaje', self::PROCEDENCIA_AUTOMATICA)
            ->whereNotNull('triaje_regla')
            ->select('triaje_regla', DB::raw('COUNT(*) as total'))
            ->groupBy('triaje_regla')
            ->pluck('total', 'triaje_regla')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();
    }

    /**
     * Procedencia de una alerta concreta, lista para mostrar.
     *
     * La evidencia se decodifica con cuidado a proposito: la columna se escribe como texto
     * JSON desde el constructor de consultas, pero si manana el modelo gana la conversion a
     * arreglo llegaria aqui ya decodificada. Las dos formas se aceptan.
     *
     * @return array{procedencia: string|null, etiqueta: string, regla: string|null, criterio: string|null, evidencia: array<string, mixed>, momento: CarbonInterface|null, actor: string|null}
     */
    public function procedenciaDe(AlertaSeguridad $alerta): array
    {
        if (! $this->procedenciaRegistrable()) {
            return [
                'procedencia' => null,
                'etiqueta' => 'sin registro de procedencia',
                'regla' => null,
                'criterio' => null,
                'evidencia' => [],
                'momento' => null,
                'actor' => null,
            ];
        }

        $crudo = $alerta->getAttribute('triaje_evidencia');
        $evidencia = is_array($crudo) ? $crudo : (is_string($crudo) ? json_decode($crudo, true) : null);

        $procedencia = $alerta->getAttribute('procedencia_triaje');
        $momento = $alerta->getAttribute('triado_en');
        $regla = $alerta->getAttribute('triaje_regla');
        $criterio = $alerta->getAttribute('triaje_criterio');
        $actor = $alerta->getAttribute('triado_por');

        return [
            'procedencia' => is_string($procedencia) ? $procedencia : null,
            'etiqueta' => match ($procedencia) {
                self::PROCEDENCIA_HUMANA => 'triada por una persona',
                self::PROCEDENCIA_AUTOMATICA => 'triada por el lote automatico',
                default => $alerta->estado === AlertaSeguridad::ESTADO_NUEVA
                    ? 'sin triar'
                    : 'sin registro de procedencia',
            },
            'regla' => is_string($regla) ? $regla : null,
            'criterio' => is_string($criterio) ? $criterio : null,
            'evidencia' => is_array($evidencia) ? $evidencia : [],
            'momento' => $momento instanceof CarbonInterface
                ? $momento
                : ($momento !== null ? CarbonImmutable::parse((string) $momento) : null),
            'actor' => is_string($actor) ? $actor : null,
        ];
    }

    /**
     * Texto del criterio tal como se muestra en el panel, buscado por su clave.
     */
    public function textoCriterio(string $clave): string
    {
        foreach (self::CRITERIOS as $criterio) {
            if ($criterio['clave'] === $clave) {
                return $criterio['nombre'].': '.$criterio['condicion'];
            }
        }

        return $clave;
    }

    /**
     * Comprueba si una direccion cae en los rangos de documentacion de la RFC 5737.
     *
     * Se compara sobre enteros de 32 bits y no con comparaciones de texto: "203.0.113.5" y
     * "203.0.1130" empiezan igual, y un prefijo de cadena habria dado por buena una direccion
     * que no pertenece al rango.
     */
    public static function enRangoDocumentacion(string $direccion): bool
    {
        $numero = ip2long(trim($direccion));

        if ($numero === false) {
            // IPv6 o texto invalido: no se puede afirmar que sea de documentacion, asi que no lo es.
            return false;
        }

        foreach (self::RANGOS_DOCUMENTACION as [$red, $bits]) {
            $base = ip2long($red);

            if ($base === false) {
                continue;
            }

            $mascara = -1 << (32 - $bits);

            if (($numero & $mascara) === ($base & $mascara)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nombre del actor cuando la decision la toma un proceso, no una persona.
     */
    public static function actorAutomatico(string $comando): string
    {
        $anfitrion = gethostname() ?: 'anfitrion desconocido';
        $usuario = get_current_user() ?: 'usuario desconocido';

        return sprintf('comando %s en %s (usuario %s)', $comando, $anfitrion, $usuario);
    }

    private function actorHumano(?User $analista, string $origen): string
    {
        if ($analista === null) {
            return 'persona no identificada desde '.$origen;
        }

        return sprintf('%s (cuenta #%d) desde %s', $analista->name, $analista->getKey(), $origen);
    }

    /**
     * Direcciones implicadas en la alerta: la suya y las de sus eventos, sin repetir.
     *
     * @param  Collection<int, EventoSeguridad>  $eventos
     * @return array<int, string>
     */
    private function direcciones(AlertaSeguridad $alerta, Collection $eventos): array
    {
        return $eventos
            ->pluck('direccion_ip')
            ->push($alerta->direccion_ip)
            ->filter(static fn (mixed $direccion): bool => is_string($direccion) && trim($direccion) !== '')
            ->map(static fn (string $direccion): string => trim($direccion))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, EventoSeguridad>
     */
    private function eventosDe(AlertaSeguridad $alerta): Collection
    {
        return $alerta->relationLoaded('eventos') ? $alerta->eventos : $alerta->eventos()->get();
    }
}
