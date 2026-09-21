<?php

use App\Models\Carrito;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    /**
     * Las demás pantallas de la tienda emiten "carrito-actualizado" al agregar, cambiar
     * cantidad o vaciar. Este método no necesita cuerpo: basta con que Livewire vuelva a
     * pintar el componente para que el contador se recalcule.
     */
    #[On('carrito-actualizado')]
    public function refrescar(): void {}

    #[Computed]
    public function articulos(): int
    {
        return Carrito::articulosDelVisitante();
    }
}; ?>

<a
    href="{{ route('tienda.carrito') }}"
    wire:navigate
    class="relative flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 text-sm font-medium text-zinc-700 transition hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800"
    aria-label="Ver el carrito de compras"
>
    <flux:icon.shopping-bag variant="micro" />
    <span class="hidden sm:inline">Carrito</span>

    @if ($this->articulos > 0)
        <span class="flex min-w-5 items-center justify-center rounded-full bg-emerald-600 px-1.5 text-xs font-semibold text-white">
            {{ $this->articulos }}
        </span>
    @endif
</a>
