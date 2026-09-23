<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;

/**
 * Acta de una prueba de restauracion ejecutada de verdad.
 *
 * El RTO y el RPO no se deducen de como estan configurados los respaldos: se miden
 * restaurando. Esta tabla existe para que el panel pueda decir "la ultima restauracion
 * tardo tanto" en lugar de "la politica dice que tardaria tanto", que son dos afirmaciones
 * completamente distintas delante de un auditor.
 *
 * @property int $id
 * @property CarbonInterface $iniciada_en
 * @property CarbonInterface|null $terminada_en
 * @property string $resultado
 * @property string|null $fase_fallida
 * @property string|null $error
 * @property string $origen
 * @property int|null $ejecutada_por
 * @property string $actor
 * @property string|null $anfitrion
 * @property string|null $comando
 * @property string|null $modo_cliente
 * @property string $conexion
 * @property string $base_origen
 * @property string $base_prueba
 * @property float|null $segundos_volcado
 * @property float|null $segundos_cifrado
 * @property float|null $segundos_descifrado
 * @property float|null $segundos_restauracion
 * @property float|null $segundos_verificacion
 * @property float|null $segundos_recuperacion
 * @property int|null $bytes_volcado
 * @property string|null $algoritmo_cifrado
 * @property bool $cifrado_verificado
 * @property string|null $huella_volcado
 * @property bool $verificacion_superada
 * @property int $tablas_comparadas
 * @property int $filas_comparadas
 * @property array<string, int>|null $conteos_origen
 * @property array<string, int>|null $conteos_restaurada
 * @property array<string, mixed>|null $discrepancias
 * @property CarbonInterface|null $respaldo_mas_reciente_en
 * @property string|null $respaldo_mas_reciente_ruta
 * @property int|null $antiguedad_respaldo_minutos
 * @property int $respaldos_encontrados
 * @property string|null $ruta_respaldos
 * @property string|null $salida
 * @property bool $es_demostracion
 */
class PruebaRestauracion extends Model
{
    protected $table = 'pruebas_restauracion';

    public const RESULTADO_SATISFACTORIA = 'satisfactoria';

    public const RESULTADO_FALLIDA = 'fallida';

    /**
     * Quien pidio la prueba. No es lo mismo un simulacro que alguien ejecuto a mano el dia
     * antes de la presentacion que uno que el programador de tareas repite cada mes.
     */
    public const ORIGEN_MANUAL = 'manual';

    public const ORIGEN_PROGRAMADO = 'programado';

    public const ORIGEN_GUION = 'guion';

    /**
     * @var array<int, string>
     */
    public const ORIGENES = [
        self::ORIGEN_MANUAL,
        self::ORIGEN_PROGRAMADO,
        self::ORIGEN_GUION,
    ];

    /**
     * @var array<string, string>
     */
    public const ETIQUETAS_ORIGEN = [
        self::ORIGEN_MANUAL => 'Manual',
        self::ORIGEN_PROGRAMADO => 'Programada',
        self::ORIGEN_GUION => 'Guion del servidor',
    ];

    /**
     * Periodicidad que exige el anexo del proyecto. Pasada esta cifra la ultima acta deja de
     * demostrar nada: un RTO medido hace medio ano no describe la plataforma de hoy.
     */
    public const PERIODICIDAD_DIAS = 30;

    /**
     * Por debajo de este volumen la prueba demuestra que el procedimiento funciona, pero no
     * que el tiempo medido se sostendria con los datos de produccion. Se avisa en lugar de
     * callarlo: una restauracion de una base casi vacia siempre sale rapidisima.
     */
    public const FILAS_MINIMAS_REPRESENTATIVAS = 1000;

