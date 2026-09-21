@props(['valor' => 'informativa'])

@php
    $etiquetas = [
        'critica' => 'Critica',
        'alta' => 'Alta',
        'media' => 'Media',
        'baja' => 'Baja',
        'informativa' => 'Informativa',
    ];
@endphp

{{-- El punto de color va siempre acompanado del texto: el color nunca es el unico canal. --}}
<span {{ $attributes->merge(['class' => 'siem-sev siem-sev-'.$valor]) }}>
    {{ $etiquetas[$valor] ?? $valor }}
</span>
