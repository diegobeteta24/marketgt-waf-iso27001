<?php

use App\Models\Carrito;
use App\Models\Producto;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('pages::tienda.layout')]
class extends Component
{
    public Producto $producto;

    public int $cantidad = 1;

    /**
     * El slug llega de la URL. Se resuelve con firstOrFail y filtrando por activo: un
     * producto dado de baja responde 404 y no se convierte en una página huérfana que el
     * rastreador indexe.
     */
    public function mount(string $slug): void
    {
        $this->producto = Producto::query()
            ->disponibles()
            ->with('categoria')
            ->where('slug', $slug)
            ->firstOrFail();
    }

    /**
     * Título dinámico de la pestaña.
     *
     * El parámetro DEBE llamarse $view: Livewire entrega los argumentos del gancho por
     * nombre y, si no coincide, intenta construir un Illuminate\View\View desde el
     * contenedor —que no sabe hacerlo— y la ficha entera responde 500.
     */
    public function rendering(\Illuminate\View\View $view): void
    {
        if (\Illuminate\View\View::hasMacro('title')) {
            $view->title($this->producto->nombre);
        }
    }

    /** @return Collection<int, Producto> */
    #[Computed]
    public function relacionados(): Collection
    {
        return Producto::query()
            ->disponibles()
            ->with('categoria')
            ->where('categoria_id', $this->producto->categoria_id)
            ->whereKeyNot($this->producto->id)
            ->inRandomOrder()
            ->limit(4)
            ->get();
    }

    public function aumentar(): void
    {
        if ($this->cantidad < $this->producto->existencias) {
            $this->cantidad++;
        }
    }

    public function disminuir(): void
    {
        if ($this->cantidad > 1) {
            $this->cantidad--;
        }
    }

    /**
     * Agrega al carrito. Sirve tanto para el botón principal de esta ficha como para las
     * tarjetas de productos relacionados, que llaman agregar($id) con una sola unidad.
     */
    public function agregar(?int $productoId = null, ?int $cantidad = null): void
    {
        // Siempre se relee del catálogo aplicando el alcance disponibles(): un producto
        // dado de baja mientras la ficha estaba abierta no puede colarse al carrito.
        $producto = Producto::disponibles()->find($productoId ?? $this->producto->id);

        if ($producto === null) {
            Flux::toast(variant: 'danger', text: 'Ese producto ya no está disponible.');

            return;
        }

        // La cantidad se acota en el servidor entre 1 y las existencias reales: el valor
        // que manda el navegador es una sugerencia, no una orden.
        $solicitadas = max(1, $cantidad ?? ($productoId === null ? $this->cantidad : 1));

        $carrito = Carrito::actual(crear: true);
        $linea = $carrito->lineas()->firstOrNew(['producto_id' => $producto->id]);
        $total = (int) ($linea->cantidad ?? 0) + $solicitadas;

        if ($total > $producto->existencias) {
            Flux::toast(
                variant: 'danger',
                text: $producto->existencias === 0
                    ? 'Este producto está agotado.'
                    : 'Solo quedan '.$producto->existencias.' unidades disponibles.',
            );

            return;
        }

        $linea->cantidad = $total;
        $linea->precio_unitario = $producto->precio;
        $linea->save();

        $this->dispatch('carrito-actualizado');

        Flux::toast(variant: 'success', text: $producto->nombre.' se agregó al carrito.');
    }
}; ?>

