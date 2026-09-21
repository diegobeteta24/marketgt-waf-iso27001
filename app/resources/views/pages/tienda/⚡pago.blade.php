<?php

use App\Models\Carrito;
use App\Models\Pedido;
use App\Models\Producto;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('pages::tienda.layout')]
#[Title('Pago')]
class extends Component
{
    public string $nombre_cliente = '';

    public string $correo_cliente = '';

    public string $telefono_cliente = '';

    public string $direccion_envio = '';

    public string $municipio_envio = '';

    public string $departamento_envio = 'Guatemala';

    public string $referencia_envio = '';

    public string $nombre_tarjeta = '';

    public string $numero_tarjeta = '';

    public string $vencimiento = '';

    public string $cvv = '';

    public function mount(): void
    {
        if (Auth::check()) {
            $this->nombre_cliente = Auth::user()->name;
            $this->correo_cliente = Auth::user()->email;
        }
    }

    #[Computed]
    public function carrito(): ?Carrito
    {
        return Carrito::actual()?->load('lineas.producto');
    }

    /**
     * Los veintidós departamentos de Guatemala. Es una lista cerrada: el valor que llega
     * del formulario se valida contra ella con la regla in, de modo que no puede colarse
     * texto arbitrario en la dirección de envío.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function departamentos(): array
    {
        return [
            'Alta Verapaz', 'Baja Verapaz', 'Chimaltenango', 'Chiquimula', 'El Progreso',
            'Escuintla', 'Guatemala', 'Huehuetenango', 'Izabal', 'Jalapa', 'Jutiapa',
            'Petén', 'Quetzaltenango', 'Quiché', 'Retalhuleu', 'Sacatepéquez', 'San Marcos',
            'Santa Rosa', 'Sololá', 'Suchitepéquez', 'Totonicapán', 'Zacapa',
        ];
    }

    /**
     * Rellena el formulario con datos de fantasía y una tarjeta de prueba pública.
     *
     * El 4111 1111 1111 1111 es el número de ensayo que publican las propias marcas: pasa
     * el algoritmo de Luhn y no pertenece a ninguna cuenta real. Existe para que la
     * demostración del sábado no dependa de que alguien dicte su tarjeta en voz alta.
     */
    public function llenarDatosDePrueba(): void
    {
        $this->nombre_cliente = 'Mariana Xoyón Batz';
        $this->correo_cliente = 'mariana.demo@marketgt.test';
        $this->telefono_cliente = '5512 8844';
        $this->direccion_envio = '4a. calle 12-45, zona 10, Edificio Los Almendros, apto. 302';
        $this->municipio_envio = 'Ciudad de Guatemala';
        $this->departamento_envio = 'Guatemala';
        $this->referencia_envio = 'Portón negro frente a la tienda de la esquina';
        $this->nombre_tarjeta = 'MARIANA XOYON BATZ';
        $this->numero_tarjeta = '4111 1111 1111 1111';
        $this->vencimiento = '12/29';
        $this->cvv = '123';
    }

