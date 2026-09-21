<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Evento de seguridad normalizado. Tres fuentes distintas (WAF, aplicacion y sistema
 * operativo) aterrizan en esta misma forma para poder correlacionarse entre si.
 *
 * @property int $id
 * @property string $fuente
 * @property string|null $subfuente
 * @property CarbonInterface $marca_tiempo
 * @property string $direccion_ip
 * @property string|null $pais
 * @property string|null $metodo
 * @property string|null $ruta
 * @property int|null $codigo_respuesta
 * @property string|null $identificador_transaccion
 * @property array<int, int|string>|null $identificadores_regla
 * @property int $puntuacion_anomalia
 * @property string $severidad
 * @property array<int, string>|null $etiquetas
 * @property string|null $mensaje
 * @property string|null $carga_util
 * @property bool $fue_bloqueado
 * @property int|null $usuario_id
 * @property string|null $agente_usuario
 * @property bool $es_demostracion
 * @property string|null $huella
 */
class EventoSeguridad extends Model
{
    protected $table = 'eventos_seguridad';

    public const FUENTE_WAF = 'waf';

    public const FUENTE_APLICACION = 'aplicacion';

    public const FUENTE_SISTEMA = 'sistema';

    public const SEVERIDAD_CRITICA = 'critica';

    public const SEVERIDAD_ALTA = 'alta';

    public const SEVERIDAD_MEDIA = 'media';

    public const SEVERIDAD_BAJA = 'baja';

    public const SEVERIDAD_INFORMATIVA = 'informativa';

    /**
     * Orden de gravedad de mayor a menor. Se usa para ordenar y para comparar severidades
     * sin repartir el criterio por media aplicacion.
     *
     * @var array<int, string>
     */
    public const ESCALA_SEVERIDAD = [
        self::SEVERIDAD_CRITICA,
        self::SEVERIDAD_ALTA,
        self::SEVERIDAD_MEDIA,
        self::SEVERIDAD_BAJA,
        self::SEVERIDAD_INFORMATIVA,
    ];

    protected $fillable = [
        'fuente',
        'subfuente',
        'marca_tiempo',
        'direccion_ip',
        'pais',
        'metodo',
        'ruta',
        'codigo_respuesta',
        'identificador_transaccion',
        'identificadores_regla',
        'puntuacion_anomalia',
        'severidad',
        'etiquetas',
        'mensaje',
        'carga_util',
        'fue_bloqueado',
        'usuario_id',
        'agente_usuario',
        'es_demostracion',
        'huella',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'marca_tiempo' => 'datetime',
            'identificadores_regla' => 'array',
            'etiquetas' => 'array',
            'fue_bloqueado' => 'boolean',
            'es_demostracion' => 'boolean',
            'puntuacion_anomalia' => 'integer',
            'codigo_respuesta' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /**
     * @return BelongsToMany<AlertaSeguridad, $this>
     */
    public function alertas(): BelongsToMany
    {
        return $this->belongsToMany(
            AlertaSeguridad::class,
            'alerta_evento',
            'evento_seguridad_id',
            'alerta_seguridad_id',
        );
    }

    /**
     * @param  Builder<$this>  $consulta
     * @return Builder<$this>
     */
    public function scopeDesde(Builder $consulta, CarbonInterface $momento): Builder
    {
        return $consulta->where('marca_tiempo', '>=', $momento);
    }

    /**
     * @param  Builder<$this>  $consulta
     * @return Builder<$this>
     */
    public function scopeBloqueados(Builder $consulta): Builder
    {
        return $consulta->where('fue_bloqueado', true);
    }

    /**
     * @param  Builder<$this>  $consulta
     * @return Builder<$this>
     */
    public function scopeDeFuente(Builder $consulta, string $fuente): Builder
    {
        return $consulta->where('fuente', $fuente);
    }

    /**
     * Busca eventos cuyo arreglo de identificadores de regla contenga alguno de los dados.
     * MariaDB resuelve JSON_CONTAINS sobre la columna JSON sin necesidad de tabla aparte.
     *
     * Se pregunta por el identificador como texto y como numero porque JSON_CONTAINS
     * distingue los dos tipos: ["15021"] no contiene 15021 ni al reves. La ingesta guarda
     * siempre texto, pero un evento insertado a mano con numeros dejaria la regla del
     * rastreador falsificado callada sin que nada avisara del hueco.
     *
     * @param  Builder<$this>  $consulta
     * @param  array<int, int|string>  $identificadores
     * @return Builder<$this>
     */
    public function scopeConRegla(Builder $consulta, array $identificadores): Builder
    {
        return $consulta->where(function (Builder $interna) use ($identificadores): void {
            foreach ($identificadores as $identificador) {
                $interna->orWhereJsonContains('identificadores_regla', (string) $identificador);

                if (is_numeric($identificador)) {
                    $interna->orWhereJsonContains('identificadores_regla', (int) $identificador);
                }
            }
        });
    }

    /**
     * Etiqueta corta de la regla que mejor explica el evento, para la tabla del panel.
     */
    public function reglaPrincipal(): ?string
    {
        $reglas = $this->identificadores_regla ?? [];

        if ($reglas === []) {
            return null;
        }

        // Las reglas propias del proyecto (15000-15099) explican el ataque mucho mejor que
        // las genericas del Core Rule Set, asi que se muestran con preferencia.
        foreach ($reglas as $regla) {
            $numero = (int) $regla;

            if ($numero >= 15000 && $numero <= 15099) {
                return (string) $regla;
            }
        }

        return (string) $reglas[0];
    }

    public function severidadEsAlMenos(string $minima): bool
    {
        $posicionEvento = array_search($this->severidad, self::ESCALA_SEVERIDAD, true);
        $posicionMinima = array_search($minima, self::ESCALA_SEVERIDAD, true);

        if ($posicionEvento === false || $posicionMinima === false) {
            return false;
        }

        return $posicionEvento <= $posicionMinima;
    }
}
