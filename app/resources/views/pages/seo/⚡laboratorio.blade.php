<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Laboratorio de contenido')] class extends Component {
    //
}; ?>

<x-pages::seo.navegacion
    titulo="Laboratorio de contenido"
    descripcion="Banco de pruebas en vivo de los tres controles preventivos: sanitización, verificación inversa de rastreadores y lista blanca de redirección."
>
    <livewire:seo.laboratorio-contenido />
</x-pages::seo.navegacion>
