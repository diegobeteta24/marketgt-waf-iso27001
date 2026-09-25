<?php

namespace App\Services\Siem;

use App\Models\AlertaSeguridad;
use App\Models\Capacitacion;
use App\Models\EventoSeguridad;
use App\Models\PruebaRestauracion;
use App\Models\RegistroAuditoria;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Calcula las metricas del triangulo de la ciberresiliencia a partir de los datos reales
 * de la base, nunca de valores escritos a mano.
 *
 * Regla de oro de este archivo: cuando una metrica no se puede calcular con lo que hay,
 * devuelve estado "sin datos". Un tablero que inventa un noventa y nueve por ciento para
 * verse bien es exactamente el hallazgo que un auditor esta buscando.
 */
class CalculadoraMetricas
{
    public const CUMPLE = 'cumple';

    public const INCUMPLE = 'incumple';

    public const SIN_DATOS = 'sin_datos';

    /**
     * Metas del proyecto, en las unidades en que se miden.
     */
    private const META_DETECCION_MINUTOS = 30;

    private const META_FALSOS_POSITIVOS_PORCENTAJE = 2.0;

    private const META_CONTENCION_MINUTOS = 120;

    private const META_RTO_HORAS = 4;

    private const META_RPO_HORAS = 24;

    /**
     * Plazo para INICIAR el triaje de una alerta, por severidad, contado desde que se genero.
     *
     * No son cifras de este archivo: son la columna "Triaje iniciado" de la tabla de tiempos
     * objetivo del plan de respuesta a incidentes (docs/03-politicas, seccion 8). "Siguiente
     * turno" se lee como veinticuatro horas, que es lo maximo que puede tardar en llegar el
     * siguiente turno de cualquier guardia.
     *
     * La cobertura de triaje se mide contra estos plazos y no contra un cien por cien
     * instantaneo. Una alerta que llego hace tres minutos y nadie ha mirado no es una falla
     * del equipo: esta dentro de su plazo. La falla es la que lo supero sin que nadie la
     * revisara, y esa es la que el plan exige que no exista.
     */
    private const PLAZO_TRIAJE_MINUTOS = [
        EventoSeguridad::SEVERIDAD_CRITICA => 15,
        EventoSeguridad::SEVERIDAD_ALTA => 60,
        EventoSeguridad::SEVERIDAD_MEDIA => 240,
        EventoSeguridad::SEVERIDAD_BAJA => 1440,
        EventoSeguridad::SEVERIDAD_INFORMATIVA => 1440,
    ];

