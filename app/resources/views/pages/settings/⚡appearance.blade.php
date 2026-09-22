<?php

use Livewire\Component;
use Livewire\Attributes\Title;

new #[Title('Appearance settings')] class extends Component {
    //
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Appearance settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Appearance')" :subheading="__('Update the appearance settings for your account')">
        {{--
            El control segmentado de Flux viene con 40 px de alto fijos; se libera
            la altura y se da 44 px a cada opción para poder elegirla con el dedo.
        --}}
        <flux:radio.group x-data variant="segmented" x-model="$flux.appearance" class="h-auto! w-full [&>ui-radio]:min-h-11">
            <flux:radio value="light" icon="sun">{{ __('Light') }}</flux:radio>
            <flux:radio value="dark" icon="moon">{{ __('Dark') }}</flux:radio>
            <flux:radio value="system" icon="computer-desktop">{{ __('System') }}</flux:radio>
        </flux:radio.group>
    </x-pages::settings.layout>
</section>
