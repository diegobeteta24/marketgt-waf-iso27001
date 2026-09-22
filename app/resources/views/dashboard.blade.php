@php
    // Los contadores se leen aquí y no en un componente aparte: son cuatro
    // consultas agregadas sobre columnas indexadas y la página no se recarga
    // sola. Un componente Livewire con sondeo añadiría carga sin aportar nada,
    // porque para el seguimiento en vivo ya está el tablero de detección.
    use App\Models\Producto;
    use App\Models\EventoSeguridad;
    use App\Models\AlertaSeguridad;

    $usuario   = auth()->user();
    $esAdmin   = $usuario?->esAdministrador() ?? false;
    $esAuditor = $usuario?->esAuditor() ?? false;
    $operador  = $esAdmin || $esAuditor;

    $desde = now()->subDay();

    $productos = Producto::query()->where('activo', true)->count();

    // Se consulta con `rescue` porque el panel de inicio nunca debe romperse:
    // si la ingesta de eventos todavía no corrió, la tabla existe pero vacía,
    // y si algo fallara es preferible mostrar un guion que una traza de error.
    //
    // Dos correcciones que importan más de lo que parece:
    //
    // Se filtra por `marca_tiempo` y no por la fecha de inserción. Son cosas
    // distintas: la primera es cuándo ocurrió el evento, la segunda cuándo se
    // escribió la fila. Como los eventos de demostración se insertan todos de
    // golpe pero están fechados a lo largo de una semana, filtrar por la fecha
    // de inserción los contaba todos como de las últimas veinticuatro horas.
    // Un contador que exagera el tráfico reciente es peor que no tenerlo.
    //
    // Y la columna de bloqueo se llama `fue_bloqueado`. Con el nombre erróneo
    // la consulta fallaba y `rescue` devolvía un guion, de modo que el panel
    // mostraba un hueco donde debía ir la cifra más importante: cuántos
    // ataques detuvo el cortafuegos.
    $eventos24h  = $operador ? rescue(fn () => EventoSeguridad::where('marca_tiempo', '>=', $desde)->count(), null, false) : null;
    $bloqueados  = $operador ? rescue(fn () => EventoSeguridad::where('marca_tiempo', '>=', $desde)->where('fue_bloqueado', true)->count(), null, false) : null;
    $abiertas    = $operador ? rescue(fn () => AlertaSeguridad::whereIn('estado', ['nueva', 'en_triaje'])->count(), null, false) : null;

    $segundoFactorActivo = filled($usuario?->two_factor_confirmed_at ?? null);
@endphp