    protected $fillable = [
        'iniciada_en',
        'terminada_en',
        'resultado',
        'fase_fallida',
        'error',
        'origen',
        'ejecutada_por',
        'actor',
        'anfitrion',
        'comando',
        'modo_cliente',
        'conexion',
        'base_origen',
        'base_prueba',
        'segundos_volcado',
        'segundos_cifrado',
        'segundos_descifrado',
        'segundos_restauracion',
        'segundos_verificacion',
        'segundos_recuperacion',
        'bytes_volcado',
        'algoritmo_cifrado',
        'cifrado_verificado',
        'huella_volcado',
        'verificacion_superada',
        'tablas_comparadas',
        'filas_comparadas',
        'conteos_origen',
        'conteos_restaurada',
        'discrepancias',
        'respaldo_mas_reciente_en',
        'respaldo_mas_reciente_ruta',
        'antiguedad_respaldo_minutos',
        'respaldos_encontrados',
        'ruta_respaldos',
        'salida',
        'es_demostracion',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'iniciada_en' => 'datetime',
            'terminada_en' => 'datetime',
            'respaldo_mas_reciente_en' => 'datetime',
            'conteos_origen' => 'array',
            'conteos_restaurada' => 'array',
            'discrepancias' => 'array',
            'cifrado_verificado' => 'boolean',
            'verificacion_superada' => 'boolean',
            'es_demostracion' => 'boolean',
            'segundos_volcado' => 'float',
            'segundos_cifrado' => 'float',
            'segundos_descifrado' => 'float',
            'segundos_restauracion' => 'float',
            'segundos_verificacion' => 'float',
            'segundos_recuperacion' => 'float',
            'bytes_volcado' => 'integer',
            'tablas_comparadas' => 'integer',
            'filas_comparadas' => 'integer',
            'antiguedad_respaldo_minutos' => 'integer',
            'respaldos_encontrados' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function operador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ejecutada_por');
    }

    /**
     * @param  Builder<$this>  $consulta
     * @return Builder<$this>
     */
    public function scopeSatisfactorias(Builder $consulta): Builder
    {
        return $consulta->where('resultado', self::RESULTADO_SATISFACTORIA);
    }

    /**
     * La ultima prueba que de verdad demostro algo. Una prueba fallida tambien se guarda,
     * porque el historial de intentos fallidos es informacion de auditoria, pero no mide.
     */
    public static function ultimaSatisfactoria(): ?self
    {
        return self::query()
            ->satisfactorias()
            ->whereNotNull('segundos_recuperacion')
            ->orderByDesc('iniciada_en')
            ->first();
    }

    public static function ultima(): ?self
    {
        return self::query()->orderByDesc('iniciada_en')->first();
    }

    public function fueSatisfactoria(): bool
    {
        return $this->resultado === self::RESULTADO_SATISFACTORIA;
    }

    /**
     * Horas que tardo la recuperacion medida. Es el numerador del RTO.
     */
    public function horasRecuperacion(): ?float
    {
        if ($this->segundos_recuperacion === null) {
            return null;
        }

        return round($this->segundos_recuperacion / 3600, 4);
    }

    public function horasAntiguedadRespaldo(): ?float
    {
        if ($this->antiguedad_respaldo_minutos === null) {
            return null;
        }

        return round($this->antiguedad_respaldo_minutos / 60, 2);
    }

    public function diasDesdeLaPrueba(?CarbonInterface $ahora = null): int
    {
        $ahora = $ahora?->copy() ?? CarbonImmutable::now();

        return (int) floor($this->iniciada_en->diffInDays($ahora, absolute: true));
    }

    public function etiquetaOrigen(): string
    {
        return self::ETIQUETAS_ORIGEN[$this->origen] ?? $this->origen;
    }

    /**
     * Quien la ejecuto, en una sola frase. Si hubo cuenta de la plataforma manda esa, porque
     * identifica a una persona; el usuario del sistema identifica solo a una sesion.
     */
    public function responsable(): string
    {
        $cuenta = $this->operador?->name;

        return $cuenta !== null && $cuenta !== ''
            ? $cuenta.' ('.$this->actor.')'
            : $this->actor;
    }

