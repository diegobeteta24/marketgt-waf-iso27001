<?php

namespace App\Services\Siem;

use App\Models\EstadoParche;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Calcula la cobertura de parcheo critico del vertice de proteccion a partir del
 * inventario que el recolector del anfitrion deja en estados_parche.
 *
 * Devuelve un arreglo con la misma forma que produce CalculadoraMetricas, de modo que el
 * integrador solo tiene que sustituir alli la llamada a sinDatos() por una llamada a
 * metrica(). Este servicio no toca aquel archivo ni depende de su estado interno.
 *
 * TRES DECISIONES GOBIERNAN EL CALCULO, Y LAS TRES SON LA MISMA:
 * no medir sobre lo que no se sabe.
 *
 *  1. El porcentaje se calcula SOLO sobre parches de seguridad con las dos fechas
 *     conocidas, publicacion y aplicacion. Un parche al que le falta la fecha de
 *     publicacion cuenta en el total, no en el plazo, y la metrica dice cuantos son.
 *
 *  2. Los parches pendientes NO entran en el porcentaje, porque su fecha de publicacion
 *     no se puede leer en la maquina: el paquete todavia no esta instalado. Entran como
 *     condicion aparte, medida desde que el recolector los vio por primera vez, que es
 *     una cota inferior del retraso y se declara como tal.
 *
 *  3. Un desfase negativo (aplicado antes de publicado) no es un plazo cumplido: es un
 *     reloj mal puesto. Sale del calculo y se reporta.
 *
 * Si tras todo eso no queda nada medible, la metrica sigue declarandose sin datos. Es un
 * resultado legitimo y preferible a un numero inventado.
 */
class AnalizadorParches
{
    /**
     * Antiguedad a partir de la cual el inventario deja de describir el servidor de hoy.
     *
     * El recolector esta programado a diario, de modo que cuarenta y ocho horas son dos
     * ciclos perdidos: suficiente para sospechar que la tarea murio. Una metrica calculada
     * sobre un inventario viejo no es falsa, pero responde a una pregunta de otro dia, y
     * el panel tiene que avisarlo.
     */
    private const HORAS_INVENTARIO_FRESCO = 48;

    private const CLAVE = 'cobertura_parcheo_critico';

    private const NOMBRE = 'Cobertura de parcheo critico';

    /**
     * @return array<string, mixed>
     */
    public function metrica(?CarbonInterface $ahora = null): array
    {
        $ahora = $ahora?->copy() ?? CarbonImmutable::now();
        $horas = $this->horasMeta();
        $meta = '100 % de los parches criticos aplicados en '.$horas.' h';

        $conteos = $this->conteos($ahora);

        if ($conteos['registros'] === 0) {
            return $this->sinDatos(
                $meta,
                'La tabla de estados de parche esta vacia: el recolector del anfitrion todavia no ha '
                .'escrito ningun inventario, o "siem:ingerir-parches" no ha leido el archivo. Ejecute '
                .'infra/scripts/recolectar-parches.sh en el servidor y compruebe el montaje del volumen.',
            );
        }

        // Nada que medir el plazo: hay inventario, pero ningun parche de seguridad tiene
        // las dos fechas. Se dice exactamente por que, porque el motivo es accionable.
        if ($conteos['medibles'] === 0) {
            return $this->sinDatos($meta, $this->motivoSinPlazoMedible($conteos, $ahora));
        }

        $porcentaje = round($conteos['en_plazo'] * 100 / $conteos['medibles'], 1);
        $cumple = $conteos['en_plazo'] === $conteos['medibles'] && $conteos['pendientes_vencidos'] === 0;

        return [
            'clave' => self::CLAVE,
            'nombre' => self::NOMBRE,
            'meta' => $meta,
            'valor' => $porcentaje,
            'valor_texto' => $this->formatearPorcentaje($porcentaje),
            'unidad' => '%',
            'estado' => $cumple ? CalculadoraMetricas::CUMPLE : CalculadoraMetricas::INCUMPLE,
            'muestra' => $conteos['medibles'],
            'origen' => $this->origen($conteos, $horas),
            'advertencia' => $this->advertencia($conteos, $horas, $ahora),
        ];
    }