<x-layouts::app :title="__('Inicio')">
    <div class="flex h-full w-full min-w-0 flex-1 flex-col gap-4 rounded-xl sm:gap-6">

        {{-- Encabezado --}}
        <div>
            <flux:heading size="xl">MarketGT</flux:heading>
            <flux:text class="mt-1">
                Plataforma de comercio electrónico protegida por un cortafuegos de aplicación,
                conforme a la norma ISO/IEC 27001:2022.
            </flux:text>
        </div>

        {{-- Aviso de segundo factor. Va arriba del todo a propósito: una cuenta
             con privilegios sin segundo factor es el hallazgo más caro que
             puede dejar una revisión sobre este proyecto. --}}
        @unless ($segundoFactorActivo)
            <div class="rounded-xl border border-amber-300 bg-amber-50 p-3 sm:p-4 dark:border-amber-700/60 dark:bg-amber-950/30">
                <div class="flex items-start gap-2 sm:gap-3">
                    <flux:icon.shield-exclamation class="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-500" />
                    <div class="min-w-0 flex-1">
                        <flux:heading size="sm" class="text-amber-900 dark:text-amber-200">
                            Esta cuenta no tiene segundo factor activo
                        </flux:heading>
                        <flux:text class="mt-1 text-amber-800 dark:text-amber-300/90">
                            La contraseña es hoy la única barrera de acceso. Activá una contraseña
                            de un solo uso o registrá una passkey.
                        </flux:text>
                        {{-- A ancho completo en el teléfono para que no se salga del aviso,
                             y con 44 px de alto para poder pulsarlo con el dedo. --}}
                        <flux:button :href="route('security.edit')" variant="primary" size="sm" class="mt-3 min-h-11 w-full sm:w-auto" wire:navigate>
                            Configurar ahora
                        </flux:button>
                    </div>
                </div>
            </div>
        @endunless

        {{-- Contadores. En el teléfono van a dos columnas cuando hay cuatro contadores
             (operador) y a una cuando solo hay uno, para que no quede una tarjeta suelta a
             media pantalla. Desde sm manda siempre la rejilla.

             Las etiquetas iban en neutral-500, que sobre el fondo oscuro da una relación
             de contraste de 3,5 a 1: por debajo del mínimo para texto pequeño. Y las
             cifras no llevaban color propio, de modo que heredaban el negro del cuerpo.

             Los cuatro contadores tampoco valen lo mismo. "Bloqueados por el WAF" es el
             resultado que justifica el proyecto entero, así que lleva su propio recuadro,
             una cifra mayor y un icono: los otros tres son contexto. El verde va con el
             escudo, porque que el cortafuegos bloquee es un acierto, no un fallo, y
             porque el color solo no basta para quien no lo distingue. --}}
        <div class="grid gap-3 {{ $operador ? 'grid-cols-2' : 'grid-cols-1' }} sm:grid-cols-2 sm:gap-4 lg:grid-cols-4">
            <div class="rounded-xl border border-neutral-200 p-3 sm:p-4 dark:border-neutral-700">
                <flux:text size="sm" class="text-neutral-600 dark:text-zinc-400">Productos publicados</flux:text>
                <div class="mt-1 text-2xl font-semibold tabular-nums text-zinc-900 sm:text-3xl dark:text-zinc-100">{{ number_format($productos) }}</div>
            </div>

            @if ($operador)
                <div class="rounded-xl border border-neutral-200 p-3 sm:p-4 dark:border-neutral-700">
                    <flux:text size="sm" class="text-neutral-600 dark:text-zinc-400">Eventos · últimas 24 h</flux:text>
                    <div class="mt-1 text-2xl font-semibold tabular-nums text-zinc-900 sm:text-3xl dark:text-zinc-100">
                        {{ is_null($eventos24h) ? '—' : number_format($eventos24h) }}
                    </div>
                </div>

                {{-- A ancho completo en el teléfono y con recuadro propio: es la cifra
                     que hay que ver primero. --}}
                <div class="col-span-2 rounded-xl border border-emerald-300 bg-emerald-50 p-3 sm:col-span-1 sm:p-4 dark:border-emerald-500/40 dark:bg-emerald-950/40">
                    <div class="flex items-center gap-1.5">
                        <flux:icon.shield-check class="size-4 shrink-0 text-emerald-700 dark:text-emerald-400" />
                        <flux:text size="sm" class="font-medium text-emerald-800 dark:text-emerald-300">Bloqueados por el WAF</flux:text>
                    </div>
                    <div class="mt-1 text-3xl font-bold tabular-nums text-emerald-700 sm:text-4xl dark:text-emerald-400">
                        {{ is_null($bloqueados) ? '—' : number_format($bloqueados) }}
                    </div>
                </div>

                <div class="rounded-xl border border-neutral-200 p-3 sm:p-4 dark:border-neutral-700">
                    <flux:text size="sm" class="text-neutral-600 dark:text-zinc-400">Alertas abiertas</flux:text>
                    <div class="mt-1 text-2xl font-semibold tabular-nums sm:text-3xl {{ $abiertas ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-900 dark:text-zinc-100' }}">
                        {{ is_null($abiertas) ? '—' : number_format($abiertas) }}
                    </div>
                </div>
            @endif
        </div>

        {{-- Accesos.

             Antes eran siete tarjetas seguidas en filas de tres, de modo que la última
             quedaba sola ocupando un tercio del ancho. Se agrupan por bloques con el
             mismo criterio que el menú lateral —operación, detección, demostración—, que
             además dice para qué sirve cada cosa en vez de dejarlas en una lista plana.

             Las descripciones iban en neutral-500 y el icono también: los dos por debajo
             del mínimo de contraste sobre el fondo oscuro. --}}
        <div>
            <flux:heading size="lg" class="mb-3">Módulos</flux:heading>

            <div class="space-y-5">
                <section>
                    <h3 class="mb-2 text-xs font-medium tracking-wide uppercase text-neutral-600 dark:text-zinc-300">Operación</h3>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <a href="{{ route('tienda.catalogo') }}" wire:navigate
                           class="group rounded-xl border border-neutral-200 p-4 transition hover:border-neutral-400 dark:border-neutral-700 dark:hover:border-neutral-500">
                            <flux:icon.shopping-bag class="size-5 text-neutral-600 dark:text-zinc-400" />
                            <flux:heading size="sm" class="mt-2">Tienda</flux:heading>
                            <flux:text size="sm" class="mt-1 text-neutral-600 dark:text-zinc-300">
                                Catálogo, carrito y pago simulado. Es el activo que el esquema protege.
                            </flux:text>
                        </a>

                        <a href="{{ route('security.edit') }}" wire:navigate
                           class="group rounded-xl border border-neutral-200 p-4 transition hover:border-neutral-400 dark:border-neutral-700 dark:hover:border-neutral-500">
                            <flux:icon.lock-closed class="size-5 text-neutral-600 dark:text-zinc-400" />
                            <flux:heading size="sm" class="mt-2">Seguridad de la cuenta</flux:heading>
                            <flux:text size="sm" class="mt-1 text-neutral-600 dark:text-zinc-300">
                                Contraseña de un solo uso, passkeys y códigos de recuperación.
                            </flux:text>
                        </a>
                    </div>
                </section>

                @if ($operador)
                    <section>
                        <h3 class="mb-2 text-xs font-medium tracking-wide uppercase text-neutral-600 dark:text-zinc-300">Detección</h3>
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <a href="{{ route('siem.tablero') }}" wire:navigate
                               class="group rounded-xl border border-neutral-200 p-4 transition hover:border-neutral-400 dark:border-neutral-700 dark:hover:border-neutral-500">
                                <flux:icon.chart-bar class="size-5 text-neutral-600 dark:text-zinc-400" />
                                <flux:heading size="sm" class="mt-2">Tablero de detección</flux:heading>
                                <flux:text size="sm" class="mt-1 text-neutral-600 dark:text-zinc-300">
                                    Eventos correlacionados del cortafuegos, la aplicación y el sistema.
                                </flux:text>
                            </a>

                            <a href="{{ route('siem.alertas') }}" wire:navigate
                               class="group rounded-xl border border-neutral-200 p-4 transition hover:border-neutral-400 dark:border-neutral-700 dark:hover:border-neutral-500">
                                <flux:icon.bell-alert class="size-5 text-neutral-600 dark:text-zinc-400" />
                                <flux:heading size="sm" class="mt-2">Alertas</flux:heading>
                                <flux:text size="sm" class="mt-1 text-neutral-600 dark:text-zinc-300">
                                    Triaje y contención. Es lo que convierte la detección en respuesta.
                                </flux:text>
                            </a>

                            <a href="{{ route('siem.metricas') }}" wire:navigate
                               class="group rounded-xl border border-neutral-200 p-4 transition hover:border-neutral-400 dark:border-neutral-700 dark:hover:border-neutral-500">
                                <flux:icon.presentation-chart-line class="size-5 text-neutral-600 dark:text-zinc-400" />
                                <flux:heading size="sm" class="mt-2">Métricas del triángulo</flux:heading>
                                <flux:text size="sm" class="mt-1 text-neutral-600 dark:text-zinc-300">
                                    Protección, detección y respuesta, calculadas sobre los datos reales.
                                </flux:text>
                            </a>
                        </div>
                    </section>

                    <section>
                        <h3 class="mb-2 text-xs font-medium tracking-wide uppercase text-neutral-600 dark:text-zinc-300">Demostración</h3>
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <a href="{{ route('demo.consola') }}" wire:navigate
                               class="group rounded-xl border border-neutral-200 p-4 transition hover:border-neutral-400 dark:border-neutral-700 dark:hover:border-neutral-500">
                                <flux:icon.bolt class="size-5 text-neutral-600 dark:text-zinc-400" />
                                <flux:heading size="sm" class="mt-2">Consola de ataques</flux:heading>
                                <flux:text size="sm" class="mt-1 text-neutral-600 dark:text-zinc-300">
                                    Lanza ataques reales contra la propia plataforma y muestra el bloqueo del cortafuegos.
                                </flux:text>
                            </a>

                            <a href="{{ route('seo.incidentes') }}" wire:navigate
                               class="group rounded-xl border border-neutral-200 p-4 transition hover:border-neutral-400 dark:border-neutral-700 dark:hover:border-neutral-500">
                                <flux:icon.shield-exclamation class="size-5 text-neutral-600 dark:text-zinc-400" />
                                <flux:heading size="sm" class="mt-2">Integridad de posicionamiento</flux:heading>
                                <flux:text size="sm" class="mt-1 text-neutral-600 dark:text-zinc-300">
                                    Rastreadores falsificados, contenido no autorizado y redirecciones.
                                </flux:text>
                            </a>
                        </div>
                    </section>
                @endif
            </div>
        </div>

        {{-- Aviso de entorno --}}
        <div class="mt-auto rounded-xl border border-neutral-200 p-3 sm:p-4 dark:border-neutral-700">
            <flux:text size="sm" class="text-neutral-600 dark:text-zinc-400">
                Entorno de demostración académica. No se procesan pagos reales ni se almacenan
                números de tarjeta: los medios de pago se tokenizan y solo se conservan los
                cuatro últimos dígitos.
            </flux:text>
        </div>

    </div>
</x-layouts::app>
