<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Incidentes de posicionamiento')] class extends Component {
    //
}; ?>

<x-layouts::app :title="__('Incidentes de posicionamiento')">
    <x-pages::seo.navegacion
        titulo="Incidentes de posicionamiento"
        descripcion="Cada hallazgo con su evidencia, su regla hermana en el WAF y su estado de revisión."
    >
        <livewire:seo.tabla-incidentes />
    </x-pages::seo.navegacion>
</x-layouts::app>
