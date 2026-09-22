<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Consola de ataques en vivo')] class extends Component {
    //
}; ?>

{{-- `min-w-0` evita que un hijo ancho (el bloque de código, la tabla de la bitácora)
     estire esta página por encima del ancho del teléfono. --}}
<div class="consola-demo w-full min-w-0">
    <x-pages::demo.estilos />

    <div class="mb-4 sm:mb-6">
        <flux:heading size="xl">Consola de ataques en vivo</flux:heading>
        <flux:subheading class="mt-1">
            Capa 4 (WAF) y Capa 5 (aplicación). Lance un ataque real contra el propio sitio y vea, sin cambiar de
            ventana, el bloqueo del WAF, la regla activada, la puntuación de anomalía y el evento de auditoría.
        </flux:subheading>
    </div>

    <livewire:demo.consola />
</div>
