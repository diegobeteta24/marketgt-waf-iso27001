@props(['titulo', 'descripcion'])

@php
    $pestanas = [
        ['ruta' => 'seo.integridad', 'texto' => 'Integridad'],
        ['ruta' => 'seo.incidentes', 'texto' => 'Incidentes'],
        ['ruta' => 'seo.laboratorio', 'texto' => 'Laboratorio'],
        ['ruta' => 'seo.demostracion', 'texto' => 'Demostración'],
    ];
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <flux:heading size="xl">{{ $titulo }}</flux:heading>
            <flux:subheading>{{ $descripcion }}</flux:subheading>
        </div>

        {{-- En móvil las pestañas van en rejilla de dos columnas: cuatro en una sola fila no
             caben en 375 px y «Demostración» se saldría de la pantalla. Desde sm vuelven a
             la fila única de siempre. --}}
        <nav class="grid w-full grid-cols-2 gap-1 rounded-lg border border-zinc-200 p-1 sm:flex sm:w-auto sm:flex-wrap dark:border-zinc-700" aria-label="Secciones de integridad de posicionamiento">
            @foreach ($pestanas as $pestana)
                <a
                    href="{{ route($pestana['ruta']) }}"
                    wire:navigate
                    @class([
                        'flex min-h-11 items-center justify-center rounded-md px-3 py-1.5 text-center text-sm font-medium transition sm:min-h-0',
                        'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => request()->routeIs($pestana['ruta']),
                        'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' => ! request()->routeIs($pestana['ruta']),
                    ])
                    @if (request()->routeIs($pestana['ruta'])) aria-current="page" @endif
                >{{ $pestana['texto'] }}</a>
            @endforeach
        </nav>
    </div>

    {{ $slot }}
</div>
