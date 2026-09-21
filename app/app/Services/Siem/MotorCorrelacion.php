<?php

namespace App\Services\Siem;

use App\Models\AlertaSeguridad;
use App\Models\EventoSeguridad;
use App\Models\ReglaCorrelacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Motor de correlacion. Un evento aislado casi nunca es un incidente; un patron de eventos
 * si lo es. Aqui se convierte el ruido de las tres capas en alertas con dueno, evidencia y
 * accion recomendada, que es lo unico que un turno de guardia puede realmente atender.
 *
 * Los umbrales viven en la tabla reglas_correlacion. Los valores por defecto que siembra
 * esta clase estan justificados uno a uno en sembrarReglas().
 */
class MotorCorrelacion
{
    /**
     * Rango de identificadores que el proyecto reservo para sus propias reglas del WAF.
     */
    private const RASTREADOR_FALSIFICADO_DESDE = 15020;

    private const RASTREADOR_FALSIFICADO_HASTA = 15029;

    private const EXTRACCION_MASIVA_DESDE = 15040;

    private const EXTRACCION_MASIVA_HASTA = 15049;

    /**
     * Cuantos eventos se guardan como muestra dentro de la evidencia de una alerta.
     * Con veinte lineas un analista ya sabe si el patron es real; guardar miles solo
     * hincha la base y nadie las lee.
     */
    private const MUESTRA_EVIDENCIA = 20;

    /**
     * Ejecuta todas las reglas activas.
     *
     * @param  Carbon|null  $ahora  Instante que el motor considera "presente". Se puede fijar
     *                              para reproducir el motor sobre datos historicos.
     * @return array<string, int>  Alertas nuevas por clave de regla.
     */
    public function ejecutar(?Carbon $ahora = null, bool $marcarDemostracion = false): array
    {
        $ahora = $ahora?->copy() ?? Carbon::now();
        $resumen = [];

        foreach (ReglaCorrelacion::query()->activas()->get() as $regla) {
            $resumen[$regla->clave] = $this->ejecutarRegla($regla, $ahora, $marcarDemostracion);
        }

        return $resumen;
    }

    private function ejecutarRegla(ReglaCorrelacion $regla, Carbon $ahora, bool $demostracion): int
    {
        return match ($regla->clave) {
            ReglaCorrelacion::WAF_403_REPETIDO => $this->detectarWaf403Repetido($regla, $ahora, $demostracion),
            ReglaCorrelacion::ESCALADA_ANOMALIA => $this->detectarEscaladaAnomalia($regla, $ahora, $demostracion),
            ReglaCorrelacion::FUERZA_BRUTA_SESION => $this->detectarFuerzaBruta($regla, $ahora, $demostracion),
            ReglaCorrelacion::SEGUNDO_FACTOR_FALLIDO => $this->detectarSegundoFactorFallido($regla, $ahora, $demostracion),
            ReglaCorrelacion::RASTREADOR_FALSIFICADO => $this->detectarRastreadorFalsificado($regla, $ahora, $demostracion),
            ReglaCorrelacion::EXTRACCION_MASIVA => $this->detectarExtraccionMasiva($regla, $ahora, $demostracion),
            ReglaCorrelacion::EVENTO_CRITICO_UNICO => $this->detectarEventoCriticoUnico($regla, $ahora, $demostracion),
            default => 0,
        };
    }

