<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title("Auditoría del mapa del sitio")] class extends Component {
    //
}; ?>

<x-pages::seo.navegacion
    titulo="Auditoría del mapa del sitio"
    descripcion="Quien inyecta páginas casi siempre modifica el mapa del sitio para que el buscador las indexe. Comparar lo declarado contra lo esperado delata la inyección aunque las páginas estén ocultas para quien navega."
>
    <livewire:seo.auditor-mapa-sitio />
</x-pages::seo.navegacion>
