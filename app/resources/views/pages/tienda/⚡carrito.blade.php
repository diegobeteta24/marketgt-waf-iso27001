<?php

use App\Models\Carrito;
use App\Models\LineaCarrito;
use App\Models\Producto;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('pages::tienda.layout')]
#[Title('Carrito de compras')]
class extends Component
{
    #[Computed]
    public function carrito(): ?Carrito
    {
        return Carrito::actual()?->load('lineas.producto.categoria');
    }

    /**
     * Cambia la cantidad de una línea.
     *
     * El identificador de la línea que manda el navegador se busca SIEMPRE acotado al
     * carrito del visitante actual. Si alguien altera la petición con el identificador de
     * la línea de otra persona, la consulta no devuelve nada y la operación se descarta:
     * esto es control de acceso a nivel de objeto (OWASP A01, referencia directa insegura).
     */
    public function cambiarCantidad(int $lineaId, int $cantidad): void
    {
        $carrito = Carrito::actual();

        if ($carrito === null) {
            return;
        }

        $linea = $carrito->lineas()->with('producto')->find($lineaId);

        if ($linea === null) {
            return;
        }

        if ($cantidad < 1) {
            $this->quitar($lineaId);

            return;
        }

        $existencias = (int) ($linea->producto?->existencias ?? 0);

        if ($cantidad > $existencias) {
            Flux::toast(variant: 'danger', text: 'Solo quedan '.$existencias.' unidades de este producto.');

            return;
        }

        $linea->update(['cantidad' => $cantidad]);

        unset($this->carrito);
        $this->dispatch('carrito-actualizado');
    }

    public function aumentar(int $lineaId): void
    {
        $linea = Carrito::actual()?->lineas()->find($lineaId);

        if ($linea instanceof LineaCarrito) {
            $this->cambiarCantidad($lineaId, $linea->cantidad + 1);
        }
    }

    public function disminuir(int $lineaId): void
    {
        $linea = Carrito::actual()?->lineas()->find($lineaId);

        if ($linea instanceof LineaCarrito) {
            $this->cambiarCantidad($lineaId, $linea->cantidad - 1);
        }
    }

    public function quitar(int $lineaId): void
    {
        $carrito = Carrito::actual();

        if ($carrito === null) {
            return;
        }

        $carrito->lineas()->whereKey($lineaId)->delete();

        unset($this->carrito);
        $this->dispatch('carrito-actualizado');

        Flux::toast(variant: 'success', text: 'Producto quitado del carrito.');
    }