    /**
     * Regla 1. Muchos 403 del WAF desde la misma direccion en poco tiempo.
     * Un 403 suelto lo produce cualquier cosa; una docena en cinco minutos es alguien
     * probando cargas utiles contra el Core Rule Set a mano o con herramienta.
     */
    private function detectarWaf403Repetido(ReglaCorrelacion $regla, Carbon $ahora, bool $demostracion): int
    {
        $desde = $ahora->copy()->subMinutes($regla->ventana_minutos);

        $grupos = $this->agruparPorIp(
            EventoSeguridad::query()
                ->deFuente(EventoSeguridad::FUENTE_WAF)
                ->where('codigo_respuesta', 403),
            $desde,
            $ahora,
            $regla->umbral,
        );

        $creadas = 0;

        foreach ($grupos as $grupo) {
            $eventos = $this->eventosDelGrupo(
                EventoSeguridad::query()
                    ->deFuente(EventoSeguridad::FUENTE_WAF)
                    ->where('codigo_respuesta', 403)
                    ->where('direccion_ip', $grupo->direccion_ip),
                $desde,
                $ahora,
            );

            $creadas += $this->registrarAlerta(
                regla: $regla,
                huella: $this->huellaAgrupacion($regla, $grupo->direccion_ip, $grupo->ultimo),
                titulo: "Ataque dirigido desde {$grupo->direccion_ip}",
                descripcion: "El WAF devolvio {$grupo->total} respuestas 403 a la direccion {$grupo->direccion_ip} "
                    ."en una ventana de {$regla->ventana_minutos} minutos. El umbral configurado es {$regla->umbral}. "
                    .'El patron corresponde a alguien probando cargas utiles contra el Core Rule Set, no a un usuario legitimo.',
                direccionIp: $grupo->direccion_ip,
                usuarioObjetivoId: null,
                primerEventoEn: Carbon::parse($grupo->primero),
                ahora: $ahora,
                eventos: $eventos,
                conteo: (int) $grupo->total,
                demostracion: $demostracion,
                datosExtra: ['respuestas_403' => (int) $grupo->total],
            );
        }

        return $creadas;
    }

    /**
     * Regla 2. La puntuacion de anomalia de una misma direccion crece con el tiempo.
     * Es el patron del atacante que afina: empieza con sondas que apenas puntuan, mide que
     * pasa el WAF y sube la carga. Se compara la ventana reciente contra una linea base
     * inmediatamente anterior de tres veces su tamano.
     */
    private function detectarEscaladaAnomalia(ReglaCorrelacion $regla, Carbon $ahora, bool $demostracion): int
    {
        $ventana = $regla->ventana_minutos;
        $inicioReciente = $ahora->copy()->subMinutes($ventana);
        $inicioBase = $inicioReciente->copy()->subMinutes($ventana * 3);

        $factor = (float) $regla->parametro('factor_escalada', 2.0);
        $puntuacionMinima = (int) $regla->parametro('puntuacion_minima_reciente', 8);

        $recientes = EventoSeguridad::query()
            ->deFuente(EventoSeguridad::FUENTE_WAF)
            ->whereBetween('marca_tiempo', [$inicioReciente, $ahora])
            ->groupBy('direccion_ip')
            ->havingRaw('COUNT(*) >= ?', [$regla->umbral])
            ->select([
                'direccion_ip',
                DB::raw('COUNT(*) as total'),
                DB::raw('AVG(puntuacion_anomalia) as promedio'),
                DB::raw('MAX(puntuacion_anomalia) as maximo'),
                DB::raw('MIN(marca_tiempo) as primero'),
                DB::raw('MAX(marca_tiempo) as ultimo'),
            ])
            ->get();

        $creadas = 0;

        foreach ($recientes as $reciente) {
            if ((float) $reciente->promedio < $puntuacionMinima) {
                continue;
            }

            $promedioBase = (float) EventoSeguridad::query()
                ->deFuente(EventoSeguridad::FUENTE_WAF)
                ->where('direccion_ip', $reciente->direccion_ip)
                ->whereBetween('marca_tiempo', [$inicioBase, $inicioReciente])
                ->avg('puntuacion_anomalia');

            // Sin linea base no hay escalada que demostrar: es actividad nueva, y de eso ya
            // se encarga la regla de 403 repetidos. Aqui solo interesa la progresion.
            if ($promedioBase <= 0.0) {
                continue;
            }

            if ((float) $reciente->promedio < $promedioBase * $factor) {
                continue;
            }

            $eventos = $this->eventosDelGrupo(
                EventoSeguridad::query()
                    ->deFuente(EventoSeguridad::FUENTE_WAF)
                    ->where('direccion_ip', $reciente->direccion_ip),
                $inicioBase,
                $ahora,
            );

            $promedioReciente = round((float) $reciente->promedio, 1);
            $promedioAnterior = round($promedioBase, 1);

            $creadas += $this->registrarAlerta(
                regla: $regla,
                huella: $this->huellaAgrupacion($regla, $reciente->direccion_ip, $reciente->ultimo),
                titulo: "Escalada de anomalia desde {$reciente->direccion_ip}",
                descripcion: "La puntuacion media de anomalia de {$reciente->direccion_ip} paso de {$promedioAnterior} "
                    ."a {$promedioReciente} en los ultimos {$ventana} minutos, con un maximo de {$reciente->maximo}. "
                    .'El atacante esta midiendo al WAF y subiendo la agresividad de la carga util.',
                direccionIp: $reciente->direccion_ip,
                usuarioObjetivoId: null,
                primerEventoEn: Carbon::parse($reciente->primero),
                ahora: $ahora,
                eventos: $eventos,
                conteo: (int) $reciente->total,
                demostracion: $demostracion,
                datosExtra: [
                    'promedio_linea_base' => $promedioAnterior,
                    'promedio_reciente' => $promedioReciente,
                    'puntuacion_maxima' => (int) $reciente->maximo,
                ],
            );
        }

        return $creadas;
    }

