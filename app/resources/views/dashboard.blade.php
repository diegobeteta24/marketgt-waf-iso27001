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
    $eventos24h  = $operador ? rescue(fn () => EventoSeguridad::where('created_at', '>=', $desde)->count(), null, false) : null;
    $bloqueados  = $operador ? rescue(fn () => EventoSeguridad::where('created_at', '>=', $desde)->where('bloqueado', true)->count(), null, false) : null;
    $abiertas    = $operador ? rescue(fn () => AlertaSeguridad::whereIn('estado', ['nueva', 'en_triaje'])->count(), null, false) : null;

    $segundoFactorActivo = filled($usuario?->two_factor_confirmed_at ?? null);
@endphp

<x-layouts::app :title="__('Inicio')">
    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">

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
            <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-700/60 dark:bg-amber-950/30">
                <div class="flex items-start gap-3">
                    <flux:icon.shield-exclamation class="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-500" />
                    <div class="flex-1">
                        <flux:heading size="sm" class="text-amber-900 dark:text-amber-200">
                            Esta cuenta no tiene segundo factor activo
                        </flux:heading>
                        <flux:text class="mt-1 text-amber-800 dark:text-amber-300/90">
                            La contraseña es hoy la única barrera de acceso. Activá una contraseña
                            de un solo uso o registrá una passkey.
                        </flux:text>
                        <flux:button :href="route('security.edit')" variant="primary" size="sm" class="mt-3" wire:navigate>
                            Configurar ahora
                        </flux:button>
                    </div>
                </div>
            </div>
        @endunless

        {{-- Contadores --}}
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <flux:text size="sm" class="text-neutral-500">Productos publicados</flux:text>
                <div class="mt-1 text-3xl font-semibold tabular-nums">{{ number_format($productos) }}</div>
            </div>

            @if ($operador)
                <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                    <flux:text size="sm" class="text-neutral-500">Eventos · últimas 24 h</flux:text>
                    <div class="mt-1 text-3xl font-semibold tabular-nums">
                        {{ is_null($eventos24h) ? '—' : number_format($eventos24h) }}
                    </div>
                </div>

                <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                    <flux:text size="sm" class="text-neutral-500">Bloqueados por el WAF</flux:text>
                    <div class="mt-1 text-3xl font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">
                        {{ is_null($bloqueados) ? '—' : number_format($bloqueados) }}
                    </div>
                </div>

                <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                    <flux:text size="sm" class="text-neutral-500">Alertas abiertas</flux:text>
                    <div class="mt-1 text-3xl font-semibold tabular-nums {{ $abiertas ? 'text-amber-600 dark:text-amber-400' : '' }}">
                        {{ is_null($abiertas) ? '—' : number_format($abiertas) }}
                    </div>
                </div>
            @endif
        </div>

        {{-- Accesos --}}
        <div>
            <flux:heading size="lg" class="mb-3">Módulos</flux:heading>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">

                <a href="{{ route('tienda.catalogo') }}" wire:navigate
                   class="group rounded-xl border border-neutral-200 p-4 transition hover:border-neutral-400 dark:border-neutral-700 dark:hover:border-neutral-500">
                    <flux:icon.shopping-bag class="size-5 text-neutral-500" />
                    <flux:heading size="sm" class="mt-2">Tienda</flux:heading>
                    <flux:text size="sm" class="mt-1 text-neutral-500">
                        Catálogo, carrito y pago simulado. Es el activo que el esquema protege.
                    </flux:text>
                </a>

                <a href="{{ route('security.edit') }}" wire:navigate
                   class="group rounded-xl border border-neutral-200 p-4 transition hover:border-neutral-400 dark:border-neutral-700 dark:hover:border-neutral-500">
                    <flux:icon.lock-closed class="size-5 text-neutral-500" />
                    <flux:heading size="sm" class="mt-2">Seguridad de la cuenta</flux:heading>
                    <flux:text size="sm" class="mt-1 text-neutral-500">
                        Contraseña de un solo uso, passkeys y códigos de recuperación.
                    </flux:text>
                </a>

                @if ($operador)
                    <a href="{{ route('siem.tablero') }}" wire:navigate
                       class="group rounded-xl border border-neutral-200 p-4 transition hover:border-neutral-400 dark:border-neutral-700 dark:hover:border-neutral-500">
                        <flux:icon.chart-bar class="size-5 text-neutral-500" />
                        <flux:heading size="sm" class="mt-2">Tablero de detección</flux:heading>
                        <flux:text size="sm" class="mt-1 text-neutral-500">
                            Eventos correlacionados del cortafuegos, la aplicación y el sistema.
                        </flux:text>
                    </a>

                    <a href="{{ route('siem.alertas') }}" wire:navigate
                       class="group rounded-xl border border-neutral-200 p-4 transition hover:border-neutral-400 dark:border-neutral-700 dark:hover:border-neutral-500">
                        <flux:icon.bell-alert class="size-5 text-neutral-500" />
                        <flux:heading size="sm" class="mt-2">Alertas</flux:heading>
                        <flux:text size="sm" class="mt-1 text-neutral-500">
                            Triaje y contención. Es lo que convierte la detección en respuesta.
                        </flux:text>
                    </a>

                    <a href="{{ route('siem.metricas') }}" wire:navigate
                       class="group rounded-xl border border-neutral-200 p-4 transition hover:border-neutral-400 dark:border-neutral-700 dark:hover:border-neutral-500">
                        <flux:icon.presentation-chart-line class="size-5 text-neutral-500" />
                        <flux:heading size="sm" class="mt-2">Métricas del triángulo</flux:heading>
                        <flux:text size="sm" class="mt-1 text-neutral-500">
                            Protección, detección y respuesta, calculadas sobre los datos reales.
                        </flux:text>
                    </a>

                    <a href="{{ route('seo.incidentes') }}" wire:navigate
                       class="group rounded-xl border border-neutral-200 p-4 transition hover:border-neutral-400 dark:border-neutral-700 dark:hover:border-neutral-500">
                        <flux:icon.shield-exclamation class="size-5 text-neutral-500" />
                        <flux:heading size="sm" class="mt-2">Integridad de posicionamiento</flux:heading>
                        <flux:text size="sm" class="mt-1 text-neutral-500">
                            Rastreadores falsificados, contenido no autorizado y redirecciones.
                        </flux:text>
                    </a>
                @endif

            </div>
        </div>

        {{-- Aviso de entorno --}}
        <div class="mt-auto rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:text size="sm" class="text-neutral-500">
                Entorno de demostración académica. No se procesan pagos reales ni se almacenan
                números de tarjeta: los medios de pago se tokenizan y solo se conservan los
                cuatro últimos dígitos.
            </flux:text>
        </div>

    </div>
</x-layouts::app>