    /**
     * Desglose completo para una pantalla de detalle o para el comando de ingesta.
     *
     * @return array<string, mixed>
     */
    public function resumen(?CarbonInterface $ahora = null): array
    {
        $ahora = $ahora?->copy() ?? CarbonImmutable::now();

        return [
            'conteos' => $this->conteos($ahora),
            'horas_meta' => $this->horasMeta(),
            'pendientes' => $this->pendientesDeSeguridad(),
            'ultima_recoleccion' => $this->ultimaRecoleccion(),
        ];
    }

    /**
     * Parches de seguridad que siguen sin aplicarse, del mas antiguo al mas reciente.
     * Es la lista de trabajo real detras de la cifra.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, EstadoParche>
     */
    public function pendientesDeSeguridad()
    {
        return EstadoParche::query()
            ->deSeguridad()
            ->pendientes()
            ->orderBy('visto_pendiente_desde')
            ->get();
    }

    public function ultimaRecoleccion(): ?CarbonImmutable
    {
        $valor = EstadoParche::query()->max('recolectado_en');

        return $valor === null ? null : CarbonImmutable::parse($valor);
    }

    /**
     * Todos los conteos que sostienen la metrica, en una sola consulta agregada.
     *
     * @return array<string, int>
     */
    private function conteos(CarbonInterface $ahora): array
    {
        $horas = $this->horasMeta();
        $limitePendiente = $ahora->copy()->subHours($horas);

        $fila = EstadoParche::query()
            ->selectRaw('COUNT(*) as registros')
            ->selectRaw('SUM(CASE WHEN es_seguridad = 1 AND estado = ? THEN 1 ELSE 0 END) as aplicados_seguridad', [EstadoParche::ESTADO_APLICADO])
            ->selectRaw('SUM(CASE WHEN es_seguridad = 1 AND estado = ? AND publicado_en IS NULL THEN 1 ELSE 0 END) as sin_fecha', [EstadoParche::ESTADO_APLICADO])
            // Las tres cuentas del plazo exigen las DOS fechas ademas del desfase. Sin esa
            // condicion, una fila con desfase guardado y una fecha perdida entraria en el
            // denominador, y esa cifra ya no se podria reproducir desde la propia fila:
            // es exactamente el conjunto que define EstadoParche::scopeConPlazoMedible.
            ->selectRaw('SUM(CASE WHEN es_seguridad = 1 AND estado = ? AND publicado_en IS NOT NULL AND aplicado_en IS NOT NULL AND desfase_horas IS NOT NULL AND desfase_horas < 0 THEN 1 ELSE 0 END) as inconsistentes', [EstadoParche::ESTADO_APLICADO])
            ->selectRaw('SUM(CASE WHEN es_seguridad = 1 AND estado = ? AND publicado_en IS NOT NULL AND aplicado_en IS NOT NULL AND desfase_horas IS NOT NULL AND desfase_horas >= 0 THEN 1 ELSE 0 END) as medibles', [EstadoParche::ESTADO_APLICADO])
            ->selectRaw('SUM(CASE WHEN es_seguridad = 1 AND estado = ? AND publicado_en IS NOT NULL AND aplicado_en IS NOT NULL AND desfase_horas IS NOT NULL AND desfase_horas >= 0 AND desfase_horas <= ? THEN 1 ELSE 0 END) as en_plazo', [EstadoParche::ESTADO_APLICADO, $horas])
            ->selectRaw('SUM(CASE WHEN es_seguridad = 1 AND estado = ? THEN 1 ELSE 0 END) as pendientes', [EstadoParche::ESTADO_PENDIENTE])
            ->selectRaw('SUM(CASE WHEN es_seguridad = 1 AND estado = ? AND visto_pendiente_desde IS NOT NULL AND visto_pendiente_desde <= ? THEN 1 ELSE 0 END) as pendientes_vencidos', [EstadoParche::ESTADO_PENDIENTE, $limitePendiente])
            ->selectRaw('SUM(CASE WHEN es_seguridad IS NULL AND estado <> ? THEN 1 ELSE 0 END) as sin_clasificar', [EstadoParche::ESTADO_NO_APLICABLE])
            ->first();

        return [
            'registros' => (int) ($fila->registros ?? 0),
            'aplicados_seguridad' => (int) ($fila->aplicados_seguridad ?? 0),
            'sin_fecha' => (int) ($fila->sin_fecha ?? 0),
            'inconsistentes' => (int) ($fila->inconsistentes ?? 0),
            'medibles' => (int) ($fila->medibles ?? 0),
            'en_plazo' => (int) ($fila->en_plazo ?? 0),
            'pendientes' => (int) ($fila->pendientes ?? 0),
            'pendientes_vencidos' => (int) ($fila->pendientes_vencidos ?? 0),
            'sin_clasificar' => (int) ($fila->sin_clasificar ?? 0),
        ];
    }

