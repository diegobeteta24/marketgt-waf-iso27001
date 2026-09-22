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
    <body class="min-h-screen bg-neutral-100 text-zinc-800 antialiased dark:bg-linear-to-b dark:from-neutral-950 dark:to-neutral-900 dark:text-zinc-100">
        {{-- Relleno progresivo: en un teléfono de 375 px no sobra el ancho. --}}
        <div class="bg-muted flex min-h-svh flex-col items-center justify-center gap-6 p-4 sm:p-6 md:p-10">
            <div class="flex w-full max-w-md flex-col gap-6">
                <a href="{{ route('home') }}" class="flex flex-col items-center gap-2 font-medium" wire:navigate>
                    <span class="flex h-9 w-9 items-center justify-center rounded-md">
                        <x-app-logo-icon class="size-9 fill-current text-black dark:text-white" />
                    </span>

                    <span class="sr-only">{{ config('app.name', 'Laravel') }}</span>
                </a>

                <div class="flex flex-col gap-6">
                    <div class="rounded-xl border bg-white text-stone-800 shadow-xs dark:border-stone-800 dark:bg-stone-950 dark:text-zinc-100">
                        {{-- 40 px de relleno lateral fijos dejaban el formulario sin sitio en móvil. --}}
                        <div class="px-5 py-6 sm:px-10 sm:py-8">{{ $slot }}</div>
                    </div>
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
