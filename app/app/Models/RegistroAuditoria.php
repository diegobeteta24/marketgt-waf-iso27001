<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Asiento de auditoría de una acción sensible.
 *
 * La bitácora JSON (storage/logs/seguridad.log) y esta tabla no se pisan: el
 * archivo es el flujo que consume el SIEM y se rota; la tabla es el registro
 * consultable que sobrevive a la rotación y se puede cruzar con los pedidos y los
 * usuarios en una sola consulta. Un auditor pide siempre las dos cosas, y tener
 * solo el archivo obliga a reprocesarlo para contestar cualquier pregunta.
 *
 * @property int $id
 * @property string $accion
 * @property string|null $tipo_recurso
 * @property string|null $identificador_recurso
 * @property int|null $usuario_id
 * @property string|null $correo
 * @property string|null $direccion_ip
 * @property string|null $agente_usuario
 * @property string|null $metodo
 * @property string|null $ruta
 * @property int|null $codigo_respuesta
 * @property string $resultado
 * @property array<string, mixed>|null $detalle
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RegistroAuditoria extends Model
{
    public const EXITO = 'exito';

    public const FALLO = 'fallo';

    public const DENEGADO = 'denegado';

    protected $table = 'registros_auditoria';

    protected $fillable = [
        'accion',
        'tipo_recurso',
        'identificador_recurso',
        'usuario_id',
        'correo',
        'direccion_ip',
        'agente_usuario',
        'metodo',
        'ruta',
        'codigo_respuesta',
        'resultado',
        'detalle',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'detalle' => 'array',
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
     * @param  Builder<RegistroAuditoria>  $consulta
     * @return Builder<RegistroAuditoria>
     */
    public function scopeDeUsuario(Builder $consulta, int $usuarioId): Builder
    {
        return $consulta->where('usuario_id', $usuarioId);
    }

    /**
     * @param  Builder<RegistroAuditoria>  $consulta
     * @return Builder<RegistroAuditoria>
     */
    public function scopeDesde(Builder $consulta, Carbon $momento): Builder
    {
        return $consulta->where('created_at', '>=', $momento);
    }

    /**
     * Acciones que no salieron bien. Es la primera consulta de cualquier revisión:
     * el intento denegado dice más del atacante que el permitido.
     *
     * @param  Builder<RegistroAuditoria>  $consulta
     * @return Builder<RegistroAuditoria>
     */
    public function scopeFallidos(Builder $consulta): Builder
    {
        return $consulta->whereIn('resultado', [self::FALLO, self::DENEGADO]);
    }
}