    public function vaciar(): void
    {
        Carrito::actual()?->lineas()->delete();

        unset($this->carrito);
        $this->dispatch('carrito-actualizado');

        Flux::toast(variant: 'success', text: 'El carrito quedó vacío.');
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Carrito de compras</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Revisá las cantidades antes de continuar al pago.
            </p>
        </div>

        <flux:button variant="ghost" icon="arrow-left" :href="route('tienda.catalogo')" wire:navigate>
            Seguir comprando
        </flux:button>
    </div>

    @php($carrito = $this->carrito)

    @if ($carrito === null || $carrito->estaVacio())
        <section class="rounded-2xl border border-dashed border-zinc-300 bg-white px-6 py-16 text-center dark:border-zinc-700 dark:bg-zinc-800">
            <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-700">
                <flux:icon.shopping-bag class="text-zinc-400" />
            </div>
            <h2 class="mt-4 text-base font-semibold text-zinc-900 dark:text-white">Tu carrito está vacío</h2>
            <p class="mx-auto mt-1 max-w-sm text-sm text-zinc-500 dark:text-zinc-400">
                Todavía no agregaste productos. Empezá por el café de Antigua o por los textiles del altiplano.
            </p>
            <div class="mt-5">
                <flux:button variant="primary" icon="squares-2x2" :href="route('tienda.catalogo')" wire:navigate>
                    Ir al catálogo
                </flux:button>
            </div>
        </section>
    @else
        <div class="grid gap-6 lg:grid-cols-3">
            <section class="space-y-3 lg:col-span-2">
                @foreach ($carrito->lineas as $linea)
                    <article
                        wire:key="linea-{{ $linea->id }}"
                        class="flex gap-3 rounded-2xl border border-zinc-200 bg-white p-3 sm:gap-4 sm:p-4 dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <a
                            href="{{ route('tienda.producto', $linea->producto->slug) }}"
                            wire:navigate
                            class="size-20 shrink-0 overflow-hidden rounded-xl bg-gradient-to-br sm:size-24 {{ $linea->producto->tonoPortada() }}"
                        >
                            @if (filled($linea->producto->imagen_url))
                                <img src="{{ $linea->producto->imagen_url }}" alt="{{ $linea->producto->nombre }}" class="size-full object-cover" />
                            @else
                                <span class="flex size-full items-center justify-center text-lg font-black text-zinc-900/30 dark:text-white/40">
                                    {{ $linea->producto->iniciales() }}
                                </span>
                            @endif
                        </a>

                        <div class="flex min-w-0 flex-1 flex-col gap-2">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="text-[11px] font-medium uppercase tracking-wide text-emerald-700 dark:text-emerald-400">
                                        {{ $linea->producto->categoria?->nombre }}
                                    </p>
                                    <a href="{{ route('tienda.producto', $linea->producto->slug) }}" wire:navigate>
                                        <h2 class="truncate text-sm font-semibold text-zinc-900 dark:text-white">
                                            {{ $linea->producto->nombre }}
                                        </h2>
                                    </a>
                                    <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $linea->precioFormateado() }} c/u
                                    </p>
                                </div>

                                <flux:button
                                    size="sm"
                                    variant="subtle"
                                    icon="trash"
                                    wire:click="quitar({{ $linea->id }})"
                                    wire:confirm="¿Quitar «{{ $linea->producto->nombre }}» del carrito?"
                                    aria-label="Quitar del carrito"
                                />
                            </div>

                            <div class="mt-auto flex flex-wrap items-center justify-between gap-2">
                                <div class="flex items-center rounded-xl border border-zinc-200 dark:border-zinc-600">
                                    <flux:button
                                        size="sm"
                                        variant="subtle"
                                        icon="minus"
                                        wire:click="disminuir({{ $linea->id }})"
                                        aria-label="Disminuir cantidad"
                                    />
                                    <span class="w-9 text-center text-sm font-semibold tabular-nums">{{ $linea->cantidad }}</span>
                                    <flux:button
                                        size="sm"
                                        variant="subtle"
                                        icon="plus"
                                        wire:click="aumentar({{ $linea->id }})"
                                        aria-label="Aumentar cantidad"
                                    />
                                </div>

                                <p class="text-base font-bold tracking-tight text-zinc-900 dark:text-white">
                                    {{ $linea->subtotalFormateado() }}
                                </p>
                            </div>
                        </div>
                    </article>
                @endforeach

                <div class="pt-1">
                    <flux:button
                        size="sm"
                        variant="ghost"
                        icon="x-mark"
                        wire:click="vaciar"
                        wire:confirm="¿Vaciar todo el carrito?"
                    >
                        Vaciar carrito
                    </flux:button>
                </div>
            </section>

            <aside class="lg:col-span-1">
                <div class="sticky top-24 space-y-4 rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                    <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Resumen del pedido</h2>

                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-zinc-500 dark:text-zinc-400">
                                Subtotal ({{ $carrito->totalArticulos() }} {{ \Illuminate\Support\Str::plural('artículo', $carrito->totalArticulos()) }})
                            </dt>
                            <dd class="font-medium tabular-nums">{{ \App\Models\Producto::quetzales($carrito->subtotal()) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-zinc-500 dark:text-zinc-400">Envío</dt>
                            <dd class="font-medium tabular-nums">
                                @if ($carrito->costoEnvio() > 0)
                                    {{ \App\Models\Producto::quetzales($carrito->costoEnvio()) }}
                                @else
                                    <span class="text-emerald-600 dark:text-emerald-400">Gratis</span>
                                @endif
                            </dd>
                        </div>
                    </dl>

                    @if ($carrito->faltaParaEnvioGratis() > 0)
                        <p class="rounded-lg bg-emerald-50 px-3 py-2 text-xs text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">
                            Agregá {{ \App\Models\Producto::quetzales($carrito->faltaParaEnvioGratis()) }} más y el envío es gratis.
                        </p>
                    @endif

                    <flux:separator />

                    <div class="flex items-baseline justify-between">
                        <span class="text-sm font-medium text-zinc-600 dark:text-zinc-300">Total</span>
                        <span class="text-2xl font-bold tracking-tight text-zinc-900 tabular-nums dark:text-white">
                            {{ \App\Models\Producto::quetzales($carrito->total()) }}
                        </span>
                    </div>

                    <flux:button variant="primary" class="w-full" icon="credit-card" :href="route('tienda.pago')" wire:navigate>
                        Continuar al pago
                    </flux:button>

                    <p class="text-center text-[11px] leading-relaxed text-zinc-500 dark:text-zinc-400">
                        Entorno de demostración: el pago es simulado y no se almacena ningún número de tarjeta.
                    </p>
                </div>
            </aside>
        </div>
    @endif
</div>
