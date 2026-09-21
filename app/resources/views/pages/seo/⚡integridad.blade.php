<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Integridad de posicionamiento')] class extends Component {
    //
}; ?>

<x-layouts::app :title="__('Integridad de posicionamiento')">
    <x-pages::seo.navegacion
        titulo="Integridad de posicionamiento"
        descripcion="Lo que el WAF no puede ver: verificación inversa de rastreadores, integridad de robots.txt y del sitemap, y contenido retenido antes de publicarse."
    >
        <livewire:seo.panel-integridad />
    </x-pages::seo.navegacion>
</x-layouts::app>
