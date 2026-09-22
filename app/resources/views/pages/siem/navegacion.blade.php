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
        <div class="min-w-0">
            <flux:heading size="xl">{{ $titulo }}</flux:heading>
            <flux:subheading>{{ $descripcion }}</flux:subheading>
        </div>

        {{-- En un telefono las cuatro pestanas se reparten en dos filas de dos y cada una
             ocupa su mitad: en linea no cabrian y, sobre todo, un enlace de 32 pixeles de
             alto no se acierta con el dedo. Desde sm vuelven a su fila unica compacta. --}}
        <nav class="grid w-full grid-cols-2 gap-1 rounded-lg border border-zinc-200 p-1 sm:flex sm:w-auto sm:flex-wrap dark:border-zinc-700" aria-label="Secciones del centro de monitoreo">
            @foreach ($pestanas as $pestana)
                <a
                    href="{{ route($pestana['ruta']) }}"
                    wire:navigate
                    @class([
                        'flex min-h-11 items-center justify-center rounded-md px-3 text-sm font-medium transition sm:min-h-0 sm:py-1.5',
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
