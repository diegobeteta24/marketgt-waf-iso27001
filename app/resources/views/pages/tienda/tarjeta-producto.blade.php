@props([
    'producto',
])

{{--
    Tarjeta del catálogo. Debe renderizarse dentro de un componente Livewire que exponga
    el método agregar(int $productoId); el catálogo lo implementa.
--}}
<article
    wire:key="producto-{{ $producto->id }}"
    class="group flex flex-col overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm transition hover:shadow-md dark:border-zinc-700 dark:bg-zinc-800"
>
    <a href="{{ route('tienda.producto', $producto->slug) }}" wire:navigate class="block">
        <div class="relative aspect-square w-full overflow-hidden bg-linear-to-br {{ $producto->tonoPortada() }}">
            @if (filled($producto->imagen_url))
                <img
                    src="{{ $producto->imagen_url }}"
                    alt="{{ $producto->nombre }}"
                    loading="lazy"
                    class="size-full object-cover transition duration-300 group-hover:scale-105"
                />
            @else
                {{-- Portada generada localmente: la demostración tiene que verse igual de bien
                     aunque el salón de clase se quede sin Internet. --}}
                <div class="flex size-full items-center justify-center">
                    <span class="text-4xl font-black tracking-tight text-zinc-900/30 dark:text-white/40">
                        {{ $producto->iniciales() }}
                    </span>
                </div>
            @endif

            @unless ($producto->hayExistencias())
                <span class="absolute inset-x-0 bottom-0 bg-zinc-900/80 py-1.5 text-center text-xs font-semibold uppercase tracking-wide text-white">
                    Agotado
                </span>
            @endunless
        </div>
    </a>

    <div class="flex flex-1 flex-col gap-2 p-4">
        <p class="text-[11px] font-medium uppercase tracking-wide text-emerald-700 dark:text-emerald-400">
            {{ $producto->categoria?->nombre }}
        </p>

        <a href="{{ route('tienda.producto', $producto->slug) }}" wire:navigate class="block">
            <h3 class="line-clamp-2 text-sm font-semibold leading-snug text-zinc-900 dark:text-white">
                {{ $producto->nombre }}
            </h3>
        </a>

        <p class="line-clamp-2 text-xs text-zinc-500 dark:text-zinc-400">
            {{ $producto->descripcion }}
        </p>

        <div class="mt-auto flex items-end justify-between gap-2 pt-2">
            <div>
                <p class="text-lg font-bold tracking-tight text-zinc-900 dark:text-white">
                    {{ $producto->precioFormateado() }}
                </p>
                <p class="text-[11px] text-zinc-500 dark:text-zinc-400">
                    {{ $producto->hayExistencias() ? $producto->existencias.' disponibles' : 'Sin existencias' }}
                </p>
            </div>

            <flux:button
                size="sm"
                variant="primary"
                icon="plus"
                :disabled="! $producto->hayExistencias()"
                wire:click="agregar({{ $producto->id }})"
                wire:loading.attr="disabled"
                wire:target="agregar({{ $producto->id }})"
            >
                Agregar
            </flux:button>
        </div>
    </div>
</article>
