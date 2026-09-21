<?php

namespace App\Services\Siem;

use App\Models\AlertaSeguridad;
use App\Models\EventoSeguridad;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Agregaciones que alimentan el panel en vivo. Viven fuera de los componentes Livewire
 * para que el panel se pueda probar sin renderizar nada y para que las mismas cifras
 * sirvan manana a un informe o a una exportacion.
 */
class ResumenOperativo
{
    /**
     * Techo de filas que se leen para desmenuzar el arreglo JSON de reglas en PHP.
     *
     * MariaDB no ofrece una forma portable de expandir un arreglo JSON en filas sin
     * JSON_TABLE, asi que el conteo por regla se hace en memoria. El tope evita que una
     * rafaga de cien mil eventos tumbe el panel; si se alcanza, el propio panel lo avisa.
     */
    private const TECHO_FILAS_REGLAS = 20000;

    /**
     * @return array<string, int>
     */
    public function contadores(?Carbon $ahora = null): array
    {
        $ahora = $ahora?->copy() ?? Carbon::now();
        $desde = $ahora->copy()->subDay();

        $eventos = EventoSeguridad::query()
            ->whereBetween('marca_tiempo', [$desde, $ahora])
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN fue_bloqueado = 1 THEN 1 ELSE 0 END) as bloqueados')
            ->selectRaw('COUNT(DISTINCT direccion_ip) as direcciones')
            ->selectRaw('SUM(CASE WHEN severidad = ? THEN 1 ELSE 0 END) as criticos', [EventoSeguridad::SEVERIDAD_CRITICA])
            ->first();

        $total = (int) ($eventos->total ?? 0);
        $bloqueados = (int) ($eventos->bloqueados ?? 0);

