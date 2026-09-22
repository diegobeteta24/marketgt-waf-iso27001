<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Tareas periódicas del vértice de detección
|--------------------------------------------------------------------------
|
| Sin esta programación el panel de monitoreo no recibe un solo evento: los
| comandos existen, pero nadie los invoca. Es el fallo clásico de un sistema
| de detección, y tiene la particularidad de no parecerse a un fallo — el
| panel simplemente muestra cero, y cero se lee igual que «no pasa nada».
|
| Por eso el propio panel vigila la antigüedad del último evento ingerido y
| avisa cuando la ingesta lleva demasiado tiempo detenida. Un sistema de
| detección que no comprueba que sigue recibiendo datos solo sirve mientras
| nadie lo necesita.
|
*/

// ─── Ingesta del registro de auditoría del cortafuegos ───────────────────────
//
// Cada minuto. La lectura es incremental —recuerda el desplazamiento donde se
// quedó— de modo que una pasada sin novedades cuesta prácticamente nada.
//
// withoutOverlapping evita que dos pasadas coincidan si una se alarga tras una
// ráfaga de ataques, porque procesarían las mismas líneas dos veces y el panel
// mostraría eventos duplicados.
//
// runInBackground impide que una ingesta lenta retrase a la correlación, que
// es la que genera las alertas.
Schedule::command('siem:ingerir-waf')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/siem-ingesta.log'));

// ─── Motor de correlación ────────────────────────────────────────────────────
//
// Cada dos minutos, desfasado respecto a la ingesta para no competir por la
// base de datos. Las reglas trabajan sobre ventanas de varios minutos, así que
// ejecutarlas con más frecuencia no adelantaría ninguna detección.
//
// El objetivo declarado del proyecto es un tiempo medio de detección menor o
// igual a treinta minutos; con este intervalo el margen es amplio.
Schedule::command('siem:correlacionar')
    ->everyTwoMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/siem-correlacion.log'));

// ─── Vigilancia de integridad del posicionamiento ────────────────────────────
//
// Cada hora. Compara la huella de robots.txt, del mapa del sitio y de las
// etiquetas canónicas contra la línea base autorizada.
//
// Una frecuencia horaria basta porque lo que se busca es un cambio no
// autorizado y persistente, no una ráfaga. Un envenenamiento de posicionamiento
// necesita permanecer para que el buscador lo indexe: detectarlo en una hora
// llega muy a tiempo.
Schedule::command('seo:vigilar')
    ->hourly()
    ->withoutOverlapping(30)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/seo-vigilancia.log'));
