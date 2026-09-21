<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Metricas de ciberresiliencia')] class extends Component {
    //
}; ?>

<x-pages::siem.navegacion
    titulo="Metricas de ciberresiliencia"
    descripcion="Proteccion, deteccion y respuesta calculadas sobre los datos reales. Lo que no se puede medir se declara sin datos."
>
    <livewire:siem.metricas-resiliencia />
</x-pages::siem.navegacion>