    /**
     * Regla 3. Fuerza bruta contra el inicio de sesion.
     * La aplicacion ya limita intentos, pero el limitador solo frena; nadie se entera.
     * Esta regla convierte ese freno silencioso en una alerta con direccion de origen.
     */
    private function detectarFuerzaBruta(ReglaCorrelacion $regla, Carbon $ahora, bool $demostracion): int
    {
        $desde = $ahora->copy()->subMinutes($regla->ventana_minutos);

        $consultaBase = fn (): Builder => EventoSeguridad::query()
            ->deFuente(EventoSeguridad::FUENTE_APLICACION)
            ->whereJsonContains('etiquetas', 'autenticacion.fallida');

        $grupos = $this->agruparPorIp($consultaBase(), $desde, $ahora, $regla->umbral);

        $creadas = 0;

        foreach ($grupos as $grupo) {
            $eventos = $this->eventosDelGrupo(
                $consultaBase()->where('direccion_ip', $grupo->direccion_ip),
                $desde,
                $ahora,
            );

            $creadas += $this->registrarAlerta(
                regla: $regla,
                huella: $this->huellaAgrupacion($regla, $grupo->direccion_ip, $grupo->ultimo),
                titulo: "Fuerza bruta contra el inicio de sesion desde {$grupo->direccion_ip}",
                descripcion: "Se registraron {$grupo->total} intentos fallidos de inicio de sesion desde "
                    ."{$grupo->direccion_ip} en {$regla->ventana_minutos} minutos (umbral {$regla->umbral}). "
                    .'Un usuario que olvido su contrasena no llega a esa cifra.',
                direccionIp: $grupo->direccion_ip,
                usuarioObjetivoId: null,
                primerEventoEn: Carbon::parse($grupo->primero),
                ahora: $ahora,
                eventos: $eventos,
                conteo: (int) $grupo->total,
                demostracion: $demostracion,
                datosExtra: ['intentos_fallidos' => (int) $grupo->total],
            );
        }

        return $creadas;
    }

    /**
     * Regla 4. Fallos repetidos del segundo factor sobre la misma cuenta.
     * Es la alerta mas grave del conjunto aunque el numero sea pequeno: para llegar a la
     * pantalla del segundo factor hay que haber acertado ya la contrasena. La credencial
     * de esa cuenta esta comprometida, este o no el atacante logrando entrar.
     */
    private function detectarSegundoFactorFallido(ReglaCorrelacion $regla, Carbon $ahora, bool $demostracion): int
    {
        $desde = $ahora->copy()->subMinutes($regla->ventana_minutos);

        $consultaBase = fn (): Builder => EventoSeguridad::query()
            ->deFuente(EventoSeguridad::FUENTE_APLICACION)
            ->whereJsonContains('etiquetas', 'segundo_factor.fallido')
            ->whereNotNull('usuario_id');

        $grupos = $consultaBase()
            ->whereBetween('marca_tiempo', [$desde, $ahora])
            ->groupBy('usuario_id')
            ->havingRaw('COUNT(*) >= ?', [$regla->umbral])
            ->select([
                'usuario_id',
                DB::raw('COUNT(*) as total'),
                DB::raw('MIN(marca_tiempo) as primero'),
                DB::raw('MAX(marca_tiempo) as ultimo'),
                DB::raw('MAX(direccion_ip) as direccion_ip'),
            ])
            ->get();

        $creadas = 0;

        foreach ($grupos as $grupo) {
            $eventos = $this->eventosDelGrupo(
                $consultaBase()->where('usuario_id', $grupo->usuario_id),
                $desde,
                $ahora,
            );

            $creadas += $this->registrarAlerta(
                regla: $regla,
                huella: $this->huellaAgrupacion($regla, 'usuario:'.$grupo->usuario_id, $grupo->ultimo),
                titulo: 'Segundo factor atacado en la cuenta '.$grupo->usuario_id,
                descripcion: "La cuenta {$grupo->usuario_id} acumulo {$grupo->total} fallos del segundo factor en "
                    ."{$regla->ventana_minutos} minutos. Llegar a esa pantalla exige la contrasena correcta: "
                    .'hay que tratar la credencial como comprometida.',
                direccionIp: $grupo->direccion_ip,
                usuarioObjetivoId: (int) $grupo->usuario_id,
                primerEventoEn: Carbon::parse($grupo->primero),
                ahora: $ahora,
                eventos: $eventos,
                conteo: (int) $grupo->total,
                demostracion: $demostracion,
                datosExtra: ['fallos_segundo_factor' => (int) $grupo->total],
            );
        }

        return $creadas;
    }

