<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Rol del control de acceso de MarketGT.
 *
 * El control de acceso está escrito a mano y no con spatie/laravel-permission a
 * propósito. Ese paquete resuelve permisos granulares, jerarquías y equipos; aquí
 * hay tres roles fijos que no crecen, y adoptarlo significaría añadir una
 * dependencia con su propio ciclo de versiones, cinco tablas y una caché de
 * permisos que hay que invalidar a mano. Para tres roles, el paquete es más
 * superficie de ataque y más mantenimiento del que ahorra; además, en la defensa
 * del sábado el profesor puede leer las cuarenta líneas que deciden quién entra
 * al SIEM, en vez de auditar código de terceros.
 *
 * @property int $id
 * @property string $nombre
 * @property string $etiqueta
 * @property string|null $descripcion
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Rol extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    public const ADMINISTRADOR = 'administrador';

    public const AUDITOR = 'auditor';

    public const CLIENTE = 'cliente';

    protected $table = 'roles';

    protected $fillable = [
        'nombre',
        'etiqueta',
        'descripcion',
    ];

    /**
     * @return BelongsToMany<User, $this>
     */
    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'rol_usuario', 'rol_id', 'usuario_id')
            ->withPivot(['asignado_por', 'asignado_en'])
            ->withTimestamps();
    }

    /**
     * Traduce un nombre escrito por otro componente al nombre canónico.
     *
     * routes/siem.php pide "rol:admin,auditor"; la tabla guarda "administrador".
     * Sin esta traducción, ese middleware negaría el paso al administrador y el
     * fallo se vería como un 403 inexplicable en plena demostración.
     */
    public static function canonico(string $nombre): string
    {
        $nombre = mb_strtolower(trim($nombre));

        /** @var array<string, string> $alias */
        $alias = config('seguridad.alias_roles', []);

        return $alias[$nombre] ?? $nombre;
    }
}
