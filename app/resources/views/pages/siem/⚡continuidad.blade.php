<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Continuidad y recuperacion')] class extends Component {
    //
}; ?>

<x-pages::siem.navegacion
    titulo="Continuidad y recuperacion"
    descripcion="Cada prueba de restauracion ejecutada, con lo que tardo y de que respaldo partio. El RTO y el RPO del triangulo salen de aqui, no de la configuracion del respaldo."
>
    <livewire:siem.panel-continuidad />
</x-pages::siem.navegacion>