    /**
     * Regla 5. Rastreador falsificado, reglas propias 15020 a 15029 del WAF.
     * Alguien que se hace pasar por Googlebot busca contenido que no daria a un visitante
     * normal: es la antesala del encubrimiento y de la inyeccion de enlaces.
     */
    private function detectarRastreadorFalsificado(ReglaCorrelacion $regla, Carbon $ahora, bool $demostracion): int
    {
        $desde = $ahora->copy()->subMinutes($regla->ventana_minutos);
        $identificadores = $this->rango(self::RASTREADOR_FALSIFICADO_DESDE, self::RASTREADOR_FALSIFICADO_HASTA);

        $consultaBase = fn (): Builder => EventoSeguridad::query()
            ->deFuente(EventoSeguridad::FUENTE_WAF)
            ->conRegla($identificadores);

        $grupos = $this->agruparPorIp($consultaBase(), $desde, $ahora, $regla->umbral);

        $creadas = 0;

        foreach ($grupos as $grupo) {
            $eventos = $this->eventosDelGrupo(
                $consultaBase()->where('direccion_ip', $grupo->direccion_ip),
                $desde,
                $ahora,
            );

            $creadas += $this->registrarAlerta(
                regla: $regla,
                huella: $this->huellaAgrupacion($regla, $grupo->direccion_ip, $grupo->ultimo),
                titulo: "Rastreador falsificado desde {$grupo->direccion_ip}",
                descripcion: "Las reglas propias 15020-15029 marcaron {$grupo->total} peticiones desde "
                    ."{$grupo->direccion_ip} que dicen ser de un rastreador de buscador sin proceder de sus rangos. "
                    .'Es el preludio del encubrimiento y de la inyeccion de enlaces en el catalogo.',
                direccionIp: $grupo->direccion_ip,
                usuarioObjetivoId: null,
                primerEventoEn: Carbon::parse($grupo->primero),
                ahora: $ahora,
                eventos: $eventos,
                conteo: (int) $grupo->total,
                demostracion: $demostracion,
                datosExtra: ['peticiones_marcadas' => (int) $grupo->total],
            );
        }

        return $creadas;
    }