    /**
     * Duracion en la unidad que se lee de un vistazo. Un tablero que escribe "0.842 h"
     * obliga a hacer la cuenta mentalmente para entender que fueron tres minutos.
     */
    public static function duracionLegible(?float $segundos): string
    {
        if ($segundos === null) {
            return 'sin datos';
        }

        if ($segundos < 1) {
            return number_format($segundos * 1000, 0).' ms';
        }

        if ($segundos < 90) {
            return number_format($segundos, 1).' s';
        }

        if ($segundos < 3600) {
            return number_format($segundos / 60, 1).' min';
        }

        return number_format($segundos / 3600, 2).' h';
    }

    /**
     * La antiguedad de un respaldo se mide en minutos, de modo que no puede compartir
     * formateador con las duraciones de las fases: un respaldo de hace cuarenta segundos
     * saldria como "0 ms" y pareceria un fallo de medicion en vez de un respaldo recien hecho.
     */
    public static function antiguedadLegible(?int $minutos): string
    {
        if ($minutos === null) {
            return 'sin datos';
        }

        if ($minutos < 1) {
            return 'menos de 1 min';
        }

        if ($minutos < 60) {
            return $minutos.' min';
        }

        if ($minutos < 1440) {
            return number_format($minutos / 60, 1).' h';
        }

        return number_format($minutos / 1440, 1).' dias';
    }

    public static function tamanoLegible(?int $bytes): string
    {
        if ($bytes === null) {
            return 'sin datos';
        }

        foreach (['B', 'KiB', 'MiB', 'GiB'] as $indice => $unidad) {
            $division = 1024 ** $indice;

            if ($bytes < $division * 1024 || $unidad === 'GiB') {
                return number_format($bytes / $division, $indice === 0 ? 0 : 1).' '.$unidad;
            }
        }

        return $bytes.' B';
    }