<div class="space-y-10">
    <nav aria-label="Ruta de navegación" class="flex flex-wrap items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
        <a href="{{ route('tienda.catalogo') }}" wire:navigate class="hover:text-zinc-900 dark:hover:text-white">Catálogo</a>
        <span>/</span>
        <a
            href="{{ route('tienda.catalogo', ['categoria' => $producto->categoria?->slug]) }}"
            wire:navigate
            class="hover:text-zinc-900 dark:hover:text-white"
        >
            {{ $producto->categoria?->nombre }}
        </a>
        <span>/</span>
        <span class="text-zinc-700 dark:text-zinc-200">{{ $producto->nombre }}</span>
    </nav>

    <div class="grid gap-8 lg:grid-cols-2">
        <div class="overflow-hidden rounded-3xl border border-zinc-200 bg-linear-to-br {{ $producto->tonoPortada() }} dark:border-zinc-700">
            <div class="aspect-square w-full">
                @if (filled($producto->imagen_url))
                    <img src="{{ $producto->imagen_url }}" alt="{{ $producto->nombre }}" class="size-full object-cover" />
                @else
                    <div class="flex size-full items-center justify-center">
                        <span class="text-7xl font-black tracking-tight text-zinc-900/25 dark:text-white/30">
                            {{ $producto->iniciales() }}
                        </span>
                    </div>
                @endif
            </div>
        </div>

        <div class="flex flex-col gap-5">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-400">
                    {{ $producto->categoria?->nombre }}
                </p>
                <h1 class="mt-2 text-2xl font-bold leading-tight tracking-tight text-zinc-900 sm:text-3xl dark:text-white">
                    {{ $producto->nombre }}
                </h1>
            </div>

            <p class="text-3xl font-bold tracking-tight text-zinc-900 dark:text-white">
                {{ $producto->precioFormateado() }}
            </p>

            <div class="flex items-center gap-2 text-sm">
                @if ($producto->existencias > 5)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">
                        <span class="size-1.5 rounded-full bg-emerald-500"></span>
                        {{ $producto->existencias }} unidades en existencia
                    </span>
                @elseif ($producto->existencias > 0)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">
                        <span class="size-1.5 rounded-full bg-amber-500"></span>
                        Últimas {{ $producto->existencias }} unidades
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-medium text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                        <span class="size-1.5 rounded-full bg-zinc-400"></span>
                        Agotado
                    </span>
                @endif
            </div>

            <p class="text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">
                {{ $producto->descripcion }}
            </p>

            <flux:separator />

            @if ($producto->hayExistencias())
                <div class="flex flex-wrap items-center gap-3">
                    <div class="flex items-center rounded-xl border border-zinc-200 dark:border-zinc-700">
                        <flux:button size="sm" variant="subtle" icon="minus" wire:click="disminuir" aria-label="Disminuir cantidad" />
                        <span class="w-10 text-center text-sm font-semibold tabular-nums">{{ $cantidad }}</span>
                        <flux:button size="sm" variant="subtle" icon="plus" wire:click="aumentar" aria-label="Aumentar cantidad" />
                    </div>

                    <flux:button variant="primary" icon="shopping-bag" wire:click="agregar" wire:loading.attr="disabled">
                        Agregar al carrito
                    </flux:button>

                    <flux:button variant="ghost" :href="route('tienda.carrito')" wire:navigate>
                        Ver carrito
                    </flux:button>
                </div>
            @else
                <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                    <p class="font-semibold">Producto agotado</p>
                    <p class="mt-1">
                        Este artículo no tiene existencias en este momento. Revisá el resto del catálogo
                        mientras se repone.
                    </p>
                </div>
            @endif

            <div class="rounded-xl bg-zinc-100 p-4 text-xs leading-relaxed text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                <p class="font-semibold text-zinc-800 dark:text-zinc-100">Envíos y pagos</p>
                <p class="mt-1">
                    Envío a toda la República de Guatemala por Q35.00, gratis en compras mayores a Q500.00.
                    El pago de esta tienda es simulado: no se cobra dinero y no se guarda ningún número de tarjeta.
                </p>
            </div>
        </div>
    </div>

    @if ($this->relacionados->isNotEmpty())
        <section class="space-y-4">
            <h2 class="text-lg font-semibold tracking-tight text-zinc-900 dark:text-white">
                También de {{ $producto->categoria?->nombre }}
            </h2>

            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($this->relacionados as $relacionado)
                    <x-pages::tienda.tarjeta-producto :producto="$relacionado" />
                @endforeach
            </div>
        </section>
    @endif
</div>
