<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Alertas y triaje')] class extends Component {
    //
}; ?>

<x-pages::siem.navegacion
    titulo="Alertas y triaje"
    descripcion="Quien revisa, cuando y con que resultado. Cada cambio de estado queda sellado con su marca de tiempo y su responsable."
>
    {{-- El reparto por procedencia va ARRIBA del listado: la primera pregunta de la auditoria
         no es que alertas quedan, sino quien reviso las que ya no estan. --}}
    <livewire:siem.acciones-masivas />

    <livewire:siem.panel-alertas />
</x-pages::siem.navegacion>
