@props([
    'status',
])

{{-- El verde iba sin variante oscura: green-600 sobre el fondo de las pantallas
     de acceso se queda en 3,1 a 1, por debajo del mínimo. Se aclara en oscuro. --}}
@if ($status)
    <div {{ $attributes->merge(['class' => 'font-medium text-sm text-emerald-700 dark:text-emerald-400']) }}>
        {{ $status }}
    </div>
@endif
