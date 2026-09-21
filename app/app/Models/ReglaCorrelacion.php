<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Regla de correlacion. Umbral y ventana viven en la base y no en el codigo para que el
 * analista pueda afinar la sensibilidad del motor sin un despliegue.
 *
 * @property int $id
 * @property string $clave
 * @property string $nombre
 * @property string $descripcion
 * @property string $severidad
 * @property int $umbral
 * @property int $ventana_minutos
 * @property string $accion_recomendada
 * @property array<string, mixed>|null $parametros
 * @property bool $activa
 * @property string|null $justificacion_umbral
 */
class ReglaCorrelacion extends Model
{
    protected $table = 'reglas_correlacion';

    public const WAF_403_REPETIDO = 'waf_403_repetido';

    public const ESCALADA_ANOMALIA = 'escalada_anomalia';

    public const FUERZA_BRUTA_SESION = 'fuerza_bruta_sesion';

    public const SEGUNDO_FACTOR_FALLIDO = 'segundo_factor_fallido';

    public const RASTREADOR_FALSIFICADO = 'rastreador_falsificado';

    public const EXTRACCION_MASIVA = 'extraccion_masiva';

    public const EVENTO_CRITICO_UNICO = 'evento_critico_unico';

    protected $fillable = [
        'clave',
        'nombre',
        'descripcion',
        'severidad',
        'umbral',
        'ventana_minutos',
        'accion_recomendada',
        'parametros',
        'activa',
        'justificacion_umbral',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parametros' => 'array',
            'activa' => 'boolean',
            'umbral' => 'integer',
            'ventana_minutos' => 'integer',
        ];
    }

    /**
     * @return HasMany<AlertaSeguridad, $this>
     */
    public function alertas(): HasMany
    {
        return $this->hasMany(AlertaSeguridad::class, 'regla_correlacion_id');
    }

    /**
     * @param  Builder<$this>  $consulta
     * @return Builder<$this>
     */
    public function scopeActivas(Builder $consulta): Builder
    {
        return $consulta->where('activa', true);
    }

    public function parametro(string $nombre, mixed $porDefecto = null): mixed
    {
        return data_get($this->parametros ?? [], $nombre, $porDefecto);
    }
}
