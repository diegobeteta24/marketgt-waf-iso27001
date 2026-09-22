<div class="flex items-start max-md:flex-col">
    {{--
        En móvil el menú de ajustes ocupa todo el ancho y se coloca encima del
        contenido; el margen lateral y el ancho fijo de 220 px sólo se aplican
        desde md hacia arriba, donde ya hay sitio para las dos columnas.
    --}}
    <div class="w-full pb-4 md:me-10 md:w-[220px] md:shrink-0">
        <flux:navlist aria-label="{{ __('Settings') }}">
            <flux:navlist.item :href="route('profile.edit')" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
            <flux:navlist.item :href="route('security.edit')" wire:navigate>{{ __('Security') }}</flux:navlist.item>
            <flux:navlist.item :href="route('appearance.edit')" wire:navigate>{{ __('Appearance') }}</flux:navlist.item>
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" />

    <div class="w-full min-w-0 flex-1 self-stretch max-md:pt-6">
        <flux:heading>{{ $heading ?? '' }}</flux:heading>
        <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

        <div class="mt-5 w-full max-w-lg">
            {{ $slot }}
        </div>
    </div>
</div>