    /**
     * @param  array<string, int>  $conteos
     */
    private function origen(array $conteos, int $horas): string
    {
        $origen = sprintf(
            'Parches de seguridad aplicados dentro de %d h (%d) entre los que tienen fecha de publicacion '
                .'y de aplicacion conocidas (%d). Fechas leidas del registro de cambios instalado de cada '
                .'paquete y de los registros de dpkg del anfitrion.',
            $horas,
            $conteos['en_plazo'],
            $conteos['medibles'],
        );

        // La parte que el porcentaje NO cubre se escribe en el mismo sitio que el
        // porcentaje. Una cifra que no dice sobre cuantos casos se calculo, ni cuantos
        // quedaron fuera, es la que un auditor senala primero.
        if ($conteos['sin_fecha'] > 0) {
            $origen .= sprintf(
                ' %d de %d parches de seguridad aplicados %s fecha de publicacion conocida: %s en el total '
                    .'y no en el plazo.',
                $conteos['sin_fecha'],
                $conteos['aplicados_seguridad'],
                $this->concordar($conteos['sin_fecha'], 'no tiene', 'no tienen'),
                $this->concordar($conteos['sin_fecha'], 'cuenta', 'cuentan'),
            );
        }

        if ($conteos['sin_clasificar'] > 0) {
            $origen .= sprintf(
                ' Otro%s %d parche%s no se %s clasificar como de seguridad y %s fuera del calculo.',
                $this->concordar($conteos['sin_clasificar'], '', 's'),
                $conteos['sin_clasificar'],
                $this->concordar($conteos['sin_clasificar'], '', 's'),
                $this->concordar($conteos['sin_clasificar'], 'pudo', 'pudieron'),
                $this->concordar($conteos['sin_clasificar'], 'queda', 'quedan'),
            );
        }

        return $origen;
    }

    /**
     * @param  array<string, int>  $conteos
     */
    private function advertencia(array $conteos, int $horas, CarbonInterface $ahora): ?string
    {
        $partes = [];

        if ($conteos['pendientes_vencidos'] > 0) {
            $partes[] = sprintf(
                '%d parche%s de seguridad %s mas de %d h pendiente%s desde que el recolector %s vio por '
                    .'primera vez. El plazo se cuenta desde esa observacion, no desde la publicacion, asi que '
                    .'el retraso real es como minimo ese.',
                $conteos['pendientes_vencidos'],
                $this->concordar($conteos['pendientes_vencidos'], '', 's'),
                $this->concordar($conteos['pendientes_vencidos'], 'lleva', 'llevan'),
                $horas,
                $this->concordar($conteos['pendientes_vencidos'], '', 's'),
                $this->concordar($conteos['pendientes_vencidos'], 'lo', 'los'),
            );
        } elseif ($conteos['pendientes'] > 0) {
            $partes[] = sprintf(
                '%d parche%s de seguridad %s pendiente%s y todavia dentro de la ventana de %d h contada '
                    .'desde que se %s por primera vez.',
                $conteos['pendientes'],
                $this->concordar($conteos['pendientes'], '', 's'),
                $this->concordar($conteos['pendientes'], 'esta', 'estan'),
                $this->concordar($conteos['pendientes'], '', 's'),
                $horas,
                $this->concordar($conteos['pendientes'], 'vio', 'vieron'),
            );
        }

        if ($conteos['inconsistentes'] > 0) {
            $partes[] = sprintf(
                '%d parche%s %s aplicado%s antes de su fecha de publicacion; %s fuera del calculo '
                    .'hasta que se revise el reloj del anfitrion.',
                $conteos['inconsistentes'],
                $this->concordar($conteos['inconsistentes'], '', 's'),
                $this->concordar($conteos['inconsistentes'], 'figura', 'figuran'),
                $this->concordar($conteos['inconsistentes'], '', 's'),
                $this->concordar($conteos['inconsistentes'], 'queda', 'quedan'),
            );
        }

        $ultima = $this->ultimaRecoleccion();

        if ($ultima === null) {
            $partes[] = 'Ninguna fila registra cuando se recolecto: no se puede saber si el inventario esta al dia.';
        } elseif ($ultima->greaterThan($ahora)) {
            // Un inventario fechado en el futuro no es fresco: es un reloj mal puesto, y
            // la comparacion de antiguedad daria negativa y callaria. El mismo criterio
            // que saca del calculo a los desfases negativos.
            $partes[] = sprintf(
                'El inventario dice haberse recolectado el %s, despues de la hora actual: revise el reloj del '
                    .'anfitrion antes de dar la cifra por buena.',
                $ultima->format('Y-m-d H:i'),
            );
        } elseif ($ultima->diffInHours($ahora) > self::HORAS_INVENTARIO_FRESCO) {
            $partes[] = sprintf(
                'El inventario se recolecto por ultima vez hace %d h: la cifra describe el servidor de ese dia, '
                    .'no el de hoy. Revise la tarea programada del anfitrion.',
                (int) $ultima->diffInHours($ahora),
            );
        }

        return $partes === [] ? null : implode(' ', $partes);
    }