    /**
     * Los dos hechos que alimentan el vertice de respuesta, con su procedencia redactada.
     *
     * Devuelve valores nulos cuando no hay ninguna prueba satisfactoria. Ese nulo es el que
     * mantiene las metricas en "sin datos", y es un resultado legitimo: mientras nadie haya
     * restaurado nada, la plataforma no tiene forma honesta de afirmar cuanto tardaria.
     *
     * @return array{
     *     prueba: self|null,
     *     rto_horas: float|null,
     *     rto_muestra: int,
     *     rto_origen: string,
     *     rpo_horas: float|null,
     *     rpo_origen: string,
     *     dias_desde_prueba: int|null,
     *     vencida: bool,
     *     advertencia: string|null,
     * }
     */
    public static function medicionRecuperacion(?CarbonInterface $ahora = null): array
    {
        $ahora = $ahora?->copy() ?? CarbonImmutable::now();

        try {
            $prueba = self::ultimaSatisfactoria();
            $intentos = $prueba === null ? self::query()->count() : 0;
        } catch (QueryException) {
            // Si la tabla todavia no existe, la metrica se declara sin datos y DICE por que.
            // Ni se cae el triangulo entero ni se finge que no pasa nada: un panel que
            // enmascara su propio fallo de despliegue es peor que uno que se rompe.
            return self::sinMedicion(
                'No se puede leer el registro de pruebas de restauracion: la tabla pruebas_restauracion '
                    .'no existe todavia. Ejecute "php artisan migrate".'
            );
        }

        if ($prueba === null) {
            return self::sinMedicion($intentos === 0
                ? 'No hay ninguna prueba de restauracion registrada. Ejecute '
                    .'"php artisan siem:probar-restauracion" o infra/scripts/probar-restauracion.sh: '
                    .'el objetivo se mide restaurando, no leyendo la configuracion del respaldo.'
                : ($intentos === 1
                    ? 'La unica prueba registrada termino sin verificacion satisfactoria, de modo que no '
                        .'demuestra ningun tiempo de recuperacion. Revise el historial de continuidad.'
                    : 'Las '.$intentos.' pruebas registradas terminaron sin verificacion satisfactoria, '
                        .'de modo que ninguna demuestra un tiempo de recuperacion. Revise el historial de continuidad.'));
        }

        $dias = $prueba->diasDesdeLaPrueba($ahora);
        $vencida = $dias > self::PERIODICIDAD_DIAS;
        $muestra = self::query()->satisfactorias()->count();

        $avisos = [];

        if ($vencida) {
            $avisos[] = 'La ultima prueba satisfactoria tiene '.$dias.' dias y el anexo exige una mensual: '
                .'la cifra describe la plataforma de hace '.$dias.' dias, no la de hoy.';
        }

        if ($prueba->filas_comparadas < self::FILAS_MINIMAS_REPRESENTATIVAS) {
            $avisos[] = 'La prueba restauro '.number_format($prueba->filas_comparadas).' filas: demuestra que el '
                .'procedimiento funciona, pero con ese volumen el tiempo medido no representa al de produccion.';
        }

        // La cifra exacta va en la procedencia porque el panel del triangulo redondea a
        // minutos: una recuperacion de tres segundos se mostraria como "0 min" y parecería
        // que no se midio, cuando lo que pasa es que tardo menos de lo que la unidad resuelve.
        $rtoOrigen = sprintf(
            'Duracion medida de la recuperacion (descifrado + restauracion + verificacion): %s, en la prueba del %s '
                .'ejecutada por %s sobre la base de prueba %s. Se verificaron %d tablas y %s filas.',
            self::duracionLegible($prueba->segundos_recuperacion),
            $prueba->iniciada_en->format('d/m/Y H:i'),
            $prueba->responsable(),
            $prueba->base_prueba,
            $prueba->tablas_comparadas,
            number_format($prueba->filas_comparadas),
        );

        if ($prueba->antiguedad_respaldo_minutos === null) {
            $rpoOrigen = 'La prueba del '.$prueba->iniciada_en->format('d/m/Y H:i').' no encontro ningun respaldo en '
                .($prueba->ruta_respaldos ?? 'el directorio configurado')
                .'. Sin un respaldo que fechar no hay punto de recuperacion que medir.';
        } else {
            $rpoOrigen = sprintf(
                'Antiguedad del respaldo mas reciente, fechado el %s, medida al iniciar la prueba del %s y antes de '
                    .'volcar nada. Se encontraron %d respaldos en %s.',
                $prueba->respaldo_mas_reciente_en?->format('d/m/Y H:i') ?? 'sin fecha',
                $prueba->iniciada_en->format('d/m/Y H:i'),
                $prueba->respaldos_encontrados,
                $prueba->ruta_respaldos ?? 'el directorio configurado',
            );
        }

        return [
            'prueba' => $prueba,
            'rto_horas' => $prueba->horasRecuperacion(),
            'rto_muestra' => $muestra,
            'rto_origen' => $rtoOrigen,
            'rpo_horas' => $prueba->horasAntiguedadRespaldo(),
            'rpo_origen' => $rpoOrigen,
            'dias_desde_prueba' => $dias,
            'vencida' => $vencida,
            'advertencia' => $avisos === [] ? null : implode(' ', $avisos),
        ];
    }

    /**
     * Forma canonica del "todavia no se puede medir". Existe para que el motivo viaje con el
     * hueco: una metrica sin datos que no explica cual es el dato que falta no se puede cerrar.
     *
     * @return array{
     *     prueba: self|null,
     *     rto_horas: float|null,
     *     rto_muestra: int,
     *     rto_origen: string,
     *     rpo_horas: float|null,
     *     rpo_origen: string,
     *     dias_desde_prueba: int|null,
     *     vencida: bool,
     *     advertencia: string|null,
     * }
     */
    private static function sinMedicion(string $motivo): array
    {
        return [
            'prueba' => null,
            'rto_horas' => null,
            'rto_muestra' => 0,
            'rto_origen' => $motivo,
            'rpo_horas' => null,
            'rpo_origen' => $motivo,
            'dias_desde_prueba' => null,
            'vencida' => true,
            'advertencia' => null,
        ];
    }
}
