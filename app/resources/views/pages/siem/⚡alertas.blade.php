<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Alertas y triaje')] class extends Component {
    //
}; ?>

<x-layouts::app :title="__('Alertas y triaje')">
    <x-pages::siem.navegacion
        titulo="Alertas y triaje"
        descripcion="Quien revisa, cuando y con que resultado. Cada cambio de estado queda sellado con su marca de tiempo y su responsable."
    >
        <livewire:siem.panel-alertas />
    </x-pages::siem.navegacion>
</x-layouts::app>