    /**
     * @param  array<string, int>  $conteos
     */
    private function motivoSinPlazoMedible(array $conteos, CarbonInterface $ahora): string
    {
        $motivo = 'Hay inventario de parches, pero ningun parche de seguridad tiene a la vez fecha de '
            .'publicacion y fecha de aplicacion, que son las dos que el plazo necesita.';

        if ($conteos['aplicados_seguridad'] > 0 && $conteos['sin_fecha'] === $conteos['aplicados_seguridad']) {
            $motivo .= sprintf(
                ' %s %d parche%s de seguridad aplicado%s no %s fecha de publicacion: esos paquetes no tienen '
                    .'el registro de cambios instalado en el servidor.',
                $this->concordar($conteos['aplicados_seguridad'], 'El', 'Los'),
                $conteos['aplicados_seguridad'],
                $this->concordar($conteos['aplicados_seguridad'], '', 's'),
                $this->concordar($conteos['aplicados_seguridad'], '', 's'),
                $this->concordar($conteos['aplicados_seguridad'], 'trae', 'traen'),
            );
        }

        if ($conteos['pendientes'] > 0) {
            $motivo .= sprintf(
                ' %s %d parche%s de seguridad pendiente%s de aplicar, el dato mas util que hay ahora mismo, '
                    .'pero un pendiente no permite calcular un porcentaje de cumplimiento del plazo.',
                $this->concordar($conteos['pendientes'], 'Consta', 'Constan'),
                $conteos['pendientes'],
                $this->concordar($conteos['pendientes'], '', 's'),
                $this->concordar($conteos['pendientes'], '', 's'),
            );
        }

        if ($conteos['sin_clasificar'] > 0) {
            $motivo .= sprintf(
                ' Otro%s %d parche%s no se %s clasificar como de seguridad.',
                $this->concordar($conteos['sin_clasificar'], '', 's'),
                $conteos['sin_clasificar'],
                $this->concordar($conteos['sin_clasificar'], '', 's'),
                $this->concordar($conteos['sin_clasificar'], 'pudo', 'pudieron'),
            );
        }

        return $motivo;
    }

    /**
     * @return array<string, mixed>
     */
    private function sinDatos(string $meta, string $motivo): array
    {
        return [
            'clave' => self::CLAVE,
            'nombre' => self::NOMBRE,
            'meta' => $meta,
            'valor' => null,
            'valor_texto' => 'sin datos',
            'unidad' => null,
            'estado' => CalculadoraMetricas::SIN_DATOS,
            'muestra' => 0,
            'origen' => $motivo,
            'advertencia' => null,
        ];
    }

    /**
     * Concordancia de numero en los textos que lee el auditor.
     *
     * No es cosmetica: estas cadenas son la explicacion de una cifra de cumplimiento, y
     * "1 parches llevan pendientes" hace dudar de quien escribio el calculo antes incluso
     * de mirar el numero.
     */
    private function concordar(int $cantidad, string $singular, string $plural): string
    {
        return $cantidad === 1 ? $singular : $plural;
    }

    private function horasMeta(): int
    {
        $configurada = config('siem.metas.cobertura_parcheo_critico_horas');

        return is_numeric($configurada) ? (int) $configurada : 72;
    }

    /**
     * Mismo formato que usa el resto del panel, para que las tarjetas no se vean de dos
     * familias distintas.
     */
    private function formatearPorcentaje(float $valor): string
    {
        return number_format($valor, $valor == (int) $valor ? 0 : 1).' %';
    }
}