    /**
     * Regla 6. Extraccion masiva del catalogo.
     * Se dispara por volumen o porque las reglas propias 15040-15049 ya lo marcaron. El
     * volumen importa: copiar el catalogo completo de MarketGT es robo de inventario y
     * ademas degrada el servicio para los clientes reales.
     */
    private function detectarExtraccionMasiva(ReglaCorrelacion $regla, Carbon $ahora, bool $demostracion): int
    {
        $desde = $ahora->copy()->subMinutes($regla->ventana_minutos);
        $identificadores = $this->rango(self::EXTRACCION_MASIVA_DESDE, self::EXTRACCION_MASIVA_HASTA);

        /** @var array<int, string> $patronesCatalogo */
        $patronesCatalogo = (array) $regla->parametro('rutas_catalogo', ['/producto', '/catalogo', '/categoria', '/buscar']);

        $consultaBase = function () use ($identificadores, $patronesCatalogo): Builder {
            return EventoSeguridad::query()
                ->deFuente(EventoSeguridad::FUENTE_WAF)
                ->where(function (Builder $interna) use ($identificadores, $patronesCatalogo): void {
                    $interna->conRegla($identificadores);

                    foreach ($patronesCatalogo as $patron) {
                        $interna->orWhere('ruta', 'like', $patron.'%');
                    }
                });
        };

        $grupos = $this->agruparPorIp($consultaBase(), $desde, $ahora, $regla->umbral);

        $creadas = 0;

        foreach ($grupos as $grupo) {
            $eventos = $this->eventosDelGrupo(
                $consultaBase()->where('direccion_ip', $grupo->direccion_ip),
                $desde,
                $ahora,
            );

            $porMinuto = $regla->ventana_minutos > 0
                ? round((int) $grupo->total / $regla->ventana_minutos, 1)
                : (int) $grupo->total;

            $creadas += $this->registrarAlerta(
                regla: $regla,
                huella: $this->huellaAgrupacion($regla, $grupo->direccion_ip, $grupo->ultimo),
                titulo: "Extraccion masiva del catalogo desde {$grupo->direccion_ip}",
                descripcion: "{$grupo->direccion_ip} pidio {$grupo->total} recursos del catalogo en "
                    ."{$regla->ventana_minutos} minutos, unos {$porMinuto} por minuto. Ningun cliente navega "
                    .'a ese ritmo: se esta copiando el inventario y los precios de MarketGT.',
                direccionIp: $grupo->direccion_ip,
                usuarioObjetivoId: null,
                primerEventoEn: Carbon::parse($grupo->primero),
                ahora: $ahora,
                eventos: $eventos,
                conteo: (int) $grupo->total,
                demostracion: $demostracion,
                datosExtra: [
                    'peticiones' => (int) $grupo->total,
                    'peticiones_por_minuto' => $porMinuto,
                ],
            );
        }

        return $creadas;
    }

    /**
     * Regla 7. Un solo evento de severidad critica.
     * No hay umbral que esperar: si el WAF registro algo critico, ya paso. La ventana existe
     * solo para acotar lo que el motor revisa en cada pasada.
     */
    private function detectarEventoCriticoUnico(ReglaCorrelacion $regla, Carbon $ahora, bool $demostracion): int
    {
        $desde = $ahora->copy()->subMinutes($regla->ventana_minutos);

        $eventos = EventoSeguridad::query()
            ->where('severidad', EventoSeguridad::SEVERIDAD_CRITICA)
            ->whereBetween('marca_tiempo', [$desde, $ahora])
            ->orderByDesc('marca_tiempo')
            ->limit(100)
            ->get();

        $creadas = 0;

        foreach ($eventos as $evento) {
            $reglaDisparada = $evento->reglaPrincipal() ?? 'sin regla';

            $creadas += $this->registrarAlerta(
                regla: $regla,
                huella: $this->huellaAgrupacion($regla, 'evento:'.$evento->id, $evento->marca_tiempo),
                titulo: "Evento critico unico desde {$evento->direccion_ip}",
                descripcion: "Se registro un evento de severidad critica (regla {$reglaDisparada}, puntuacion "
                    ."{$evento->puntuacion_anomalia}) desde {$evento->direccion_ip} sobre "
                    .($evento->ruta ?? 'la aplicacion').'. Un solo evento critico ya justifica revision inmediata.',
                direccionIp: $evento->direccion_ip,
                usuarioObjetivoId: $evento->usuario_id,
                primerEventoEn: $evento->marca_tiempo,
                ahora: $ahora,
                eventos: collect([$evento]),
                conteo: 1,
                demostracion: $demostracion,
                datosExtra: [
                    'identificador_transaccion' => $evento->identificador_transaccion,
                    'puntuacion_anomalia' => $evento->puntuacion_anomalia,
                ],
            );
        }

        return $creadas;
    }

    /**
     * Agrupa por direccion aplicando el umbral de la regla.
     *
     * @param  Builder<EventoSeguridad>  $consulta
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function agruparPorIp(Builder $consulta, Carbon $desde, Carbon $hasta, int $umbral): \Illuminate\Support\Collection
    {
        return $consulta
            ->whereBetween('marca_tiempo', [$desde, $hasta])
            ->groupBy('direccion_ip')
            ->havingRaw('COUNT(*) >= ?', [$umbral])
            ->select([
                'direccion_ip',
                DB::raw('COUNT(*) as total'),
                DB::raw('MIN(marca_tiempo) as primero'),
                DB::raw('MAX(marca_tiempo) as ultimo'),
            ])
            ->get()
            ->map(static fn (EventoSeguridad $fila): object => (object) $fila->getAttributes());
    }

    /**
     * @param  Builder<EventoSeguridad>  $consulta
     * @return \Illuminate\Database\Eloquent\Collection<int, EventoSeguridad>
     */
    private function eventosDelGrupo(Builder $consulta, Carbon $desde, Carbon $hasta): \Illuminate\Database\Eloquent\Collection
    {
        return $consulta
            ->whereBetween('marca_tiempo', [$desde, $hasta])
            ->orderByDesc('marca_tiempo')
            ->limit(self::MUESTRA_EVIDENCIA)
            ->get();
    }

