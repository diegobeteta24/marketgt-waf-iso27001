@props(['titulo', 'descripcion'])

@php
    $pestanas = [
        ['ruta' => 'siem.tablero', 'texto' => 'Tablero'],
        ['ruta' => 'siem.eventos', 'texto' => 'Eventos'],
        ['ruta' => 'siem.alertas', 'texto' => 'Alertas'],
        ['ruta' => 'siem.metricas', 'texto' => 'Metricas'],
    ];
@endphp

<div class="panel-siem space-y-6">
    <x-pages::siem.estilos />

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ $titulo }}</flux:heading>
            <flux:subheading>{{ $descripcion }}</flux:subheading>
        </div>

        <nav class="flex flex-wrap gap-1 rounded-lg border border-zinc-200 p-1 dark:border-zinc-700" aria-label="Secciones del centro de monitoreo">
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
