<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Eventos de seguridad')] class extends Component {
    //
}; ?>

<x-layouts::app :title="__('Eventos de seguridad')">
    <x-pages::siem.navegacion
        titulo="Eventos de seguridad"
        descripcion="Registro normalizado de las tres fuentes. Los filtros viajan en la direccion para poder compartir exactamente lo que se esta viendo."
    >
        <livewire:siem.tabla-eventos />
    </x-pages::siem.navegacion>
</x-layouts::app>
