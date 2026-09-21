<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Guion de demostración')] class extends Component {
    /**
     * Guion de los tres minutos del sábado.
     *
     * Vive en una pantalla del propio panel y no en un documento aparte por una razón
     * práctica: durante la defensa nadie va a abrir un PDF. Aquí está a un clic, en la
     * misma pestaña donde se ven los resultados de cada ataque.
     *
     * @var array<int, array{minuto: string, titulo: string, comando: string, esperado: string, porque: string}>
     */
    public array $actos = [
        [
            'minuto' => '0:00 – 0:20',
            'titulo' => 'Punto de partida',
            'comando' => "# Pantalla izquierda: este panel, pestaña «Integridad»\n# Pantalla derecha: terminal dentro de WSL\n\ncd /mnt/e/Seguridad/app\ntail -f storage/logs/seguridad.log",
            'esperado' => 'Contadores en cero y la línea base sellada, con su huella sha256 y la hora.',
            'porque' => 'Se parte de un sistema limpio y sellado. Sin ese punto de partida, cualquier hallazgo posterior es discutible.',
        ],
        [
            'minuto' => '0:20 – 1:00',
            'titulo' => 'Ataque 1 · Rastreador falsificado',
            'comando' => "curl -i -A 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' \\\n     http://localhost:8000/tienda",
            'esperado' => 'HTTP/1.1 403 Forbidden. En el panel aparece «Rastreador falsificado» con el paso exacto que falló, y en la bitácora una línea JSON con evento seo_crawler_falsificado.',
            'porque' => 'ModSecurity no puede resolver DNS dentro de una regla. La aplicación sí: consulta el PTR de la dirección, comprueba que termine en googlebot.com y resuelve ese nombre de vuelta. Los dos pasos tienen que coincidir.',
        ],
        [
            'minuto' => '1:00 – 1:40',
            'titulo' => 'Ataque 2 · Inyección de enlaces en una reseña',
            'comando' => "# En el panel: pestaña «Laboratorio», sección 1.\n# La carga de ejemplo ya está cargada; pulsar «Intentar publicar».",
            'esperado' => 'Puntuación por encima del umbral 5, la reseña queda retenida y el <script> desaparece del HTML reconstruido. Los enlaces que sobreviven salen con rel="nofollow ugc noopener noreferrer".',
            'porque' => 'El WAF corta lo que entra; solo la aplicación puede decidir que algo no se publica y que un enlace no ceda autoridad. Aunque el spam se colara, con nofollow ugc no le sirve de nada al atacante.',
        ],
        [
            'minuto' => '1:40 – 2:05',
            'titulo' => 'Ataque 3 · Redirección abierta',
            'comando' => "curl -i 'http://localhost:8000/ir?destino=https://sitio-del-atacante.tld'",
            'esperado' => 'HTTP 302 hacia la portada de MarketGT, nunca hacia el dominio del atacante, y un incidente «Redirección abierta bloqueada».',
            'porque' => 'El parámetro no lleva una dirección, lleva una clave de un mapa cerrado. No hay codificación ni doble barra que invente una entrada que no está en el mapa.',
        ],
        [
            'minuto' => '2:05 – 2:45',
            'titulo' => 'Ataque 4 · Envenenamiento de robots.txt',
            'comando' => "# Se simula un atacante que YA entró por otra puerta (SSH, FTP, un contenedor):\nprintf '\\nUser-agent: *\\nDisallow: /\\n' >> public/robots.txt\n\nphp artisan seo:vigilar\n\n# Restaurar después de la demostración:\ngit checkout -- public/robots.txt",
            'esperado' => 'El comando devuelve ALERTA con dos motivos: bloqueo_total y huella_distinta, y registra el incidente crítico. En el panel, «Integridad rota» deja de estar en cero.',
            'porque' => 'Este cambio no viaja por HTTP, así que el WAF no lo ve. Es el vértice de detección del triángulo de ciberresiliencia: se asume que la protección falló y se detecta igual. Cinco minutos frente a varios días de ceguera.',
        ],
        [
            'minuto' => '2:45 – 3:00',
            'titulo' => 'Cierre · Una sola línea de tiempo',
            'comando' => "tail -n 5 storage/logs/seguridad.log | jq -c '{marca_tiempo, evento, ip, detalle: .detalle.regla}'",
            'esperado' => 'Los cuatro ataques, con la misma forma JSON que usa el resto del sistema y con la regla hermana del WAF en cada uno: APP-15021, APP-15031, APP-15040, APP-15050.',
            'porque' => 'El SIEM ingiere este archivo igual que ingiere el registro de auditoría de ModSecurity. Por eso el evento 15021 del WAF y el APP-15021 de la aplicación aparecen en la misma línea de tiempo y se pueden correlacionar.',
        ],
    ];
}; ?>

<x-layouts::app :title="__('Guion de demostración')">
    <x-pages::seo.navegacion
        titulo="Guion de demostración"
        descripcion="Tres minutos, cuatro ataques lanzados contra nuestro propio sitio y la detección en pantalla."
    >
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg">Antes de empezar</flux:heading>
            <flux:subheading>Tres comprobaciones que evitan el ridículo en vivo.</flux:subheading>

            <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-zinc-700 dark:text-zinc-200">
                <li>
                    Las migraciones aplicadas y la línea base sellada:
                    <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">php artisan migrate</code> y después
                    <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">php artisan seo:vigilar --sellar</code>.
                </li>
                <li>
                    La caché de configuración regenerada si se tocó el <code>.env</code>:
                    <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">php artisan config:clear</code>.
                </li>
                <li>
                    Sesión iniciada con una cuenta de administrador, porque el botón de sellar solo
                    aparece para ese rol.
                </li>
            </ol>
        </div>

        <div class="space-y-4">
            @foreach ($actos as $acto)
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <flux:heading size="lg">{{ $acto['titulo'] }}</flux:heading>
                        <span class="rounded bg-zinc-100 px-2 py-0.5 font-mono text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                            {{ $acto['minuto'] }}
                        </span>
                    </div>

                    <div class="space-y-3 p-4">
                        <pre class="overflow-x-auto rounded-lg bg-zinc-900 p-3 text-xs leading-relaxed text-zinc-100">{{ $acto['comando'] }}</pre>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Qué se ve</p>
                                <p class="mt-1 text-sm text-zinc-700 dark:text-zinc-200">{{ $acto['esperado'] }}</p>
                            </div>
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Qué se dice</p>
                                <p class="mt-1 text-sm text-zinc-700 dark:text-zinc-200">{{ $acto['porque'] }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="rounded-xl border border-amber-500/40 bg-amber-500/10 p-4">
            <flux:heading size="lg">Si algo falla en vivo</flux:heading>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-zinc-700 dark:text-zinc-200">
                <li>
                    El ataque 1 responde 200 en vez de 403: el middleware
                    <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">DetectarCloaking</code>
                    no está registrado en <code>bootstrap/app.php</code>. Se enseña el mismo veredicto desde
                    la sección 2 del laboratorio, que no depende del middleware.
                </li>
                <li>
                    El ataque 4 no detecta nada: falta sellar la línea base. Se ejecuta
                    <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">php artisan seo:vigilar --sellar</code>
                    y se repite el ataque.
                </li>
                <li>
                    La bitácora está vacía: falta el canal <code>seguridad</code> en
                    <code>config/logging.php</code>. El componente escribe igual en
                    <code>storage/logs/seguridad.log</code> por su vía de respaldo, así que el archivo debe
                    existir de todos modos.
                </li>
            </ul>
        </div>
    </x-pages::seo.navegacion>
</x-layouts::app>
