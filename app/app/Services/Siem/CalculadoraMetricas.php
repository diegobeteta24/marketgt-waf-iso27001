<?php

namespace App\Services\Siem;

use App\Models\AlertaSeguridad;
use App\Models\Capacitacion;
use App\Models\PruebaRestauracion;
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

        $metricaTriaje = $total === 0
            ? $this->sinDatos(
                clave: 'cobertura_triaje',
                nombre: 'Cobertura de triaje',
                meta: '100 % de las alertas revisadas',
                motivo: 'No hay alertas en la ventana de observacion.',
            )
            : $this->medida(
                clave: 'cobertura_triaje',
                nombre: 'Cobertura de triaje',
                meta: '100 % de las alertas revisadas',
                valor: round($triadas * 100 / $total, 1),
                unidad: '%',
                cumple: $sinTriar === 0,
                muestra: $total,
                origen: "Alertas que salieron del estado nueva ({$triadas}) entre el total ({$total}).",
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

        // Tiempo medio de contencion: desde que un humano confirmo que la alerta era real
        // hasta que la marco contenida. Medir desde la deteccion mezclaria el retraso del
        // turno de guardia con la capacidad tecnica de responder.
        $contencion = AlertaSeguridad::query()
            ->whereBetween('detectada_en', [$desde, $ahora])
            ->whereNotNull('confirmada_en')
            ->whereNotNull('contenida_en')
            ->selectRaw('COUNT(*) as muestra, AVG(TIMESTAMPDIFF(SECOND, confirmada_en, contenida_en)) as promedio')
            ->first();

        $muestraContencion = (int) ($contencion->muestra ?? 0);

        $metricaContencion = $muestraContencion === 0
            ? $this->sinDatos(
                clave: 'tiempo_medio_contencion',
                nombre: 'Tiempo medio de contencion',
                meta: '2 horas o menos',
                motivo: 'Ninguna alerta de la ventana llego todavia al estado contenida.',
            )
            : $this->medida(
                clave: 'tiempo_medio_contencion',
                nombre: 'Tiempo medio de contencion',
                meta: '2 horas o menos',
                valor: round(((float) $contencion->promedio) / 60, 1),
                unidad: 'min',
                cumple: (((float) $contencion->promedio) / 60) <= self::META_CONTENCION_MINUTOS,
                muestra: $muestraContencion,
                origen: 'Promedio de (contenida_en - confirmada_en) sobre las alertas contenidas en los ultimos '
                    .self::DIAS_OBSERVACION.' dias.',
            );

        return [
            $metricaContencion,
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
                    valor: round((float) $recuperacion['rto_horas'], 2),
                    unidad: 'h',
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
                    valor: round((float) $recuperacion['rpo_horas'], 2),
                    unidad: 'h',
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

    private function formatearValor(float $valor, string $unidad): string
    {
        if ($unidad === 'min' && $valor >= 60) {
            return number_format($valor / 60, 1).' h';
        }

        return number_format($valor, $valor == (int) $valor ? 0 : 1).' '.$unidad;
    }
}