    /**
     * Crea la alerta si el patron no estaba ya reportado en esta ventana.
     *
     * @param  \Illuminate\Support\Collection<int, EventoSeguridad>  $eventos
     * @param  array<string, mixed>  $datosExtra
     * @return int  1 si nacio una alerta nueva, 0 si solo se engordo una existente.
     */
    private function registrarAlerta(
        ReglaCorrelacion $regla,
        string $huella,
        string $titulo,
        string $descripcion,
        ?string $direccionIp,
        ?int $usuarioObjetivoId,
        ?Carbon $primerEventoEn,
        Carbon $ahora,
        \Illuminate\Support\Collection $eventos,
        int $conteo,
        bool $demostracion,
        array $datosExtra = [],
    ): int {
        $evidencia = array_merge($datosExtra, [
            'ventana_minutos' => $regla->ventana_minutos,
            'umbral' => $regla->umbral,
            'eventos_observados' => $conteo,
            'muestra' => $eventos->map(static fn (EventoSeguridad $evento): array => [
                'id' => $evento->id,
                'marca_tiempo' => $evento->marca_tiempo?->toDateTimeString(),
                'fuente' => $evento->fuente,
                'metodo' => $evento->metodo,
                'ruta' => Str::limit((string) $evento->ruta, 180),
                'codigo_respuesta' => $evento->codigo_respuesta,
                'reglas' => $evento->identificadores_regla,
                'puntuacion_anomalia' => $evento->puntuacion_anomalia,
                'mensaje' => Str::limit((string) $evento->mensaje, 240),
            ])->values()->all(),
        ]);

        $existente = AlertaSeguridad::query()->where('huella_agrupacion', $huella)->first();

        if ($existente !== null) {
            // Mientras el analista no la haya cerrado, la alerta sigue siendo la misma
            // historia: se actualiza el recuento y la evidencia, nunca el estado ni las
            // marcas de tiempo, que son las que sostienen las metricas.
            if (in_array($existente->estado, AlertaSeguridad::ESTADOS_ABIERTOS, true)) {
                $existente->conteo_eventos = max($existente->conteo_eventos, $conteo);
                $existente->evidencia = $evidencia;
                $existente->save();
                $existente->eventos()->syncWithoutDetaching($eventos->pluck('id')->all());
            }

            return 0;
        }

        $alerta = AlertaSeguridad::query()->create([
            'regla_correlacion_id' => $regla->id,
            'clave_regla' => $regla->clave,
            'titulo' => Str::limit($titulo, 250),
            'descripcion' => $descripcion,
            'severidad' => $regla->severidad,
            'estado' => AlertaSeguridad::ESTADO_NUEVA,
            'direccion_ip' => $direccionIp,
            'usuario_objetivo_id' => $usuarioObjetivoId,
            'primer_evento_en' => $primerEventoEn,
            'detectada_en' => $ahora,
            'evidencia' => $evidencia,
            'accion_recomendada' => $regla->accion_recomendada,
            'conteo_eventos' => $conteo,
            'huella_agrupacion' => $huella,
            'es_demostracion' => $demostracion,
        ]);

        $alerta->eventos()->syncWithoutDetaching($eventos->pluck('id')->all());

        return 1;
    }

    /**
     * La huella agrupa por regla, objetivo y cubo de tiempo del tamano de la ventana. Sin
     * esto, cada pasada del motor generaria una alerta nueva del mismo ataque y el analista
     * quedaria sepultado, que es exactamente como muere un SIEM en produccion.
     */
    private function huellaAgrupacion(ReglaCorrelacion $regla, string $objetivo, Carbon|string $ultimoEvento): string
    {
        $momento = $ultimoEvento instanceof Carbon ? $ultimoEvento->copy() : Carbon::parse($ultimoEvento);
        $ventana = max($regla->ventana_minutos, 1);
        $cubo = (int) floor($momento->getTimestamp() / ($ventana * 60));

        return substr(hash('sha256', $regla->clave.'|'.$objetivo.'|'.$cubo), 0, 40).':'.$regla->clave;
    }

