<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Estado de un parche del sistema operativo del anfitrion, tal como lo observo el
 * recolector que corre fuera del contenedor.
 *
 * Cada fila es un hecho fechado y con procedencia, no una opinion sobre el parcheo: dice
 * que paquete, que version, cuando se publico la correccion si se pudo leer, cuando se
 * aplico, y de que archivo salio cada una de esas dos fechas.
 *
 * @property int $id
 * @property string $anfitrion
 * @property string $paquete
 * @property string|null $arquitectura
 * @property string $version
 * @property string|null $version_anterior
 * @property string $estado
 * @property bool|null $es_seguridad
 * @property string|null $origen_archivo
 * @property CarbonInterface|null $publicado_en
 * @property string|null $fuente_publicacion
 * @property string|null $nota_publicacion
 * @property array<int, string>|null $identificadores_cve
 * @property CarbonInterface|null $aplicado_en
 * @property string|null $fuente_aplicacion
 * @property string|null $aplicado_por
 * @property CarbonInterface|null $visto_pendiente_desde
 * @property float|null $desfase_horas
 * @property CarbonInterface $recolectado_en
 * @property string $recolectado_por
 * @property string|null $version_recolector
 * @property string $huella
 */
class EstadoParche extends Model
{
    protected $table = 'estados_parche';

    public const ESTADO_PENDIENTE = 'pendiente';

    public const ESTADO_APLICADO = 'aplicado';

    /**
     * El parche dejo de figurar pendiente sin constar aplicado: retenido, reemplazado o
     * eliminado. Existe para que una fila pendiente no se quede vencida para siempre
     * hundiendo la metrica por un paquete que ya no esta.
     */
    public const ESTADO_NO_APLICABLE = 'no_aplicable';

    /**
     * @var array<string, string>
     */
    public const ETIQUETAS_ESTADO = [
        self::ESTADO_PENDIENTE => 'Pendiente',
        self::ESTADO_APLICADO => 'Aplicado',
        self::ESTADO_NO_APLICABLE => 'Ya no aplica',
    ];

    protected $fillable = [
        'anfitrion',
        'paquete',
        'arquitectura',
        'version',
        'version_anterior',
        'estado',
        'es_seguridad',
        'origen_archivo',
        'publicado_en',
        'fuente_publicacion',
        'nota_publicacion',
        'identificadores_cve',
        'aplicado_en',
        'fuente_aplicacion',
        'aplicado_por',
        'visto_pendiente_desde',
        'desfase_horas',
        'recolectado_en',
        'recolectado_por',
        'version_recolector',
        'huella',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'publicado_en' => 'datetime',
            'aplicado_en' => 'datetime',
            'visto_pendiente_desde' => 'datetime',
            'recolectado_en' => 'datetime',
            'identificadores_cve' => 'array',
            'es_seguridad' => 'boolean',
            'desfase_horas' => 'float',
        ];
    }

    /**
     * Solo lo clasificado como seguridad con un hecho detras: el archivo "-security", un
     * CVE citado en el registro de cambios o la marca SECURITY UPDATE. Lo no clasificado
     * (es_seguridad nulo) queda fuera a proposito: contarlo en un sentido o en el otro
     * seria decidir por el dato que falta.
     *
     * @param  Builder<$this>  $consulta
     * @return Builder<$this>
     */
    public function scopeDeSeguridad(Builder $consulta): Builder
    {
        return $consulta->where('es_seguridad', true);
    }

    /**
     * @param  Builder<$this>  $consulta
     * @return Builder<$this>
     */
    public function scopeSinClasificar(Builder $consulta): Builder
    {
        return $consulta->whereNull('es_seguridad');
    }

    /**
     * @param  Builder<$this>  $consulta
     * @return Builder<$this>
     */
    public function scopePendientes(Builder $consulta): Builder
    {
        return $consulta->where('estado', self::ESTADO_PENDIENTE);
    }

    /**
     * @param  Builder<$this>  $consulta
     * @return Builder<$this>
     */
    public function scopeAplicados(Builder $consulta): Builder
    {
        return $consulta->where('estado', self::ESTADO_APLICADO);
    }

    /**
     * Parches aplicados sobre los que el plazo SE PUEDE medir: hacen falta las dos fechas
     * y que el desfase tenga sentido. Es el unico conjunto que entra en el porcentaje, y
     * este ambito define exactamente el mismo conjunto que usa AnalizadorParches, para que
     * una pantalla que liste "los que cuentan" no muestre una cifra distinta de la metrica.
     *
     * Un desfase negativo significa aplicado antes de publicado, que no es un plazo
     * cumplido sino un reloj mal puesto, y queda fuera.
     *
     * @param  Builder<$this>  $consulta
     * @return Builder<$this>
     */
    public function scopeConPlazoMedible(Builder $consulta): Builder
    {
        return $consulta->where('estado', self::ESTADO_APLICADO)
            ->whereNotNull('publicado_en')
            ->whereNotNull('aplicado_en')
            ->whereNotNull('desfase_horas')
            ->where('desfase_horas', '>=', 0);
    }

    /**
     * Aplicados a los que les falta la fecha de publicacion. Cuentan en el total y no en
     * el plazo, y la metrica esta obligada a decir cuantos son.
     *
     * @param  Builder<$this>  $consulta
     * @return Builder<$this>
     */
    public function scopeSinFechaPublicacion(Builder $consulta): Builder
    {
        return $consulta->where('estado', self::ESTADO_APLICADO)->whereNull('publicado_en');
    }

    public function dentroDelPlazo(int $horas): ?bool
    {
        if ($this->desfase_horas === null) {
            return null;
        }

        return $this->desfase_horas <= $horas;
    }

    /**
     * Horas que el parche lleva esperando desde que se vio pendiente por primera vez.
     *
     * Es una COTA INFERIOR del retraso, nunca el retraso real: el parche pudo estar
     * publicado mucho antes de que el recolector mirara por primera vez. Quien muestre
     * esta cifra tiene que decirlo asi.
     */
    public function horasEsperando(?CarbonInterface $ahora = null): ?float
    {
        if ($this->estado !== self::ESTADO_PENDIENTE || $this->visto_pendiente_desde === null) {
            return null;
        }

        $ahora ??= now();

        return round($this->visto_pendiente_desde->diffInSeconds($ahora) / 3600, 2);
    }

    public function etiquetaEstado(): string
    {
        return self::ETIQUETAS_ESTADO[$this->estado] ?? $this->estado;
    }

    /**
     * Nombre completo del paquete tal como lo escribiria dpkg, para poder buscarlo en el
     * servidor a partir de lo que muestra el panel.
     */
    public function nombreCompleto(): string
    {
        return $this->arquitectura === null
            ? $this->paquete
            : $this->paquete.':'.$this->arquitectura;
    }
}
