<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title("Análisis de consultas de búsqueda")] class extends Component {
    //
}; ?>

<x-pages::seo.navegacion
    titulo="Análisis de consultas de búsqueda"
    descripcion="Pegá las consultas que exportás de Search Console. El sistema marca las que no pertenecen al negocio: son la huella de un envenenamiento ya indexado."
>
    <livewire:seo.analizador-consultas />
</x-pages::seo.navegacion>
