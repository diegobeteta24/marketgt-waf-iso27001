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
    <body class="min-h-screen bg-white text-zinc-800 dark:bg-zinc-800 dark:text-zinc-100">
        {{--
            Por debajo de lg el menú lateral es el cajón del teléfono y sus
            enlaces miden 40 px: se suben a 44 para que se puedan pulsar con el
            dedo. En escritorio se deja la altura compacta de Flux.

            Los encabezados de grupo (General, Detección, Posicionamiento,
            Demostración) los pinta Flux en zinc-400 fijo: sobre el fondo oscuro
            quedan más apagados que los propios enlaces, que van en blanco al 80 %.
            Se aclaran y se marcan como encabezados con versalitas, de modo que la
            jerarquía no dependa de que estén más tenues que lo que encabezan.
        --}}
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 max-lg:[&_[data-flux-sidebar-item]]:h-11! [&_[data-flux-sidebar-group]>div:first-child]:uppercase [&_[data-flux-sidebar-group]>div:first-child]:tracking-wide [&_[data-flux-sidebar-group]>div:first-child]:text-zinc-500! dark:[&_[data-flux-sidebar-group]>div:first-child]:text-zinc-300!">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                {{-- El botón de cerrar el cajón también necesita 44 px reales. --}}
                <flux:sidebar.collapse class="lg:hidden size-11! [&_button]:size-11!" />
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

                        {{-- Los tres controles detectivos. Los anteriores impiden que el
                             contenido malicioso entre por donde escriben los usuarios;
                             estos parten de que el ataque ya ocurrió y buscan su huella,
                             que es el escenario de un sitio comprometido de verdad. --}}
                        <flux:sidebar.item icon="magnifying-glass" :href="route('seo.consultas')" :current="request()->routeIs('seo.consultas')" wire:navigate>
                            {{ __('Consultas') }}
                        </flux:sidebar.item>

                        <flux:sidebar.item icon="map" :href="route('seo.mapa-sitio')" :current="request()->routeIs('seo.mapa-sitio')" wire:navigate>
                            {{ __('Mapa del sitio') }}
                        </flux:sidebar.item>

                        <flux:sidebar.item icon="eye-slash" :href="route('seo.cloaking')" :current="request()->routeIs('seo.cloaking')" wire:navigate>
                            {{ __('Contenido diferenciado') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endif

                @if ($esAdmin)
                    <flux:sidebar.group :heading="__('Demostración')" class="grid">
                        <flux:sidebar.item icon="bolt" :href="route('demo.consola')" :current="request()->routeIs('demo.consola')" wire:navigate>
                            {{ __('Consola de ataques') }}
                        </flux:sidebar.item>

                        <flux:sidebar.item icon="beaker" :href="route('seo.laboratorio')" :current="request()->routeIs('seo.laboratorio')" wire:navigate>
                            {{ __('Laboratorio SEO') }}
                        </flux:sidebar.item>

                        <flux:sidebar.item icon="document-text" :href="route('seo.demostracion')" :current="request()->routeIs('seo.demostracion')" wire:navigate>
                            {{ __('Guion de demostración') }}
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

            {{--
                max-lg:hidden en lugar de "hidden lg:block": así en escritorio se
                respeta la presentación propia del desplegable de Flux y en móvil
                sólo se oculta, porque allí ya está el menú del encabezado.
            --}}
            <x-desktop-user-menu class="max-lg:hidden" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            {{-- El botón que abre el menú necesita 44 px reales para poder pulsarse con el dedo. --}}
            <flux:sidebar.toggle class="lg:hidden size-11!" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                {{--
                    El desplegable se ancla al borde derecho: se le pone un ancho
                    máximo relativo a la pantalla para que en un móvil estrecho no
                    se salga por ese borde.
                --}}
                <flux:menu class="max-w-[calc(100vw-2rem)]">
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                {{-- min-w-0 permite que truncate recorte el nombre y el correo largos. --}}
                                <div class="grid min-w-0 flex-1 text-start text-sm leading-tight">
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
