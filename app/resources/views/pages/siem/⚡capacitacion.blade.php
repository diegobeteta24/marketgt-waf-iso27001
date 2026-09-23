<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Capacitacion del personal')] class extends Component {
    //
}; ?>

<x-pages::siem.navegacion
    titulo="Capacitacion del personal"
    descripcion="El plan de POL-006 y quien lo completo. La asistencia la registra una persona con rol, nunca el propio interesado: una casilla autodeclarada no es evidencia de nada."
>
    <livewire:seguridad.panel-capacitacion />
</x-pages::siem.navegacion>
