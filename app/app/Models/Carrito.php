<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/**
 * Carrito de compras. Pertenece a un usuario autenticado o, si aun no inicia sesion,
 * a un testigo aleatorio guardado en la sesion del navegador.
 *
 * @property int $id
 * @property int|null $usuario_id
 * @property string|null $testigo_sesion
 * @property-read Collection<int, LineaCarrito> $lineas
 */
class Carrito extends Model
{
    /**
     * Clave de sesion donde vive el testigo del carrito anonimo.
     */
    public const CLAVE_SESION = 'carrito_testigo';

    /**
     * Politica de envio de la tienda. Vive en el modelo y no repartida entre pantallas
     * para que el carrito y la pantalla de pago no puedan mostrar totales distintos.
     */
    public const ENVIO_ESTANDAR = 35.00;

    public const ENVIO_GRATIS_DESDE = 500.00;

    protected $table = 'carritos';

    protected $fillable = [
        'usuario_id',
        'testigo_sesion',
    ];

    /** @return BelongsTo<User, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /** @return HasMany<LineaCarrito, $this> */
    public function lineas(): HasMany
    {
        return $this->hasMany(LineaCarrito::class, 'carrito_id');
    }

    /**
     * Devuelve el carrito del visitante actual, creandolo solo cuando hace falta.
     *
     * Se resuelve por usuario autenticado antes que por testigo de sesion: asi, si alguien
     * copia el testigo de otro navegador, un usuario con sesion iniciada sigue viendo su
     * propio carrito y no el ajeno.
     */
    public static function actual(bool $crear = false): ?self
    {
        if (Auth::check()) {
            $carrito = self::firstWhere('usuario_id', Auth::id());

            if ($carrito === null && $crear) {
                $carrito = self::create([
                    'usuario_id' => Auth::id(),
                    'testigo_sesion' => null,
                ]);
            }

            return $carrito;
        }

        $testigo = Session::get(self::CLAVE_SESION);

        $carrito = is_string($testigo) && $testigo !== ''
            ? self::firstWhere('testigo_sesion', $testigo)
            : null;

        if ($carrito === null && $crear) {
            $testigo = (string) Str::uuid();
            Session::put(self::CLAVE_SESION, $testigo);

            $carrito = self::create([
                'usuario_id' => null,
                'testigo_sesion' => $testigo,
            ]);
        }

        return $carrito;
    }

    /**
     * Cantidad total de articulos, para el indicador del encabezado.
     */
    public static function articulosDelVisitante(): int
    {
        $carrito = self::actual();

        return $carrito === null ? 0 : (int) $carrito->lineas()->sum('cantidad');
    }

    public function subtotal(): float
    {
        return (float) $this->lineas->sum(fn (LineaCarrito $linea): float => $linea->subtotal());
    }

    public function totalArticulos(): int
    {
        return (int) $this->lineas->sum('cantidad');
    }

    public function costoEnvio(): float
    {
        $subtotal = $this->subtotal();

        if ($subtotal <= 0 || $subtotal >= self::ENVIO_GRATIS_DESDE) {
            return 0.0;
        }

        return self::ENVIO_ESTANDAR;
    }

    public function total(): float
    {
        return round($this->subtotal() + $this->costoEnvio(), 2);
    }

    public function estaVacio(): bool
    {
        return $this->lineas->isEmpty();
    }

    /**
     * Cuanto falta para alcanzar el envio gratuito. Cero cuando ya se alcanzo.
     */
    public function faltaParaEnvioGratis(): float
    {
        return max(0.0, round(self::ENVIO_GRATIS_DESDE - $this->subtotal(), 2));
    }
}
