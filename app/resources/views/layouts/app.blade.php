<x-layouts::app.sidebar :title="$title ?? null">
    {{--
        min-w-0 evita que un contenido ancho (una tabla, un bloque de código)
        estire la columna principal de la rejilla y provoque desplazamiento
        horizontal en toda la página. El relleno crece por tramos para no
        desperdiciar ancho en el teléfono.
    --}}
    <flux:main class="min-w-0 p-4! sm:p-6! lg:p-8!">
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
