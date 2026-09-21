<?php

use App\Services\Seo\GeneradorSitemap;
use App\Services\Seo\RedireccionSegura;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Defensa contra ataques de posicionamiento (capa 5)
|--------------------------------------------------------------------------
|
| El integrador enlaza este archivo desde routes/web.php con:
|
|     require __DIR__.'/seo.php';
|
| Dos secciones con público distinto y a propósito:
|
|   - Lo PÚBLICO (sitemap.xml y la redirección con lista blanca) tiene que ser
|     accesible sin sesión: quien lo consume es Googlebot, que nunca inicia sesión.
|   - El PANEL de integridad exige sesión y rol, igual que el SIEM. Enseña
|     direcciones de atacantes, cargas de spam y qué detecta cada regla; dárselo a
|     un cliente de la tienda es entregarle al atacante el mapa de lo que se mira y
|     lo que no.
|
*/

// -----------------------------------------------------------------------------
// Sitemap generado en memoria
// -----------------------------------------------------------------------------
// No existe como archivo en public/. Un sitemap.xml en disco es un archivo que se
// puede reescribir: quien consiga escritura le entrega a Google una lista de
// direcciones del atacante alojadas bajo el dominio de MarketGT. Generado desde el
// catálogo, para envenenarlo habría que envenenar antes la base de datos, y eso
// deja rastro en otra capa.
Route::get('sitemap.xml', function (GeneradorSitemap $generador) {
    $xml = Cache::remember(
        'seo:sitemap',
        now()->addMinutes(GeneradorSitemap::MINUTOS_CACHE),
        static fn (): string => $generador->generar(),
    );

    return response($xml, 200, [
        'Content-Type' => 'application/xml; charset=UTF-8',
        'Cache-Control' => 'public, max-age=1800',
    ]);
})->name('seo.sitemap');

// -----------------------------------------------------------------------------
// Redirección con lista blanca
// -----------------------------------------------------------------------------
// /ir?destino=sat  ->  https://portal.sat.gob.gt/portal/
//
// El parámetro NO lleva una dirección, lleva una CLAVE de un mapa cerrado. Es lo
// que cierra la redirección abierta de raíz: no hay codificación, doble barra ni
// truco de unicode que invente una entrada que no está en el mapa. Cada intento
// con algo que no es una clave queda registrado como incidente.
Route::get('ir', function (Request $peticion, RedireccionSegura $redireccion) {
    $destino = $redireccion->resolver(
        $peticion->query('destino') ?? $peticion->query('ir'),
        url('/'),
        [
            'ip' => $peticion->ip(),
            'agente_usuario' => $peticion->userAgent(),
            'ruta' => $peticion->fullUrl(),
            'usuario_id' => $peticion->user()?->getAuthIdentifier(),
        ],
    );

    // 302 y no 301: una redirección permanente se queda cacheada en el navegador y
    // en los intermediarios, y un error de configuración se volvería difícil de
    // revertir. Además se marca noindex para que ningún buscador guarde esta URL.
    return redirect()->away($destino, 302)
        ->header('X-Robots-Tag', 'noindex, nofollow');
})->middleware('throttle:30,1')->name('seo.ir');
// El límite no protege la lista blanca —esa no se puede forzar—, protege la BITÁCORA.
// Cada clave inválida escribe una línea JSON en storage/logs/seguridad.log, y esta ruta es
// pública y sin sesión: un bucle de curl llenaba el disco y ahogaba al SIEM en ruido propio
// mientras el ataque de verdad pasaba desapercibido entre un millón de líneas iguales. El
// incidente en la tabla sí está agregado por huella; el archivo no lo está.

// -----------------------------------------------------------------------------
// Panel de integridad de posicionamiento
// -----------------------------------------------------------------------------
Route::middleware(['auth', 'verified', 'rol:administrador,auditor'])
    ->prefix('seo')
    ->name('seo.')
    ->group(function () {
        // Con nombre explícito: un grupo con prefijo de nombre bautizaría igualmente la
        // ruta anónima, y una ruta llamada "seo." es una colisión esperando a ocurrir.
        Route::redirect('/', 'seo/integridad')->name('inicio');

        Route::livewire('integridad', 'pages::seo.integridad')->name('integridad');

        Route::livewire('incidentes', 'pages::seo.incidentes')->name('incidentes');

        Route::livewire('laboratorio', 'pages::seo.laboratorio')->name('laboratorio');

        Route::livewire('demostracion', 'pages::seo.demostracion')->name('demostracion');
    });
