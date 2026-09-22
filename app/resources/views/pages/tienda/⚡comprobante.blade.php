<?php

use App\Models\Pedido;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('pages::tienda.layout')]
#[Title('Comprobante')]
class extends Component
{
    public Pedido $pedido;

    /**
     * Control de acceso al comprobante.
     *
     * El número del pedido es aleatorio, así que no se puede adivinar recorriendo enteros,
     * pero eso por sí solo no basta: un enlace copiado en un chat seguiría abriendo el
     * pedido de otra persona. Por eso se exige además una de dos condiciones: que el
     * pedido pertenezca al usuario autenticado, o que el número esté en la lista de
     * compras hechas desde esta misma sesión del navegador.
     *
     * Cuando ninguna se cumple se responde 404 y no 403: un 403 confirmaría que ese número
     * de pedido existe, y eso ya es información que no le corresponde a quien pregunta.
     */
    public function mount(string $numero): void
    {
        $pedido = Pedido::query()->with('lineas')->where('numero', $numero)->first();

        if ($pedido === null) {
            abort(404);
        }

        $esDelUsuario = Auth::check() && $pedido->usuario_id === Auth::id();
        $recientes = (array) Session::get(Pedido::CLAVE_SESION_RECIENTES, []);

        if (! $esDelUsuario && ! in_array($pedido->numero, $recientes, true)) {
            abort(404);
        }

        $this->pedido = $pedido;
    }
}; ?>

<div class="mx-auto max-w-3xl space-y-6">
    <section class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-center sm:rounded-3xl sm:p-6 dark:border-emerald-500/30 dark:bg-emerald-500/10">
        <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-emerald-600">
            <flux:icon.check class="text-white" />
        </div>
        <h1 class="mt-4 text-xl font-bold tracking-tight text-emerald-900 sm:text-2xl dark:text-emerald-100">
            ¡Pedido confirmado!
        </h1>
        {{-- break-words: un correo largo no tiene espacios donde cortar y, sin esto,
             empujaba la tarjeta fuera de la pantalla del teléfono. --}}
        <p class="mt-1 break-words text-sm text-emerald-800 dark:text-emerald-200">
            Gracias por tu compra, {{ $pedido->nombre_cliente }}. Enviamos el detalle a
            {{ $pedido->correo_cliente }}.
        </p>
        <p class="mt-4 inline-flex max-w-full items-center gap-2 break-all rounded-full bg-white px-4 py-1.5 text-sm font-semibold tracking-wide text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">
            Pedido {{ $pedido->numero }}
        </p>
    </section>

    <section class="rounded-2xl border border-zinc-200 bg-white p-4 sm:p-5 dark:border-zinc-700 dark:bg-zinc-800">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Detalle del pedido</h2>
            <span class="shrink-0 rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-medium text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300">
                {{ $pedido->estadoLegible() }}
            </span>
        </div>

        <div class="mt-4 space-y-3">
            @foreach ($pedido->lineas as $linea)
                <div wire:key="linea-pedido-{{ $linea->id }}" class="flex items-start justify-between gap-3 text-sm">
                    <div class="min-w-0">
                        <p class="break-words font-medium text-zinc-900 dark:text-white">{{ $linea->nombre_producto }}</p>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                            {{ $linea->cantidad }} &times; {{ $linea->precioFormateado() }}
                        </p>
                    </div>
                    <p class="shrink-0 font-semibold tabular-nums text-zinc-900 dark:text-white">
                        {{ $linea->subtotalFormateado() }}
                    </p>
                </div>
            @endforeach
        </div>

        <flux:separator class="my-4" />

        <dl class="space-y-2 text-sm">
            <div class="flex justify-between gap-3">
                <dt class="text-zinc-500 dark:text-zinc-400">Subtotal</dt>
                <dd class="shrink-0 font-medium tabular-nums">{{ $pedido->subtotalFormateado() }}</dd>
            </div>
            <div class="flex justify-between gap-3">
                <dt class="text-zinc-500 dark:text-zinc-400">Envío</dt>
                <dd class="shrink-0 font-medium tabular-nums">
                    {{ (float) $pedido->envio > 0 ? $pedido->envioFormateado() : 'Gratis' }}
                </dd>
            </div>
            <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 pt-2">
                <dt class="text-sm font-medium text-zinc-600 dark:text-zinc-300">Total</dt>
                <dd class="text-2xl font-bold tracking-tight tabular-nums text-zinc-900 dark:text-white">
                    {{ $pedido->totalFormateado() }}
                </dd>
            </div>
        </dl>
    </section>

    <div class="grid gap-4 sm:grid-cols-2">
        <section class="rounded-2xl border border-zinc-200 bg-white p-4 text-sm sm:p-5 dark:border-zinc-700 dark:bg-zinc-800">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Envío</h2>
            {{-- La dirección de envío es texto libre y puede traer cadenas largas: se parte
                 por palabras para que no se salga de la tarjeta en pantalla chica. --}}
            <address class="mt-3 break-words not-italic leading-relaxed text-zinc-600 dark:text-zinc-300">
                {{ $pedido->nombre_cliente }}<br />
                {{ $pedido->direccion_envio }}<br />
                {{ $pedido->municipio_envio }}, {{ $pedido->departamento_envio }}<br />
                Tel. {{ $pedido->telefono_cliente }}
                @if (filled($pedido->referencia_envio))
                    <br /><span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $pedido->referencia_envio }}</span>
                @endif
            </address>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 text-sm dark:border-zinc-700 dark:bg-zinc-800">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Medio de pago</h2>
            <p class="mt-3 font-medium text-zinc-900 dark:text-white">
                {{ $pedido->marca_tarjeta }} &bull;&bull;&bull;&bull; {{ $pedido->ultimos_cuatro }}
            </p>
            <p class="mt-2 text-xs leading-relaxed text-zinc-500 dark:text-zinc-400">
                De la tarjeta solo quedaron registrados la marca y los últimos cuatro dígitos, junto a un
                token opaco emitido por la pasarela. El número completo y el código de seguridad nunca
                llegaron a la base de datos.
            </p>
            @if ($pedido->pagado_en !== null)
                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                    Cobro simulado el {{ $pedido->pagado_en->format('d/m/Y \a \l\a\s H:i') }}
                </p>
            @endif
        </section>
    </div>

    <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
        Este comprobante corresponde a una compra simulada en un entorno académico. No se realizó ningún
        cobro y no existe envío físico.
    </div>

    <div class="flex flex-wrap justify-center gap-3">
        <flux:button variant="primary" icon="squares-2x2" :href="route('tienda.catalogo')" wire:navigate>
            Seguir comprando
        </flux:button>
    </div>
</div>