    /**
     * @return array<int, int>
     */
    private function rango(int $desde, int $hasta): array
    {
        return range($desde, $hasta);
    }

    /**
     * Siembra el catalogo de reglas. Se usa firstOrCreate para que reejecutarlo no pise los
     * umbrales que el analista haya afinado a mano desde la base.
     *
     * @return int  Reglas creadas en esta llamada.
     */
    public function sembrarReglas(): int
    {
        $creadas = 0;

        foreach ($this->reglasPorDefecto() as $definicion) {
            $regla = ReglaCorrelacion::query()->firstOrCreate(
                ['clave' => $definicion['clave']],
                $definicion,
            );

            if ($regla->wasRecentlyCreated) {
                $creadas++;
            }
        }

        return $creadas;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function reglasPorDefecto(): array
    {
        return [
            [
                'clave' => ReglaCorrelacion::WAF_403_REPETIDO,
                'nombre' => 'Respuestas 403 repetidas del WAF',
                'descripcion' => 'Mas de N respuestas 403 del WAF desde la misma direccion dentro de la ventana.',
                'severidad' => EventoSeguridad::SEVERIDAD_ALTA,
                'umbral' => 12,
                'ventana_minutos' => 5,
                'accion_recomendada' => 'Confirmar el patron en el registro de auditoria del WAF con el identificador de '
                    .'transaccion. Si se confirma, bloquear la direccion en Cloudflare (capa 1) y anadir la regla de '
                    .'fail2ban correspondiente (capa 2). Registrar la contencion en esta misma alerta.',
                'justificacion_umbral' => 'Con paranoia 1 y umbral de anomalia 5, el Core Rule Set produce muy pocos 403 '
                    .'espurios. Un usuario real que tropieza con una regla lo hace una o dos veces y se detiene; doce en '
                    .'cinco minutos solo se alcanza iterando cargas utiles a proposito.',
                'parametros' => null,
                'activa' => true,
            ],
            [
                'clave' => ReglaCorrelacion::ESCALADA_ANOMALIA,
                'nombre' => 'Escalada de la puntuacion de anomalia',
                'descripcion' => 'La puntuacion media de anomalia de una direccion se multiplica respecto de su linea base.',
                'severidad' => EventoSeguridad::SEVERIDAD_ALTA,
                'umbral' => 5,
                'ventana_minutos' => 15,
                'accion_recomendada' => 'Revisar la progresion de cargas utiles de esa direccion. Si la puntuacion se acerca '
                    .'al umbral de bloqueo sin superarlo, evaluar si falta una regla: el atacante puede haber encontrado el '
                    .'borde exacto del Core Rule Set.',
                'justificacion_umbral' => 'Se exigen cinco eventos recientes para que el promedio signifique algo y un factor '
                    .'de dos respecto de la linea base anterior, tres veces mas larga. Un factor menor dispararia con la '
                    .'variacion normal del trafico.',
                'parametros' => ['factor_escalada' => 2.0, 'puntuacion_minima_reciente' => 8],
                'activa' => true,
            ],
            [
                'clave' => ReglaCorrelacion::FUERZA_BRUTA_SESION,
                'nombre' => 'Fuerza bruta contra el inicio de sesion',
                'descripcion' => 'Intentos fallidos de inicio de sesion repetidos desde la misma direccion.',
                'severidad' => EventoSeguridad::SEVERIDAD_ALTA,
                'umbral' => 8,
                'ventana_minutos' => 10,
                'accion_recomendada' => 'Comprobar si alguna de las cuentas atacadas llego a autenticarse. Bloquear la '
                    .'direccion en la capa 2 y avisar a las cuentas afectadas para que revisen su segundo factor.',
                'justificacion_umbral' => 'Laravel limita a cinco intentos por minuto. Ocho fallos en diez minutos supera lo '
                    .'que produce una persona que olvido su contrasena sin castigar al que se equivoca dos o tres veces.',
                'parametros' => null,
                'activa' => true,
            ],
            [
                'clave' => ReglaCorrelacion::SEGUNDO_FACTOR_FALLIDO,
                'nombre' => 'Segundo factor fallido de forma repetida',
                'descripcion' => 'Fallos repetidos del segundo factor sobre la misma cuenta.',
                'severidad' => EventoSeguridad::SEVERIDAD_CRITICA,
                'umbral' => 5,
                'ventana_minutos' => 15,
                'accion_recomendada' => 'Tratar la contrasena de la cuenta como comprometida: forzar su cambio, invalidar las '
                    .'sesiones activas y regenerar los codigos de recuperacion. Contactar a la persona titular antes de cerrar.',
                'justificacion_umbral' => 'Un codigo TOTP caduca cada treinta segundos y equivocarse dos veces seguidas es '
                    .'comun por desfase de reloj. Cinco fallos en quince minutos ya no es torpeza; y el atacante llego ahi '
                    .'porque la contrasena era correcta, por eso la severidad es critica pese al umbral bajo.',
                'parametros' => null,
                'activa' => true,
            ],
            [
                'clave' => ReglaCorrelacion::RASTREADOR_FALSIFICADO,
                'nombre' => 'Rastreador falsificado',
                'descripcion' => 'Peticiones marcadas por las reglas propias 15020-15029 del WAF.',
                'severidad' => EventoSeguridad::SEVERIDAD_MEDIA,
                'umbral' => 1,
                'ventana_minutos' => 60,
                'accion_recomendada' => 'Verificar la resolucion inversa de la direccion contra los rangos publicados del '
                    .'buscador. Si no coincide, bloquearla y revisar si hubo encubrimiento o enlaces inyectados en las '
                    .'fichas de producto que visito.',
                'justificacion_umbral' => 'La regla del WAF ya valida la resolucion inversa antes de marcar, asi que un solo '
                    .'acierto es evidencia suficiente. La ventana de una hora agrupa la sesion completa del falso rastreador '
                    .'en una alerta en vez de una por peticion.',
                'parametros' => ['rango_reglas' => [self::RASTREADOR_FALSIFICADO_DESDE, self::RASTREADOR_FALSIFICADO_HASTA]],
                'activa' => true,
            ],
            [
                'clave' => ReglaCorrelacion::EXTRACCION_MASIVA,
                'nombre' => 'Extraccion masiva de contenido',
                'descripcion' => 'Volumen anormal de peticiones al catalogo desde una sola direccion.',
                'severidad' => EventoSeguridad::SEVERIDAD_ALTA,
                'umbral' => 250,
                'ventana_minutos' => 10,
                'accion_recomendada' => 'Limitar la tasa de esa direccion en Cloudflare antes de bloquearla del todo: si es '
                    .'un comparador de precios conocido conviene negociar, no cortar. Documentar la decision en la alerta.',
                'justificacion_umbral' => 'Doscientas cincuenta peticiones en diez minutos son veinticinco por minuto, mas de '
                    .'una cada tres segundos de forma sostenida. Ningun cliente navega asi, y el limite deja holgura para '
                    .'las peticiones legitimas de una pagina de catalogo con muchas imagenes.',
                'parametros' => [
                    'rutas_catalogo' => ['/producto', '/catalogo', '/categoria', '/buscar'],
                    'rango_reglas' => [self::EXTRACCION_MASIVA_DESDE, self::EXTRACCION_MASIVA_HASTA],
                ],
                'activa' => true,
            ],
            [
                'clave' => ReglaCorrelacion::EVENTO_CRITICO_UNICO,
                'nombre' => 'Evento unico de severidad critica',
                'descripcion' => 'Cualquier evento clasificado como critico genera alerta por si solo.',
                'severidad' => EventoSeguridad::SEVERIDAD_CRITICA,
                'umbral' => 1,
                'ventana_minutos' => 10,
                'accion_recomendada' => 'Abrir el registro de auditoria del WAF por el identificador de transaccion y decidir '
                    .'en el momento si hubo impacto. Si la peticion no fue interrumpida, revisar tambien el registro de la '
                    .'aplicacion y el de MariaDB para el mismo minuto.',
                'justificacion_umbral' => 'No hay umbral que esperar: una severidad critica describe un intento con potencial '
                    .'de ejecucion o de fuga. Agrupar seria retrasar la respuesta.',
                'parametros' => null,
                'activa' => true,
            ],
        ];
    }
}
