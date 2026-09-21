<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Pedido confirmado en la tienda.
 *
 * Sobre el pago: este modelo guarda un token y los cuatro ultimos digitos, nunca el
 * numero de tarjeta. Ver el comentario de tokenizacion en la migracion 000105.
 *
 * @property int $id
 * @property string $numero
 * @property int|null $usuario_id
 * @property string $estado
 * @property string $total
 * @property-read \Illuminate\Database\Eloquent\Collection<int, LineaPedido> $lineas
 */
class Pedido extends Model
{
    /**
     * Estados por los que pasa un pedido. Se declara aqui y no suelto en las vistas para
     * que el enum de la base y la interfaz no se separen con el tiempo.
     */
    public const ESTADOS = [
        'pendiente' => 'Pendiente',
        'pagado' => 'Pagado',
        'enviado' => 'Enviado',
        'entregado' => 'Entregado',
        'cancelado' => 'Cancelado',
    ];

    /**
     * Clave de sesion con los numeros de pedido hechos desde este navegador. Es lo unico
     * que autoriza a abrir un comprobante sin haber iniciado sesion.
     */
    public const CLAVE_SESION_RECIENTES = 'pedidos_recientes';

    protected $table = 'pedidos';

    protected $fillable = [
        'numero',
        'usuario_id',
        'nombre_cliente',
        'correo_cliente',
        'telefono_cliente',
        'direccion_envio',
        'municipio_envio',
        'departamento_envio',
        'referencia_envio',
        'subtotal',
        'envio',
        'total',
        'estado',
        'ultimos_cuatro',
        'marca_tarjeta',
        'token_pago',
        'pagado_en',
    ];

    /**
     * El token de pago no se expone en respuestas JSON: identifica el medio de pago ante la
     * pasarela y no aporta nada al cliente.
     */
    protected $hidden = [
        'token_pago',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'envio' => 'decimal:2',
            'total' => 'decimal:2',
            'pagado_en' => 'datetime',
        ];
    }

    /**
     * El comprobante se consulta por numero publico, no por id autoincremental.
     */
    public function getRouteKeyName(): string
    {
        return 'numero';
    }

    /** @return BelongsTo<User, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /** @return HasMany<LineaPedido, $this> */
    public function lineas(): HasMany
    {
        return $this->hasMany(LineaPedido::class, 'pedido_id');
    }

    public function estadoLegible(): string
    {
        return self::ESTADOS[$this->estado] ?? $this->estado;
    }

    public function totalFormateado(): string
    {
        return Producto::quetzales($this->total);
    }

    public function subtotalFormateado(): string
    {
        return Producto::quetzales($this->subtotal);
    }

    public function envioFormateado(): string
    {
        return Producto::quetzales($this->envio);
    }

    /**
     * Numero publico aleatorio (MG-2026-K7Q3ZA).
     *
     * Deliberadamente NO es correlativo: un numero secuencial permitiria a cualquiera
     * estimar el volumen de ventas y, peor, adivinar el numero del pedido de otro cliente
     * restando uno. Esto es prevencion de enumeracion, parte de la Capa 5.
     */
    public static function generarNumero(): string
    {
        do {
            $numero = 'MG-'.now()->format('Y').'-'.Str::upper(Str::random(6));
        } while (self::where('numero', $numero)->exists());

        return $numero;
    }

    /**
     * Valida un numero de tarjeta con el algoritmo de Luhn (ISO/IEC 7812-1).
     *
     * Atrapa erratas de digitacion antes de enviar nada a la pasarela. No prueba que la
     * tarjeta exista ni que tenga fondos: eso solo lo sabe el emisor.
     */
    public static function superaLuhn(string $numero): bool
    {
        $digitos = preg_replace('/\D/', '', $numero) ?? '';
        $largo = strlen($digitos);

        if ($largo < 13 || $largo > 19) {
            return false;
        }

        $suma = 0;
        $duplicar = false;

        for ($i = $largo - 1; $i >= 0; $i--) {
            $valor = (int) $digitos[$i];

            if ($duplicar) {
                $valor *= 2;

                if ($valor > 9) {
                    $valor -= 9;
                }
            }

            $suma += $valor;
            $duplicar = ! $duplicar;
        }

        return $suma % 10 === 0;
    }

    /**
     * Deduce la marca a partir del prefijo, solo para mostrarla en el comprobante.
     */
    public static function marcaSegunPrefijo(string $numero): string
    {
        $digitos = preg_replace('/\D/', '', $numero) ?? '';

        return match (true) {
            (bool) preg_match('/^4/', $digitos) => 'Visa',
            (bool) preg_match('/^(5[1-5]|2[2-7])/', $digitos) => 'Mastercard',
            (bool) preg_match('/^3[47]/', $digitos) => 'American Express',
            (bool) preg_match('/^6(011|5)/', $digitos) => 'Discover',
            default => 'Desconocida',
        };
    }

    /**
     * Emite el token opaco que sustituye al numero de tarjeta.
     *
     * En produccion este valor lo devolveria la pasarela (Recurrente, Visanet, Stripe...).
     * Aqui se simula con bytes aleatorios criptograficos: el punto pedagogico es que el
     * token no se deriva del numero de tarjeta, asi que nadie puede reconstruir el numero
     * a partir de la base de datos ni por fuerza bruta sobre el token.
     */
    public static function emitirTokenPago(): string
    {
        return 'tok_demo_'.bin2hex(random_bytes(20));
    }
}
