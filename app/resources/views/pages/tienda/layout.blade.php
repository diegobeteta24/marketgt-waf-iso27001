@props([
    'title' => null,
])

<!DOCTYPE html>
<html lang="es" class="scroll-smooth">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-900 dark:text-zinc-100">
        {{-- Aviso permanente: el profesor debe ver desde cualquier pantalla que esto es un
             entorno de práctica y que nunca se cobra dinero de verdad. --}}
        <div class="bg-amber-500 px-4 py-1.5 text-center text-[11px] font-medium leading-tight text-amber-950 sm:text-xs">
            Entorno de demostración académica &mdash; no se procesan pagos reales ni se almacenan números de tarjeta.
        </div>

        <header class="sticky top-0 z-40 border-b border-zinc-200 bg-white/90 backdrop-blur dark:border-zinc-700 dark:bg-zinc-900/90">
            <div class="mx-auto flex max-w-6xl items-center gap-3 px-4 py-3 sm:gap-6">
                <a href="{{ route('tienda.catalogo') }}" wire:navigate class="flex shrink-0 items-center gap-2">
                    <span class="flex size-9 items-center justify-center rounded-xl bg-emerald-600 text-sm font-bold text-white">GT</span>
                    <span class="hidden leading-tight sm:block">
                        <span class="block text-base font-semibold tracking-tight">MarketGT</span>
                        <span class="block text-[11px] text-zinc-500 dark:text-zinc-400">Artesanía y tecnología de Guatemala</span>
                    </span>
                </a>

                <nav class="hidden items-center gap-1 md:flex">
                    <a
                        href="{{ route('tienda.catalogo') }}"
                        wire:navigate
                        @class([
                            'rounded-lg px-3 py-2 text-sm font-medium transition',
                            'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white' => request()->routeIs('tienda.catalogo'),
                            'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' => ! request()->routeIs('tienda.catalogo'),
                        ])
                    >
                        Catálogo
                    </a>
                </nav>

                <div class="flex-1"></div>

                <livewire:pages::tienda.indicador-carrito />

                @auth
                    <a
                        href="{{ route('dashboard') }}"
                        wire:navigate
                        class="hidden rounded-lg px-3 py-2 text-sm font-medium text-zinc-600 transition hover:bg-zinc-100 sm:block dark:text-zinc-300 dark:hover:bg-zinc-800"
                    >
                        Mi cuenta
                    </a>
                @else
                    <a
                        href="{{ route('login') }}"
                        wire:navigate
                        class="hidden rounded-lg px-3 py-2 text-sm font-medium text-zinc-600 transition hover:bg-zinc-100 sm:block dark:text-zinc-300 dark:hover:bg-zinc-800"
                    >
                        Ingresar
                    </a>
                @endauth
            </div>
        </header>

        <main class="mx-auto w-full max-w-6xl px-4 py-6 sm:py-10">
            {{ $slot }}
        </main>

        <footer class="mt-16 border-t border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-950">
            <div class="mx-auto grid max-w-6xl gap-6 px-4 py-8 text-sm sm:grid-cols-3">
                <div>
                    <p class="font-semibold">MarketGT</p>
                    <p class="mt-1 text-zinc-500 dark:text-zinc-400">
                        Tienda de demostración construida para el curso de Seguridad y Auditoría de Sistemas,
                        Universidad Mariano Gálvez de Guatemala.
                    </p>
                </div>
                <div>
                    <p class="font-semibold">Defensa en profundidad</p>
                    <ul class="mt-1 space-y-0.5 text-zinc-500 dark:text-zinc-400">
                        <li>Capa 4 &middot; WAF ModSecurity con OWASP CRS</li>
                        <li>Capa 5 &middot; Consultas preparadas en la aplicación</li>
                        <li>Capa 6 &middot; Tokenización del medio de pago</li>
                    </ul>
                </div>
                <div>
                    <p class="font-semibold">Pagos</p>
                    <p class="mt-1 text-zinc-500 dark:text-zinc-400">
                        No se almacena ningún número de tarjeta. Solo se conservan los últimos cuatro dígitos,
                        la marca y un token opaco emitido por la pasarela simulada.
                    </p>
                </div>
            </div>
            <div class="border-t border-zinc-200 px-4 py-4 text-center text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                &copy; {{ now()->year }} MarketGT &middot; Proyecto académico sin fines comerciales
            </div>
        </footer>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
