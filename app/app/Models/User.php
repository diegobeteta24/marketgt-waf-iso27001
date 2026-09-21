<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $direccion
 * @property string|null $telefono
 * @property string|null $documento_identidad
 * @property Carbon|null $ultimo_acceso_en
 * @property string|null $ultima_ip_acceso
 * @property bool $activo
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Rol> $roles
 */
#[Fillable(['name', 'email', 'password', 'direccion', 'telefono', 'documento_identidad', 'activo'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'direccion', 'telefono', 'documento_identidad'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'ultimo_acceso_en' => 'datetime',
            'activo' => 'boolean',

            // Cifrado en reposo a nivel de aplicación: Laravel cifra estos tres
            // campos con la APP_KEY antes de escribirlos y los descifra al leerlos,
            // de modo que en la tabla users solo hay texto ilegible.
            //
            // Esto NO sustituye al cifrado del volumen (LUKS en el disco del
            // servidor), y por eso se hacen los dos. El cifrado del volumen protege
            // contra el robo del disco o de una copia de seguridad en frío: mientras
            // el servidor está apagado, todo es ilegible; en cuanto arranca y monta
            // el volumen, cualquiera que consiga leer la base de datos lo ve todo en
            // claro. El cifrado de aplicación cubre justo ese hueco —la máquina
            // encendida— porque la clave vive en la aplicación y no en la base:
            // una inyección SQL, un volcado con mysqldump o una credencial de solo
            // lectura filtrada entregan el sobre cifrado, no la dirección del
            // cliente. Y al revés: si alguien se lleva el disco, la APP_KEY está en
            // ese mismo disco, así que sin LUKS el cifrado de aplicación tampoco
            // bastaría. Cada capa cubre el fallo de la otra.
            'direccion' => 'encrypted',
            'telefono' => 'encrypted',
            'documento_identidad' => 'encrypted',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /**
     * Roles concedidos a la cuenta.
     *
     * @return BelongsToMany<Rol, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Rol::class, 'rol_usuario', 'usuario_id', 'rol_id')
            ->withPivot(['asignado_por', 'asignado_en'])
            ->withTimestamps();
    }

    /**
     * Asientos de auditoría generados por la cuenta.
     *
     * @return HasMany<RegistroAuditoria, $this>
     */
    public function registrosAuditoria(): HasMany
    {
        return $this->hasMany(RegistroAuditoria::class, 'usuario_id');
    }

    /**
     * ¿Tiene la cuenta alguno de los roles indicados?
     *
     * Se carga la relación una sola vez por petición y se compara en memoria. La
     * alternativa —una consulta por comprobación— pondría una consulta en cada
     * enlace del menú que pregunta por el rol, y el menú se dibuja en cada página.
     */
    public function tieneRol(string ...$nombres): bool
    {
        if ($nombres === []) {
            return false;
        }

        $buscados = array_map(
            static fn (string $nombre): string => Rol::canonico($nombre),
            $nombres,
        );

        $propios = $this->relationLoaded('roles')
            ? $this->roles
            : $this->roles()->get();

        foreach ($propios as $rol) {
            if (in_array(Rol::canonico($rol->nombre), $buscados, true)) {
                return true;
            }
        }

        return false;
    }

    public function esAdministrador(): bool
    {
        return $this->tieneRol(Rol::ADMINISTRADOR);
    }

    /**
     * El administrador también audita. Al revés no: el auditor lee el SIEM pero no
     * toca el catálogo ni los pedidos. Es la separación de funciones que pide la
     * auditoría, y está escrita aquí y no repartida por las vistas.
     */
    public function esAuditor(): bool
    {
        return $this->tieneRol(Rol::AUDITOR, Rol::ADMINISTRADOR);
    }

    public function esCliente(): bool
    {
        return $this->tieneRol(Rol::CLIENTE);
    }

    /**
     * Concede un rol sin duplicar la concesión existente.
     */
    public function asignarRol(string $nombre, ?int $asignadoPor = null): void
    {
        $rol = Rol::query()->where('nombre', Rol::canonico($nombre))->first();

        if (! $rol instanceof Rol) {
            return;
        }

        $this->roles()->syncWithoutDetaching([
            $rol->id => [
                'asignado_por' => $asignadoPor,
                'asignado_en' => now(),
            ],
        ]);

        $this->unsetRelation('roles');
    }

    public function revocarRol(string $nombre): void
    {
        $rol = Rol::query()->where('nombre', Rol::canonico($nombre))->first();

        if ($rol instanceof Rol) {
            $this->roles()->detach($rol->id);
            $this->unsetRelation('roles');
        }
    }

    /**
     * Etiquetas de los roles, para pintarlas en la interfaz.
     *
     * @return array<int, string>
     */
    public function etiquetasRoles(): array
    {
        return $this->roles->pluck('etiqueta')->all();
    }
}
