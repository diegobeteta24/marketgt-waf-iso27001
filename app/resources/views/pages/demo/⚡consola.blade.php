<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Consola de ataques en vivo')] class extends Component {
    //
}; ?>

<div class="consola-demo">
    <x-pages::demo.estilos />

    <div class="mb-6">
        <flux:heading size="xl">Consola de ataques en vivo</flux:heading>
        <flux:subheading>
            Capa 4 (WAF) y Capa 5 (aplicación). Lance un ataque real contra el propio sitio y vea, sin cambiar de
            ventana, el bloqueo del WAF, la regla activada, la puntuación de anomalía y el evento de auditoría.
        </flux:subheading>
    </div>

    <livewire:demo.consola />
</div>
