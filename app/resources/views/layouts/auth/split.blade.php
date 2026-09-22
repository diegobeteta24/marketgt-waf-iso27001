<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    {{--
        El color del texto se fija aquí, en el cuerpo. Sin esta declaración todo
        lo que no sea un componente de Flux hereda el negro por omisión del
        navegador, y sobre el fondo oscuro no se lee.
    --}}
    <body class="min-h-screen bg-white text-zinc-800 antialiased dark:bg-linear-to-b dark:from-neutral-950 dark:to-neutral-900 dark:text-zinc-100">
        {{--
            min-h-dvh en vez de h-dvh: si el formulario es más alto que la
            pantalla del teléfono, la página crece y se puede desplazar en
            vertical en lugar de recortar el contenido.
        --}}
        <div class="relative grid min-h-dvh flex-col items-center justify-center px-6 py-8 sm:px-8 lg:max-w-none lg:grid-cols-2 lg:px-0 lg:py-0">
            <div class="bg-muted relative hidden h-full flex-col p-10 text-white lg:flex dark:border-e dark:border-neutral-800">
                <div class="absolute inset-0 bg-neutral-900"></div>
                <a href="{{ route('home') }}" class="relative z-20 flex items-center text-lg font-medium" wire:navigate>
                    <span class="flex h-10 w-10 items-center justify-center rounded-md">
                        <x-app-logo-icon class="me-2 h-7 fill-current text-white" />
                    </span>
                    {{ config('app.name', 'Laravel') }}
                </a>

                @php
                    [$message, $author] = str(Illuminate\Foundation\Inspiring::quotes()->random())->explode('-');
                @endphp

                <div class="relative z-20 mt-auto">
                    <blockquote class="space-y-2">
                        <flux:heading size="lg">&ldquo;{{ trim($message) }}&rdquo;</flux:heading>
                        <footer><flux:heading>{{ trim($author) }}</flux:heading></footer>
                    </blockquote>
                </div>
            </div>
            <div class="w-full lg:p-8">
                {{-- Ancho completo en móvil con un máximo, en lugar de los 350 px fijos. --}}
                <div class="mx-auto flex w-full max-w-sm flex-col justify-center space-y-6">
                    <a href="{{ route('home') }}" class="z-20 flex flex-col items-center gap-2 font-medium lg:hidden" wire:navigate>
                        <span class="flex h-9 w-9 items-center justify-center rounded-md">
                            <x-app-logo-icon class="size-9 fill-current text-black dark:text-white" />
                        </span>

                        <span class="sr-only">{{ config('app.name', 'Laravel') }}</span>
                    </a>
                    {{ $slot }}
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