    /**
     * Ventana de observacion. Treinta dias es el periodo natural de reporte del turno de
     * guardia y evita que un incidente aislado de hace medio ano mueva el promedio.
     */
    private const DIAS_OBSERVACION = 30;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function calcular(?CarbonInterface $ahora = null): array
    {
        $ahora = $ahora?->copy() ?? CarbonImmutable::now();
        $desde = $ahora->copy()->subDays(self::DIAS_OBSERVACION);

        return [
            'proteccion' => [
                'nombre' => 'Proteccion',
                'descripcion' => 'Que tan dificil es entrar.',
                'metricas' => $this->metricasProteccion($ahora),
            ],
            'deteccion' => [
                'nombre' => 'Deteccion',
                'descripcion' => 'Cuanto tarda el equipo en enterarse.',
                'metricas' => $this->metricasDeteccion($desde, $ahora),
            ],
            'respuesta' => [
                'nombre' => 'Respuesta',
                'descripcion' => 'Cuanto tarda el equipo en cortar el dano.',
                'metricas' => $this->metricasRespuesta($desde, $ahora),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function metricasProteccion(?CarbonInterface $ahora = null): array
    {
        return [
            // El inventario de parches lo escribe un recolector que corre EN EL ANFITRION,
            // porque el contenedor no puede leer /var/log/dpkg.log ni /usr/share/doc: vive
            // en otro espacio de nombres. El analizador lee lo ya ingerido; si no hay nada,
            // es el quien lo declara, y ademas dice que comando hay que ejecutar.
            app(AnalizadorParches::class)->metrica($ahora),
            $this->metricaPersonalCapacitado($ahora),
        ];
    }

    /**
     * Personal con la capacitacion vigente.
     *
     * Vive aqui, y no en el panel que la pinta, porque el triangulo y ese panel tienen que
     * dar la misma cifra por construccion. Cuando cada pantalla calcula la suya, la
     * pregunta que sigue en una auditoria es cual de las dos es la buena, y esa pregunta no
     * deberia poder formularse.
     *
     * @return array<string, mixed>
     */
    public function metricaPersonalCapacitado(?CarbonInterface $ahora = null): array
    {
        $meta = (float) config('siem.metas.personal_capacitado_porcentaje', 100);
        $textoMeta = rtrim(rtrim(number_format($meta, 1), '0'), '.').' % del personal con la capacitacion vigente';

        try {
            $medicion = Capacitacion::medicionPersonalCapacitado($ahora);
        } catch (QueryException) {
            // Mismo motivo que en el analizador de parches: una metrica que no puede
            // consultar su tabla se declara, no derriba las otras siete.
            return $this->sinDatos(
                clave: 'personal_capacitado',
                nombre: 'Personal capacitado',
                meta: $textoMeta,
                motivo: 'Las tablas de capacitacion no existen todavia en esta base. Falta correr las '
                    .'migraciones: "php artisan migrate" dentro del contenedor de la aplicacion.',
            );
        }

        if (! $medicion['medible']) {
            return [
                'clave' => 'personal_capacitado',
                'nombre' => 'Personal capacitado',
                'meta' => $textoMeta,
                'valor' => null,
                'valor_texto' => 'sin datos',
                'unidad' => null,
                'estado' => self::SIN_DATOS,
                'muestra' => 0,
                'origen' => (string) $medicion['origen'],
                'advertencia' => $medicion['advertencia'],
            ];
        }

        $porcentaje = (float) $medicion['porcentaje'];

        return [
            'clave' => 'personal_capacitado',
            'nombre' => 'Personal capacitado',
            'meta' => $textoMeta,
            'valor' => $porcentaje,
            'valor_texto' => number_format($porcentaje, $porcentaje == (int) $porcentaje ? 0 : 1).' %',
            'unidad' => '%',
            'estado' => $porcentaje >= $meta ? self::CUMPLE : self::INCUMPLE,
            'muestra' => (int) $medicion['muestra'],
            'origen' => (string) $medicion['origen'],
            'advertencia' => $medicion['advertencia'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function metricasDeteccion(CarbonInterface $desde, CarbonInterface $ahora): array
    {
        // Tiempo medio de deteccion: del primer evento del ataque al momento en que el motor
        // lo convirtio en alerta. Solo cuentan las alertas que tienen el primer evento sellado.
        $deteccion = AlertaSeguridad::query()
            ->whereBetween('detectada_en', [$desde, $ahora])
            ->whereNotNull('primer_evento_en')
            ->selectRaw('COUNT(*) as muestra, AVG(TIMESTAMPDIFF(SECOND, primer_evento_en, detectada_en)) as promedio')
            ->first();

        $muestraDeteccion = (int) ($deteccion->muestra ?? 0);

        $metricaDeteccion = $muestraDeteccion === 0
            ? $this->sinDatos(
                clave: 'tiempo_medio_deteccion',
                nombre: 'Tiempo medio de deteccion',
                meta: '30 minutos o menos',
                motivo: 'Todavia no hay alertas con primer evento registrado en la ventana de observacion.',
            )
            : $this->medida(
                clave: 'tiempo_medio_deteccion',
                nombre: 'Tiempo medio de deteccion',
                meta: '30 minutos o menos',
                valor: round(((float) $deteccion->promedio) / 60, 1),
                unidad: 'min',
                cumple: (((float) $deteccion->promedio) / 60) <= self::META_DETECCION_MINUTOS,
                muestra: $muestraDeteccion,
                origen: 'Promedio de (detectada_en - primer_evento_en) sobre las alertas de los ultimos '
                    .self::DIAS_OBSERVACION.' dias.',
            );

        // Tasa de falsos positivos: de todo lo que el motor levanto, cuanto resulto ser ruido.
        $conteos = AlertaSeguridad::query()
            ->whereBetween('detectada_en', [$desde, $ahora])
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN estado = ? THEN 1 ELSE 0 END) as falsos', [AlertaSeguridad::ESTADO_FALSO_POSITIVO])
            ->selectRaw('SUM(CASE WHEN estado = ? THEN 1 ELSE 0 END) as sin_triar', [AlertaSeguridad::ESTADO_NUEVA])
            ->first();

        $total = (int) ($conteos->total ?? 0);
        $falsos = (int) ($conteos->falsos ?? 0);
        $sinTriar = (int) ($conteos->sin_triar ?? 0);

        $metricaFalsos = $total === 0
            ? $this->sinDatos(
                clave: 'tasa_falsos_positivos',
                nombre: 'Tasa de falsos positivos',
                meta: 'menos del 2 %',
                motivo: 'No hay alertas en la ventana de observacion para calcular la proporcion.',
            )
            : $this->medida(
                clave: 'tasa_falsos_positivos',
                nombre: 'Tasa de falsos positivos',
                meta: 'menos del 2 %',
                valor: round($falsos * 100 / $total, 2),
                unidad: '%',
                cumple: ($falsos * 100 / $total) < self::META_FALSOS_POSITIVOS_PORCENTAJE,
                muestra: $total,
                origen: "Alertas marcadas como falso positivo ({$falsos}) entre el total de alertas ({$total}).",
                advertencia: $sinTriar > 0
                    ? "Quedan {$sinTriar} alertas sin triar: la tasa solo sera definitiva cuando el turno las revise."
                    : null,
            );

        // Una tasa de falsos positivos baja no significa nada si nadie triaja: esta metrica
        // evita que el panel se felicite a si mismo por no haber trabajado.
        $triadas = $total - $sinTriar;
        $vencidas = $sinTriar === 0 ? 0 : $this->alertasFueraDePlazoDeTriaje($desde, $ahora);
        $enPlazo = $sinTriar - $vencidas;

        $metricaTriaje = $total === 0
            ? $this->sinDatos(
                clave: 'cobertura_triaje',
                nombre: 'Cobertura de triaje',
                meta: 'ninguna alerta sin revisar fuera de su plazo',
                motivo: 'No hay alertas en la ventana de observacion.',
            )
            : $this->medida(
                clave: 'cobertura_triaje',
                nombre: 'Cobertura de triaje',
                meta: 'ninguna alerta sin revisar fuera de su plazo',
                valor: round(($total - $vencidas) * 100 / $total, 1),
                unidad: '%',
                cumple: $vencidas === 0,
                muestra: $total,
                origen: "Alertas revisadas o todavia dentro de su plazo de triaje ({$this->numero($total - $vencidas)}) "
                    ."entre el total ({$this->numero($total)}). Plazos del plan de respuesta a incidentes, seccion 8, "
                    .'contados desde que se genera la alerta: 15 min las criticas, 1 h las altas, 4 h las medias y '
                    .'el siguiente turno las bajas. '
                    ."Revisadas: {$this->numero($triadas)}. Fuera de plazo sin revisar: {$this->numero($vencidas)}.",
                // Las que esperan dentro de plazo no son una falla, pero lo seran si nadie las
                // mira. Se dice aqui para que el cien por cien no se lea como "no queda nada".
                advertencia: $enPlazo > 0
                    ? ($enPlazo === 1
                        ? '1 alerta reciente espera triaje dentro de su plazo. Si nadie la revisa antes de que venza, '
                            .'pasara a contar como falla.'
                        : "{$enPlazo} alertas recientes esperan triaje dentro de su plazo. Si nadie las revisa antes de "
                            .'que venzan, pasaran a contar como falla.')
                    : null,
            );

        return [$metricaDeteccion, $metricaFalsos, $metricaTriaje];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function metricasRespuesta(CarbonInterface $desde, CarbonInterface $ahora): array
    {
        // RTO y RPO salen del acta de la ultima prueba de restauracion satisfactoria. No
        // se leen de la configuracion del respaldo: el objetivo de recuperacion solo se
        // demuestra restaurando y cronometrando. Si no hay acta, el modelo lo declara y
        // dice que comando la produce.
        $recuperacion = PruebaRestauracion::medicionRecuperacion($ahora);

        return [
            $this->metricaTiempoContencion($desde, $ahora),
            // RTO y RPO se comprueban con una prueba de restauracion, no con el trafico del
            // WAF. Declararlos cumplidos desde este panel seria afirmar algo que el panel no vio.
            $recuperacion['rto_horas'] === null
                ? $this->sinDatos(
                clave: 'objetivo_tiempo_recuperacion',
                nombre: 'Objetivo de tiempo de recuperacion (RTO)',
                meta: self::META_RTO_HORAS.' horas o menos',
                motivo: $recuperacion['rto_origen'],
            )
                : $this->medida(
                    clave: 'objetivo_tiempo_recuperacion',
                    nombre: 'Objetivo de tiempo de recuperacion (RTO)',
                    meta: self::META_RTO_HORAS.' horas o menos',
                    valor: round((float) $recuperacion['rto_horas'] * 3600, 1),
                    unidad: 's',
                    cumple: (float) $recuperacion['rto_horas'] <= self::META_RTO_HORAS,
                    muestra: (int) ($recuperacion['rto_muestra'] ?? 1),
                    origen: $recuperacion['rto_origen'],
                ),
            $recuperacion['rpo_horas'] === null
                ? $this->sinDatos(
                    clave: 'objetivo_punto_recuperacion',
                    nombre: 'Objetivo de punto de recuperacion (RPO)',
                    meta: 'perdida maxima de '.self::META_RPO_HORAS.' horas',
                    motivo: $recuperacion['rpo_origen'],
                )
                : $this->medida(
                    clave: 'objetivo_punto_recuperacion',
                    nombre: 'Objetivo de punto de recuperacion (RPO)',
                    meta: 'perdida maxima de '.self::META_RPO_HORAS.' horas',
                    valor: round((float) $recuperacion['rpo_horas'] * 60, 1),
                    unidad: 'min',
                    cumple: (float) $recuperacion['rpo_horas'] <= self::META_RPO_HORAS,
                    muestra: 1,
                    origen: $recuperacion['rpo_origen'],
                ),
        ];
    }

    /**
     * Contadores de apoyo para la cabecera del panel de metricas.
     *
     * @return array<string, int>
     */
    public function conteosAlertas(?CarbonInterface $ahora = null): array
    {
        $ahora = $ahora?->copy() ?? CarbonImmutable::now();
        $desde = $ahora->copy()->subDays(self::DIAS_OBSERVACION);

        $filas = AlertaSeguridad::query()
            ->whereBetween('detectada_en', [$desde, $ahora])
            ->select('estado', DB::raw('COUNT(*) as total'))
            ->groupBy('estado')
            ->pluck('total', 'estado');

        $conteos = ['total' => 0];

        foreach (array_keys(AlertaSeguridad::ETIQUETAS_ESTADO) as $estado) {
            $conteos[$estado] = (int) ($filas[$estado] ?? 0);
            $conteos['total'] += $conteos[$estado];
        }

        return $conteos;
    }

    public function diasObservacion(): int
    {
        return self::DIAS_OBSERVACION;
    }

    /**
     * @return array<string, mixed>
     */
    private function medida(
        string $clave,
        string $nombre,
        string $meta,
        float $valor,
        string $unidad,
        bool $cumple,
        int $muestra,
        string $origen,
        ?string $advertencia = null,
    ): array {
        return [
            'clave' => $clave,
            'nombre' => $nombre,
            'meta' => $meta,
            'valor' => $valor,
            'valor_texto' => $this->formatearValor($valor, $unidad),
            'unidad' => $unidad,
            'estado' => $cumple ? self::CUMPLE : self::INCUMPLE,
            'muestra' => $muestra,
            'origen' => $origen,
            'advertencia' => $advertencia,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sinDatos(string $clave, string $nombre, string $meta, string $motivo): array
    {
        return [
            'clave' => $clave,
            'nombre' => $nombre,
            'meta' => $meta,
            'valor' => null,
            'valor_texto' => 'sin datos',
            'unidad' => null,
            'estado' => self::SIN_DATOS,
            'muestra' => 0,
            'origen' => $motivo,
            'advertencia' => null,
        ];
    }

    /**
     * Alertas de la ventana que siguen sin revisar y ya superaron su plazo de triaje.
     *
     * Una consulta por severidad con la fecha limite calculada aqui, en lugar de restar
     * fechas en SQL: TIMESTAMPDIFF es de MariaDB y la metrica tiene que dar lo mismo en las
     * pruebas, que corren sobre SQLite.
     */
    public function alertasFueraDePlazoDeTriaje(CarbonInterface $desde, CarbonInterface $ahora): int
    {
        $vencidas = 0;

        foreach (self::PLAZO_TRIAJE_MINUTOS as $severidad => $minutos) {
            $vencidas += AlertaSeguridad::query()
                ->whereBetween('detectada_en', [$desde, $ahora])
                ->where('estado', AlertaSeguridad::ESTADO_NUEVA)
                ->where('severidad', $severidad)
                ->where('detectada_en', '<=', $ahora->copy()->subMinutes($minutos))
                ->count();
        }

        // Una severidad fuera de la tabla no puede quedar sin plazo: se le aplica el mas
        // estricto. Un dato raro tiene que hacer saltar la metrica, no escapar de ella.
        $vencidas += AlertaSeguridad::query()
            ->whereBetween('detectada_en', [$desde, $ahora])
            ->where('estado', AlertaSeguridad::ESTADO_NUEVA)
            ->whereNotIn('severidad', array_keys(self::PLAZO_TRIAJE_MINUTOS))
            ->where('detectada_en', '<=', $ahora->copy()->subMinutes(min(self::PLAZO_TRIAJE_MINUTOS)))
            ->count();

        return $vencidas;
    }

    /**
     * Tiempo medio de contencion: desde que el equipo confirmo que la alerta era real hasta
     * que la marco contenida. Medir desde la deteccion mezclaria el retraso del turno de
     * guardia con la capacidad tecnica de responder.
     *
     * Todo lo que se excluye se dice, con numero y, en las reclasificadas, con identificador
     * y con la cifra que saldria contandolas: una exclusion que no se ve no se puede auditar.
     *
     * @return array<string, mixed>
     */
    public function metricaTiempoContencion(CarbonInterface $desde, CarbonInterface $ahora): array
    {
        $contencion = $this->contencionesHumanas($desde, $ahora);
        $duraciones = $contencion['duraciones'];
        $muestra = count($duraciones);

        $excluidas = [];
        if ($contencion['borde'] > 0) {
            $excluidas[] = $contencion['borde'].' contenidas por el cortafuegos en el borde, que no miden respuesta humana';
        }
        if ($contencion['demo_a_mano'] > 0) {
            $excluidas[] = $contencion['demo_a_mano'].' de demostracion contenidas a mano, cuya confirmacion es sembrada';
        }
        if ($contencion['invertidas'] > 0) {
            $excluidas[] = $contencion['invertidas'].' con la contencion anterior a la confirmacion';
        }
        if ($contencion['reclasificadas'] !== []) {
            $ids = array_keys($contencion['reclasificadas']);
            $excluidas[] = count($ids).' reclasificadas como cerradas sin impacto, con responsable y motivo en el '
                .'registro de auditoria ('.$this->enumerar($ids).')';
        }

        if ($muestra === 0) {
            return $this->sinDatos(
                clave: 'tiempo_medio_contencion',
                nombre: 'Tiempo medio de contencion',
                meta: '2 horas o menos',
                motivo: $excluidas === []
                    ? 'Ninguna alerta de la ventana llego todavia al estado contenida.'
                    : 'Ninguna contencion de la ventana mide una respuesta del equipo. Excluidas: '.implode('; ', $excluidas).'.',
            );
        }

        $promedioMinutos = array_sum($duraciones) / $muestra / 60;
        $cumple = $promedioMinutos <= self::META_CONTENCION_MINUTOS;

        $avisos = [];

        // Con las reclasificadas dentro, cuanto daria: la cifra que se excluye se ve.
        if ($contencion['reclasificadas'] !== []) {
            $todas = array_merge(array_values($duraciones), array_values($contencion['reclasificadas']));
            $avisos[] = 'Contando las reclasificadas, el promedio seria '
                .$this->formatearValor(array_sum($todas) / count($todas) / 60, 'min').'.';
        }

        // Una marca de reclasificacion sin asiento de auditoria no se acepta: la alerta
        // sigue en el promedio y se avisa, porque alguien la puso fuera del comando.
        if ($contencion['sin_asiento'] !== []) {
            $avisos[] = count($contencion['sin_asiento']).' alertas marcadas como reclasificadas no tienen asiento de '
                .'auditoria y siguen contando ('.$this->enumerar($contencion['sin_asiento']).').';
        }

        // Las mas lentas, con su identificador, cuando la media no cumple: un promedio que
        // falla sin decir que alertas lo arrastran obliga a buscarlas a ciegas.
        if (! $cumple) {
            arsort($duraciones);
            $lentas = [];
            foreach (array_slice($duraciones, 0, 3, true) as $id => $segundos) {
                $lentas[] = '#'.$id.' ('.number_format($segundos / 3600, 1).' h)';
            }
            $avisos[] = 'Las contenciones mas lentas: '.implode(', ', $lentas).'.';
        }

        return $this->medida(
            clave: 'tiempo_medio_contencion',
            nombre: 'Tiempo medio de contencion',
            meta: '2 horas o menos',
            valor: round($promedioMinutos, 1),
            unidad: 'min',
            cumple: $cumple,
            muestra: $muestra,
            origen: 'Promedio de (contenida_en - confirmada_en) sobre las contenciones registradas por el equipo en los '
                .'ultimos '.self::DIAS_OBSERVACION.' dias'
                .($contencion['demostracion'] > 0
                    ? ', de ellas '.$contencion['demostracion'].' de demostracion, sembradas y marcadas como tales'
                    : '')
                .'.'.($excluidas === [] ? '' : ' Excluidas: '.implode('; ', $excluidas).'.'),
            advertencia: $avisos === [] ? null : implode(' ', $avisos),
        );
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function enumerar(array $ids): string
    {
        $visibles = array_map(static fn (int $id): string => '#'.$id, array_slice($ids, 0, 10));

        return implode(', ', $visibles).(count($ids) > 10 ? ' y '.(count($ids) - 10).' mas' : '');
    }

    /**
     * Contenciones de la ventana, separadas por lo que son.
     *
     * Se excluyen por lo que SON, no por el orden de sus fechas:
     *   - las que contuvo el cortafuegos en el borde (las marca siem:contener-bloqueadas);
     *   - las reclasificadas como "sin impacto" por siem:reclasificar-contencion, pero SOLO si
     *     existe su asiento de auditoria. Una marca puesta a mano en la base, sin asiento, no
     *     saca a nadie del promedio: se queda dentro y se avisa en 'sin_asiento'.
     *
     * Se calcula en PHP y no con TIMESTAMPDIFF, que es de MariaDB: asi la metrica da lo mismo
     * en las pruebas, sobre SQLite. Son decenas de filas, no millones.
     *
     * @return array{duraciones: array<int, int>, borde: int, invertidas: int, reclasificadas: array<int, int>, sin_asiento: array<int, int>, demostracion: int, demo_a_mano: int}
     */
    public function contencionesHumanas(CarbonInterface $desde, CarbonInterface $ahora): array
    {
        $conProcedencia = app(TriajeAsistido::class)->procedenciaRegistrable();

        $columnas = ['id', 'confirmada_en', 'contenida_en', 'es_demostracion'];
        if ($conProcedencia) {
            $columnas[] = 'triaje_regla';
            $columnas[] = 'procedencia_triaje';
        }

        $filas = AlertaSeguridad::query()
            ->whereBetween('detectada_en', [$desde, $ahora])
            ->whereNotNull('confirmada_en')
            ->whereNotNull('contenida_en')
            ->get($columnas);

        // Una sola consulta para saber que reclasificaciones tienen su asiento.
        $conAsiento = [];
        if ($conProcedencia) {
            $marcadas = $filas
                ->filter(fn (AlertaSeguridad $f) => $f->getAttribute('triaje_regla') === TriajeAsistido::REGLA_RECLASIFICADA_SIN_IMPACTO)
                ->map(fn (AlertaSeguridad $f) => (string) $f->getKey())
                ->values()
                ->all();

            if ($marcadas !== []) {
                $conAsiento = array_flip(RegistroAuditoria::query()
                    ->where('accion', 'alerta.contencion_reclasificada')
                    ->whereIn('identificador_recurso', $marcadas)
                    ->pluck('identificador_recurso')
                    ->all());
            }
        }

        $duraciones = [];
        $reclasificadas = [];
        $sinAsiento = [];
        $borde = 0;
        $invertidas = 0;
        $demostracion = 0;
        $demoAMano = 0;

        foreach ($filas as $fila) {
            $id = (int) $fila->getKey();

            if ($conProcedencia
                && $fila->getAttribute('triaje_regla') === TriajeAsistido::REGLA_BLOQUEO_CRITICO
                && $fila->getAttribute('procedencia_triaje') === TriajeAsistido::PROCEDENCIA_AUTOMATICA) {
                $borde++;

                continue;
            }

            $segundos = (int) $fila->confirmada_en->diffInSeconds($fila->contenida_en, false);

            // Contener antes de confirmar no es una respuesta que se pueda medir: se cuenta
            // aparte para que se vea, en lugar de restar tiempo al promedio.
            if ($segundos < 0) {
                $invertidas++;

                continue;
            }

            // Alerta de demostracion contenida a mano: el semillero le pone una confirmacion
            // fechada horas atras para dar historia al panel, asi que restar contra ella mide un
            // tiempo inventado. Las contenciones que escribe el propio semillero no llevan
            // procedencia y siguen contando.
            if ($conProcedencia && $fila->es_demostracion
                && $fila->getAttribute('procedencia_triaje') === TriajeAsistido::PROCEDENCIA_HUMANA) {
                $demoAMano++;

                continue;
            }

            if ($conProcedencia && $fila->getAttribute('triaje_regla') === TriajeAsistido::REGLA_RECLASIFICADA_SIN_IMPACTO) {
                if (isset($conAsiento[(string) $id])) {
                    $reclasificadas[$id] = $segundos;

                    continue;
                }

                $sinAsiento[] = $id;
            }

            $duraciones[$id] = $segundos;

            if ($fila->es_demostracion) {
                $demostracion++;
            }
        }

        return [
            'duraciones' => $duraciones,
            'borde' => $borde,
            'invertidas' => $invertidas,
            'reclasificadas' => $reclasificadas,
            'sin_asiento' => $sinAsiento,
            'demostracion' => $demostracion,
            'demo_a_mano' => $demoAMano,
        ];
    }

    private function numero(int $valor): string
    {
        return number_format($valor);
    }

    private function formatearValor(float $valor, string $unidad): string
    {
        // Cada cifra en la unidad en que se lee: un RTO de tres segundos redondeado a
        // horas salia "0 h", que parece un fallo del panel y no un buen resultado.
        if ($unidad === 's' && $valor >= 3600) {
            return number_format($valor / 3600, 1).' h';
        }

        if ($unidad === 's' && $valor >= 60) {
            return number_format($valor / 60, 1).' min';
        }

        if ($unidad === 'min' && $valor >= 60) {
            return number_format($valor / 60, 1).' h';
        }

        return number_format($valor, $valor == (int) $valor ? 0 : 1).' '.$unidad;
    }
}