        return [
            'eventos_24h' => $total,
            'bloqueados_24h' => $bloqueados,
            'permitidos_24h' => max($total - $bloqueados, 0),
            'criticos_24h' => (int) ($eventos->criticos ?? 0),
            'direcciones_unicas_24h' => (int) ($eventos->direcciones ?? 0),
            'alertas_abiertas' => AlertaSeguridad::query()->abiertas()->count(),
            'alertas_nuevas' => AlertaSeguridad::query()->where('estado', AlertaSeguridad::ESTADO_NUEVA)->count(),
        ];
    }

    /**
     * Serie de eventos por hora, separando lo que el WAF corto de lo que dejo pasar.
     *
     * @return array<int, array{etiqueta: string, momento: string, bloqueados: int, permitidos: int, total: int}>
     */
    public function serieHoraria(int $horas = 24, ?Carbon $ahora = null): array
    {
        $ahora = ($ahora?->copy() ?? Carbon::now())->startOfHour();
        $desde = $ahora->copy()->subHours($horas - 1);

        $filas = EventoSeguridad::query()
            ->whereBetween('marca_tiempo', [$desde, $ahora->copy()->endOfHour()])
            ->selectRaw("DATE_FORMAT(marca_tiempo, '%Y-%m-%d %H:00:00') as cubo")
            ->selectRaw('SUM(CASE WHEN fue_bloqueado = 1 THEN 1 ELSE 0 END) as bloqueados')
            ->selectRaw('SUM(CASE WHEN fue_bloqueado = 1 THEN 0 ELSE 1 END) as permitidos')
            ->groupBy('cubo')
            ->get()
            ->keyBy('cubo');

        $serie = [];

        // Se recorren todas las horas y no solo las que tienen filas: una hora sin trafico
        // es informacion (el sitio estuvo caido, o el WAF dejo de escribir), no un hueco.
        for ($indice = 0; $indice < $horas; $indice++) {
            $momento = $desde->copy()->addHours($indice);
            $fila = $filas->get($momento->format('Y-m-d H:00:00'));

            $bloqueados = (int) ($fila->bloqueados ?? 0);
            $permitidos = (int) ($fila->permitidos ?? 0);

            $serie[] = [
                'etiqueta' => $momento->format('H:i'),
                'momento' => $momento->format('d/m H:i'),
                'bloqueados' => $bloqueados,
                'permitidos' => $permitidos,
                'total' => $bloqueados + $permitidos,
            ];
        }

        return $serie;
    }

    /**
     * Las reglas del Core Rule Set que mas se activan. Dice donde esta apuntando el atacante
     * y, cuando una regla sube sin ataque detras, donde hay que afinar el WAF.
     *
     * @return array{reglas: array<int, array{identificador: string, total: int, bloqueados: int, propia: bool}>, truncado: bool}
     */
    public function reglasMasActivadas(int $limite = 10, int $horas = 24, ?Carbon $ahora = null): array
    {
        $ahora = $ahora?->copy() ?? Carbon::now();
        $desde = $ahora->copy()->subHours($horas);

        $filas = EventoSeguridad::query()
            ->deFuente(EventoSeguridad::FUENTE_WAF)
            ->whereBetween('marca_tiempo', [$desde, $ahora])
            ->whereNotNull('identificadores_regla')
            ->orderByDesc('marca_tiempo')
            ->limit(self::TECHO_FILAS_REGLAS)
            ->get(['identificadores_regla', 'fue_bloqueado']);

        $conteo = [];

        foreach ($filas as $fila) {
            foreach ($fila->identificadores_regla ?? [] as $identificador) {
                $clave = (string) $identificador;
                $conteo[$clave] ??= ['total' => 0, 'bloqueados' => 0];
                $conteo[$clave]['total']++;

                if ($fila->fue_bloqueado) {
                    $conteo[$clave]['bloqueados']++;
                }
            }
        }

        uasort($conteo, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        $reglas = [];

        foreach (array_slice($conteo, 0, $limite, true) as $identificador => $datos) {
            $numero = (int) $identificador;

            $reglas[] = [
                'identificador' => $identificador,
                'total' => $datos['total'],
                'bloqueados' => $datos['bloqueados'],
                'propia' => $numero >= 15000 && $numero <= 15099,
            ];
        }

        return [
            'reglas' => $reglas,
            'truncado' => $filas->count() >= self::TECHO_FILAS_REGLAS,
        ];
    }

    /**
     * Direcciones mas agresivas de la ventana. Se ordenan por eventos bloqueados y no por
     * volumen: quien mas peticiones hace suele ser un rastreador legitimo, quien mas
     * bloqueos acumula es quien esta atacando.
     *
     * @return Collection<int, object>
     */
    public function direccionesMasAgresivas(int $limite = 10, int $horas = 24, ?Carbon $ahora = null): Collection
    {
        $ahora = $ahora?->copy() ?? Carbon::now();
        $desde = $ahora->copy()->subHours($horas);

        return EventoSeguridad::query()
            ->whereBetween('marca_tiempo', [$desde, $ahora])
            ->groupBy('direccion_ip')
            ->orderByDesc('bloqueados')
            ->orderByDesc('puntuacion_maxima')
            ->limit($limite)
            ->select([
                'direccion_ip',
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN fue_bloqueado = 1 THEN 1 ELSE 0 END) as bloqueados'),
                DB::raw('MAX(puntuacion_anomalia) as puntuacion_maxima'),
                DB::raw('MAX(pais) as pais'),
                DB::raw('MAX(marca_tiempo) as ultimo_evento'),
            ])
            ->get()
            ->map(static fn (EventoSeguridad $fila): object => (object) $fila->getAttributes());
    }

    /**
     * Reparto de severidades en la ventana, para la franja de color del encabezado.
     *
     * @return array<string, int>
     */
    public function repartoSeveridad(int $horas = 24, ?Carbon $ahora = null): array
    {
        $ahora = $ahora?->copy() ?? Carbon::now();
        $desde = $ahora->copy()->subHours($horas);

        $filas = EventoSeguridad::query()
            ->whereBetween('marca_tiempo', [$desde, $ahora])
            ->select('severidad', DB::raw('COUNT(*) as total'))
            ->groupBy('severidad')
            ->pluck('total', 'severidad');

        $reparto = [];

        foreach (EventoSeguridad::ESCALA_SEVERIDAD as $severidad) {
            $reparto[$severidad] = (int) ($filas[$severidad] ?? 0);
        }

        return $reparto;
    }

    /**
     * Momento del evento mas reciente. El panel lo usa para avisar de que la ingesta se paro:
     * un tablero en cero puede significar calma o puede significar que nadie esta mirando.
     */
    public function ultimoEventoEn(): ?Carbon
    {
        $valor = EventoSeguridad::query()->max('marca_tiempo');

        return $valor === null ? null : Carbon::parse($valor);
    }
}