    /**
     * @return array<string, mixed>
     */
    protected function reglas(): array
    {
        return [
            'nombre_cliente' => ['required', 'string', 'min:5', 'max:120'],
            'correo_cliente' => ['required', 'email:rfc', 'max:160'],
            'telefono_cliente' => ['required', 'string', 'max:30', 'regex:/^[0-9 ()+-]{8,30}$/'],
            'direccion_envio' => ['required', 'string', 'min:10', 'max:255'],
            'municipio_envio' => ['required', 'string', 'min:3', 'max:120'],
            'departamento_envio' => ['required', 'string', 'in:'.implode(',', $this->departamentos())],
            'referencia_envio' => ['nullable', 'string', 'max:255'],

            'nombre_tarjeta' => ['required', 'string', 'min:5', 'max:120'],
            'numero_tarjeta' => [
                'required',
                'string',
                // Diecinueve digitos mas separadores es el maximo que admite la norma ISO/IEC
                // 7812-1; acotar el campo evita que alguien empuje megabytes al validador.
                'max:32',
                function (string $atributo, mixed $valor, \Closure $fallar): void {
                    if (! Pedido::superaLuhn((string) $valor)) {
                        $fallar('El número de tarjeta no supera la verificación de Luhn. Revisá los dígitos.');
                    }
                },
            ],
            'vencimiento' => [
                'required',
                'regex:/^(0[1-9]|1[0-2])\/[0-9]{2}$/',
                function (string $atributo, mixed $valor, \Closure $fallar): void {
                    [$mes, $anio] = array_pad(explode('/', (string) $valor), 2, '');

                    if (! ctype_digit($mes) || ! ctype_digit($anio)) {
                        return;
                    }

                    $vence = now()->setDate(2000 + (int) $anio, (int) $mes, 1)->endOfMonth();

                    if ($vence->isPast()) {
                        $fallar('La tarjeta ya venció.');
                    }
                },
            ],
            'cvv' => ['required', 'digits_between:3,4'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function mensajes(): array
    {
        return [
            'required' => 'Este campo es obligatorio.',
            'email' => 'Escribí un correo electrónico válido.',
            'min' => 'Faltan caracteres en este campo.',
            'max' => 'Este campo es demasiado largo.',
            'in' => 'Seleccioná un departamento de la lista.',
            'telefono_cliente.regex' => 'El teléfono solo admite dígitos, espacios y los signos + ( ) -',
            'vencimiento.regex' => 'Usá el formato MM/AA, por ejemplo 12/29.',
            'cvv.digits_between' => 'El código de seguridad tiene 3 o 4 dígitos.',
        ];
    }

    /**
     * Confirma el pedido.
     *
     * TOKENIZACIÓN DEL MEDIO DE PAGO (Capa 6, requisito PCI DSS del proyecto):
     * el número de tarjeta se valida en memoria, se le extraen los cuatro últimos dígitos
     * y la marca, se sustituye por un token opaco y se borra de las propiedades del
     * componente antes de tocar la base de datos. El número completo nunca se escribe en
     * la base, ni en la sesión, ni en la bitácora. El CVV no se conserva jamás, ni
     * siquiera cifrado: guardarlo está prohibido incluso para comercios certificados.
     */
    public function confirmar(): void
    {
        $datos = $this->validate($this->reglas(), $this->mensajes());

        $carrito = Carrito::actual()?->load('lineas.producto');

        if ($carrito === null || $carrito->estaVacio()) {
            Flux::toast(variant: 'danger', text: 'Tu carrito está vacío.');
            $this->redirectRoute('tienda.carrito', navigate: true);

            return;
        }

        $digitos = preg_replace('/\D/', '', $this->numero_tarjeta) ?? '';
        $ultimosCuatro = substr($digitos, -4);
        $marcaTarjeta = Pedido::marcaSegunPrefijo($digitos);
        $tokenPago = Pedido::emitirTokenPago();

        // A partir de aquí el número y el código de seguridad dejan de existir en el
        // proceso: se limpian del componente para que no vuelvan al navegador en la
        // siguiente respuesta de Livewire ni queden en un volcado de memoria del worker.
        $this->reset('numero_tarjeta', 'cvv', 'vencimiento');
        unset($digitos);

        try {
            $pedido = DB::transaction(function () use ($carrito, $datos, $ultimosCuatro, $marcaTarjeta, $tokenPago): Pedido {
                $subtotal = 0.0;
                $lineasPedido = [];

                foreach ($carrito->lineas as $linea) {
                    // lockForUpdate evita que dos compras simultáneas del último artículo
                    // dejen las existencias en negativo (condición de carrera).
                    $producto = Producto::query()->whereKey($linea->producto_id)->lockForUpdate()->first();

                    if ($producto === null || ! $producto->activo || $producto->existencias < $linea->cantidad) {
                        throw new \RuntimeException(
                            'Ya no hay existencias suficientes de "'.($producto?->nombre ?? 'uno de los productos').'".'
                        );
                    }

                    // El precio se relee del catálogo dentro de la transacción y NO se toma
                    // del carrito ni del formulario: así ninguna manipulación del lado del
                    // cliente puede cambiar lo que se cobra.
                    $importe = round((float) $producto->precio * $linea->cantidad, 2);
                    $subtotal += $importe;

                    $lineasPedido[] = [
                        'producto_id' => $producto->id,
                        'nombre_producto' => $producto->nombre,
                        'precio_unitario' => $producto->precio,
                        'cantidad' => $linea->cantidad,
                        'subtotal' => $importe,
                    ];

                    $producto->decrement('existencias', $linea->cantidad);
                }

                $subtotal = round($subtotal, 2);
                $envio = $subtotal >= Carrito::ENVIO_GRATIS_DESDE ? 0.0 : Carrito::ENVIO_ESTANDAR;

                $pedido = Pedido::create([
                    'numero' => Pedido::generarNumero(),
                    'usuario_id' => Auth::id(),
                    'nombre_cliente' => $datos['nombre_cliente'],
                    'correo_cliente' => $datos['correo_cliente'],
                    'telefono_cliente' => $datos['telefono_cliente'],
                    'direccion_envio' => $datos['direccion_envio'],
                    'municipio_envio' => $datos['municipio_envio'],
                    'departamento_envio' => $datos['departamento_envio'],
                    'referencia_envio' => $datos['referencia_envio'] ?: null,
                    'subtotal' => $subtotal,
                    'envio' => $envio,
                    'total' => round($subtotal + $envio, 2),
                    'estado' => 'pagado',
                    'ultimos_cuatro' => $ultimosCuatro,
                    'marca_tarjeta' => $marcaTarjeta,
                    'token_pago' => $tokenPago,
                    'pagado_en' => now(),
                ]);

                $pedido->lineas()->createMany($lineasPedido);

                $carrito->lineas()->delete();

                return $pedido;
            });
        } catch (\RuntimeException $error) {
            Flux::toast(variant: 'danger', text: $error->getMessage());
            $this->redirectRoute('tienda.carrito', navigate: true);

            return;
        }

        // El comprobante es una URL con número aleatorio; esta lista de sesión es lo que
        // permite abrirlo sin haber iniciado sesión, y solo desde el navegador que compró.
        $recientes = Session::get(Pedido::CLAVE_SESION_RECIENTES, []);
        $recientes[] = $pedido->numero;
        Session::put(Pedido::CLAVE_SESION_RECIENTES, array_slice(array_unique($recientes), -20));

        $this->dispatch('carrito-actualizado');

        $this->redirectRoute('tienda.comprobante', ['numero' => $pedido->numero], navigate: true);
    }
}; ?>

<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Finalizar compra</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
            Datos de envío y de pago. Todos los campos marcados son obligatorios.
        </p>
    </div>

    <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-500/30 dark:bg-amber-500/10">
        <div class="flex gap-3">
            <flux:icon.exclamation-triangle class="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400" />
            <div class="text-sm text-amber-900 dark:text-amber-200">
                <p class="font-semibold">Pago simulado &mdash; entorno de demostración académica</p>
                <p class="mt-1 leading-relaxed">
                    Esta pasarela no cobra nada y no se conecta con ningún banco. El número de tarjeta se
                    verifica con el algoritmo de Luhn en memoria y se descarta de inmediato: la base de datos
                    solo guarda los últimos cuatro dígitos, la marca y un token opaco. Nunca ingresés una
                    tarjeta real.
                </p>
                <div class="mt-3">
                    <flux:button size="sm" variant="filled" icon="beaker" wire:click="llenarDatosDePrueba">
                        Llenar con datos de prueba
                    </flux:button>
                </div>
            </div>
        </div>
    </div>

    @php($carrito = $this->carrito)

    @if ($carrito === null || $carrito->estaVacio())
        <section class="rounded-2xl border border-dashed border-zinc-300 bg-white px-6 py-16 text-center dark:border-zinc-700 dark:bg-zinc-800">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">No hay nada que pagar</h2>
            <p class="mx-auto mt-1 max-w-sm text-sm text-zinc-500 dark:text-zinc-400">
                Tu carrito está vacío. Agregá al menos un producto para continuar.
            </p>
            <div class="mt-5">
                <flux:button variant="primary" icon="squares-2x2" :href="route('tienda.catalogo')" wire:navigate>
                    Ir al catálogo
                </flux:button>
            </div>
        </section>
    @else
        <form wire:submit="confirmar" class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <section class="space-y-4 rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                    <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Datos de envío</h2>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model="nombre_cliente" label="Nombre completo" autocomplete="name" />
                        <flux:input wire:model="correo_cliente" label="Correo electrónico" type="email" autocomplete="email" />
                        <flux:input wire:model="telefono_cliente" label="Teléfono" placeholder="5512 8844" autocomplete="tel" />
                        <flux:input wire:model="municipio_envio" label="Municipio" placeholder="Ciudad de Guatemala" />
                    </div>

                    <flux:input wire:model="direccion_envio" label="Dirección" placeholder="4a. calle 12-45, zona 10" autocomplete="street-address" />

                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:select wire:model="departamento_envio" label="Departamento">
                            @foreach ($this->departamentos as $departamento)
                                <flux:select.option value="{{ $departamento }}">{{ $departamento }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:input wire:model="referencia_envio" label="Referencia (opcional)" placeholder="Portón negro, frente al parque" />
                    </div>
                </section>

                <section class="space-y-4 rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Datos de la tarjeta</h2>
                        <span class="rounded-full bg-zinc-100 px-2.5 py-1 text-[11px] font-medium text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                            Simulado
                        </span>
                    </div>

                    <flux:input wire:model="nombre_tarjeta" label="Nombre como aparece en la tarjeta" autocomplete="off" />

                    <flux:input
                        wire:model="numero_tarjeta"
                        label="Número de tarjeta"
                        placeholder="4111 1111 1111 1111"
                        inputmode="numeric"
                        autocomplete="off"
                        description="Se verifica con el algoritmo de Luhn y no se almacena."
                    />

                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model="vencimiento" label="Vencimiento (MM/AA)" placeholder="12/29" inputmode="numeric" autocomplete="off" />
                        <flux:input wire:model="cvv" label="Código de seguridad" placeholder="123" inputmode="numeric" autocomplete="off" />
                    </div>

                    <p class="text-xs leading-relaxed text-zinc-500 dark:text-zinc-400">
                        El código de seguridad se usa únicamente durante la verificación y no se guarda en
                        ningún momento, ni cifrado. De la tarjeta solo quedan registrados los últimos cuatro
                        dígitos y la marca.
                    </p>
                </section>
            </div>

            <aside class="lg:col-span-1">
                <div class="sticky top-24 space-y-4 rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                    <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Tu pedido</h2>

                    <ul class="space-y-3 text-sm">
                        @foreach ($carrito->lineas as $linea)
                            <li wire:key="resumen-{{ $linea->id }}" class="flex items-start justify-between gap-3">
                                <span class="min-w-0 text-zinc-600 dark:text-zinc-300">
                                    <span class="font-medium text-zinc-900 dark:text-white">{{ $linea->cantidad }} &times;</span>
                                    {{ $linea->producto->nombre }}
                                </span>
                                <span class="shrink-0 font-medium tabular-nums">{{ $linea->subtotalFormateado() }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <flux:separator />

                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-zinc-500 dark:text-zinc-400">Subtotal</dt>
                            <dd class="font-medium tabular-nums">{{ \App\Models\Producto::quetzales($carrito->subtotal()) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-zinc-500 dark:text-zinc-400">Envío</dt>
                            <dd class="font-medium tabular-nums">
                                {{ $carrito->costoEnvio() > 0 ? \App\Models\Producto::quetzales($carrito->costoEnvio()) : 'Gratis' }}
                            </dd>
                        </div>
                    </dl>

                    <flux:separator />

                    <div class="flex items-baseline justify-between">
                        <span class="text-sm font-medium text-zinc-600 dark:text-zinc-300">Total a pagar</span>
                        <span class="text-2xl font-bold tracking-tight tabular-nums text-zinc-900 dark:text-white">
                            {{ \App\Models\Producto::quetzales($carrito->total()) }}
                        </span>
                    </div>

                    <flux:button
                        type="submit"
                        variant="primary"
                        class="w-full"
                        icon="lock-closed"
                        wire:loading.attr="disabled"
                        wire:target="confirmar"
                    >
                        <span wire:loading.remove wire:target="confirmar">Confirmar pedido</span>
                        <span wire:loading wire:target="confirmar">Procesando...</span>
                    </flux:button>

                    <flux:button variant="ghost" class="w-full" :href="route('tienda.carrito')" wire:navigate>
                        Volver al carrito
                    </flux:button>
                </div>
            </aside>
        </form>
    @endif
</div>
