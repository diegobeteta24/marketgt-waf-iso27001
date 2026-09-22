<?php

use LivewireAttributesTitle;
use LivewireComponent;

new #[Title("Detección de contenido diferenciado")] class extends Component {
    //
}; ?>

<x-pages::seo.navegacion
    titulo="Detección de contenido diferenciado"
    descripcion="Pide la misma dirección como navegador y como rastreador, y compara. Explica por qué se puede navegar un sitio sin ver nada raro y aun así tenerlo indexado con contenido ajeno."
>
    <livewire:seo.detector-cloaking />
</x-pages::seo.navegacion>
