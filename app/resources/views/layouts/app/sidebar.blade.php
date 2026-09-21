<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            {{--
                El menú se arma según el rol de quien entró. No es solo comodidad
                de interfaz: no mostrar lo que no se puede usar evita que la
                autorización se descubra a base de tropezar con pantallas de
                acceso denegado. La comprobación real la sigue haciendo el
                middleware en cada ruta; esto no la sustituye.
            --}}
            @php($usuario = auth()->user())
            @php($esAdmin = $usuario?->esAdministrador() ?? false)
            @php($esAuditor = $usuario?->esAuditor() ?? false)

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('General')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Inicio') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="shopping-bag" :href="route('tienda.catalogo')" :current="request()->routeIs('tienda.*')" wire:navigate>
                        {{ __('Tienda') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>

                @if ($esAdmin || $esAuditor)
                    <flux:sidebar.group :heading="__('Detección')" class="grid">
                        <flux:sidebar.item icon="chart-bar" :href="route('siem.tablero')" :current="request()->routeIs('siem.tablero')" wire:navigate>
                            {{ __('Tablero') }}
                        </flux:sidebar.item>

                        <flux:sidebar.item icon="bell-alert" :href="route('siem.alertas')" :current="request()->routeIs('siem.alertas')" wire:navigate>
                            {{ __('Alertas') }}
                        </flux:sidebar.item>

                        <flux:sidebar.item icon="list-bullet" :href="route('siem.eventos')" :current="request()->routeIs('siem.eventos')" wire:navigate>
                            {{ __('Eventos') }}
                        </flux:sidebar.item>

                        <flux:sidebar.item icon="presentation-chart-line" :href="route('siem.metricas')" :current="request()->routeIs('siem.metricas')" wire:navigate>
                            {{ __('Métricas') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    <flux:sidebar.group :heading="__('Posicionamiento')" class="grid">
                        <flux:sidebar.item icon="shield-exclamation" :href="route('seo.incidentes')" :current="request()->routeIs('seo.incidentes')" wire:navigate>
                            {{ __('Incidentes') }}
                        </flux:sidebar.item>

                        <flux:sidebar.item icon="finger-print" :href="route('seo.integridad')" :current="request()->routeIs('seo.integridad')" wire:navigate>
                            {{ __('Integridad') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endif

                @if ($esAdmin)
                    <flux:sidebar.group :heading="__('Demostración')" class="grid">
                        <flux:sidebar.item icon="beaker" :href="route('seo.laboratorio')" :current="request()->routeIs('seo.laboratorio')" wire:navigate>
                            {{ __('Laboratorio SEO') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endif
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav>
                <flux:sidebar.item icon="lock-closed" :href="route('security.edit')" :current="request()->routeIs('security.*')" wire:navigate>
                    {{ __('Seguridad de la cuenta') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
