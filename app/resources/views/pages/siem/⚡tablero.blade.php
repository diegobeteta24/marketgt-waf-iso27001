<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Centro de monitoreo')] class extends Component {
    //
}; ?>

<x-layouts::app :title="__('Centro de monitoreo')">
    <x-pages::siem.navegacion
        titulo="Centro de monitoreo"
        descripcion="Vertice de deteccion. Eventos del WAF, de la aplicacion y del sistema operativo, correlacionados en un solo lugar."
    >
        <livewire:siem.tablero-principal />

        <livewire:siem.panel-alertas />
    </x-pages::siem.navegacion>
</x-layouts::app>
