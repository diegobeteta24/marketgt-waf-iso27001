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
                {{-- En móvil se conserva el nombre de la tienda y solo se oculta el lema: el
                     encabezado cabe igual y la marca nunca desaparece de la pantalla chica. --}}
                <a href="{{ route('tienda.catalogo') }}" wire:navigate class="flex min-h-11 shrink-0 items-center gap-2">
                    {{-- emerald-700 y no 600: el blanco sobre el verde 600 apenas llega a 3.8:1
                         y el distintivo de la marca se lavaba al sol. --}}
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-emerald-700 text-sm font-bold text-white">GT</span>
                    <span class="leading-tight">
                        <span class="block text-sm font-semibold tracking-tight sm:text-base">MarketGT</span>
                        <span class="hidden text-[11px] text-zinc-600 sm:block dark:text-zinc-300">Artesanía y tecnología de Guatemala</span>
                    </span>
                </a>

                <nav class="hidden items-center gap-1 md:flex">
                    <a
                        href="{{ route('tienda.catalogo') }}"
                        wire:navigate
                        @class([
                            'flex min-h-11 items-center rounded-lg px-3 py-2 text-sm font-medium transition',
                            'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white' => request()->routeIs('tienda.catalogo'),
                            'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' => ! request()->routeIs('tienda.catalogo'),
                        ])
                    >
                        Catálogo
                    </a>
                </nav>

                <div class="flex-1"></div>

                <livewire:pages::tienda.indicador-carrito />

                {{-- Antes estos enlaces se ocultaban por completo en móvil y no había forma de
                     entrar a la cuenta desde el teléfono. Ahora quedan como icono en pantalla
                     chica y recuperan el texto desde sm. --}}
                @auth
                    <a
                        href="{{ route('dashboard') }}"
                        wire:navigate
                        class="flex min-h-11 min-w-11 shrink-0 items-center justify-center gap-2 rounded-lg px-2 text-sm font-medium text-zinc-600 transition hover:bg-zinc-100 sm:px-3 dark:text-zinc-300 dark:hover:bg-zinc-800"
                        aria-label="Ir a mi cuenta"
                    >
                        <flux:icon.user variant="micro" />
                        <span class="hidden sm:inline">Mi cuenta</span>
                    </a>
                @else
                    <a
                        href="{{ route('login') }}"
                        wire:navigate
                        class="flex min-h-11 min-w-11 shrink-0 items-center justify-center gap-2 rounded-lg px-2 text-sm font-medium text-zinc-600 transition hover:bg-zinc-100 sm:px-3 dark:text-zinc-300 dark:hover:bg-zinc-800"
                        aria-label="Ingresar a la cuenta"
                    >
                        <flux:icon.arrow-right-end-on-rectangle variant="micro" />
                        <span class="hidden sm:inline">Ingresar</span>
                    </a>
                @endauth
            </div>
        </header>

        <main class="mx-auto w-full max-w-6xl px-4 py-6 sm:px-6 sm:py-10">
            {{ $slot }}
        </main>

        <footer class="mt-10 border-t border-zinc-200 bg-white sm:mt-16 dark:border-zinc-700 dark:bg-zinc-950">
            <div class="mx-auto grid max-w-6xl gap-6 px-4 py-8 text-sm sm:grid-cols-3 sm:px-6">
                <div>
                    <p class="font-semibold">MarketGT</p>
                    <p class="mt-1 text-zinc-600 dark:text-zinc-300">
                        Tienda de demostración construida para el curso de Seguridad y Auditoría de Sistemas,
                        Universidad Mariano Gálvez de Guatemala.
                    </p>
                </div>
                <div>
                    <p class="font-semibold">Defensa en profundidad</p>
                    {{-- Las tres capas de defensa son justamente lo que se evalúa: no pueden
                         quedar en el gris más tenue del pie de página. --}}
                    <ul class="mt-1 space-y-0.5 text-zinc-600 dark:text-zinc-300">
                        <li>Capa 4 &middot; WAF ModSecurity con OWASP CRS</li>
                        <li>Capa 5 &middot; Consultas preparadas en la aplicación</li>
                        <li>Capa 6 &middot; Tokenización del medio de pago</li>
                    </ul>
                </div>
                <div>
                    <p class="font-semibold">Pagos</p>
                    <p class="mt-1 text-zinc-600 dark:text-zinc-300">
                        No se almacena ningún número de tarjeta. Solo se conservan los últimos cuatro dígitos,
                        la marca y un token opaco emitido por la pasarela simulada.
                    </p>
                </div>
            </div>
            <div class="border-t border-zinc-200 px-4 py-4 text-center text-xs text-zinc-600 dark:border-zinc-800 dark:text-zinc-400">
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
