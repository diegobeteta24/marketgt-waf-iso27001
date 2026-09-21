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
        <div>
            <flux:heading size="xl">{{ $titulo }}</flux:heading>
            <flux:subheading>{{ $descripcion }}</flux:subheading>
        </div>

        <nav class="flex flex-wrap gap-1 rounded-lg border border-zinc-200 p-1 dark:border-zinc-700" aria-label="Secciones de integridad de posicionamiento">
            @foreach ($pestanas as $pestana)
                <a
                    href="{{ route($pestana['ruta']) }}"
                    wire:navigate
                    @class([
                        'rounded-md px-3 py-1.5 text-sm font-medium transition',
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
