<?php

use App\Http\Controllers\Demo\BlancoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Consola de demostración de ataques en vivo (Capa 4 - 5)
|--------------------------------------------------------------------------
|
| Archivo propio del componente de demostración. El integrador lo enlaza desde
| routes/web.php con:
|
|     require __DIR__.'/demo.php';
|
| Depende del alias de middleware 'rol' (App\Http\Middleware\VerificarRol), el
| mismo que usan routes/siem.php y que el integrador registra en bootstrap/app.php.
|
*/

/*
| La CONSOLA vive bajo /demo-waf a propósito: es la ruta que el equipo del WAF dejó
| prevista para evaluarse en modo solo detección con la cabecera secreta (reglas 1006 y
| 1007 del fichero 900), de modo que el propio panel nunca se autobloquea. Exige sesión,
| verificación y rol de administrador: es una herramienta que lanza tráfico real.
*/
Route::middleware(['auth', 'verified', 'rol:admin'])
    ->prefix('demo-waf')
    ->name('demo.')
    ->group(function () {
        // Con nombre explícito: el prefijo de nombre del grupo bautizaría la redirección
        // anónima como una ruta llamada "demo.", una colisión esperando a ocurrir.
        Route::redirect('/', 'demo-waf/consola')->name('inicio');

        Route::livewire('consola', 'pages::demo.consola')->name('consola');
    });

/*
| BLANCOS CONTROLADOS. Son públicos a propósito: el lanzador los golpea de forma anónima,
| exactamente como lo haría un atacante desde Internet, y por eso no pueden exigir sesión.
| El mismo controlador se expone en DOS rutas que solo se diferencian en cómo las trata el
| WAF, y esa diferencia es todo el argumento del modo comparativo:
|
|   - /laboratorio/blanco/{familia}  -> fuera de las exclusiones: WAF plenamente activo.
|                                       El ataque se corta en el borde con 403.
|   - /demo-waf/blanco/{familia}     -> dentro de /demo-waf: con la cabecera secreta el
|                                       motor pasa a solo detección, la petición llega a la
|                                       aplicación y la consulta preparada la neutraliza.
|
| Se excluyen del token CSRF porque son destinos de ataque anónimo: un atacante real jamás
| adjunta el token de sesión de la víctima. Sin esta exclusión, el POST se detendría en 419
| antes de llegar al controlador y no se podría demostrar la defensa de la capa de aplicación.
*/
$blanco = [BlancoController::class, 'recibir'];
$verbos = ['get', 'post', 'put', 'patch', 'delete'];
$sinCsrf = [
    'Illuminate\\Foundation\\Http\\Middleware\\ValidateCsrfToken',
    'Illuminate\\Foundation\\Http\\Middleware\\VerifyCsrfToken',
];

Route::match($verbos, 'laboratorio/blanco/{familia}', $blanco)
    ->withoutMiddleware($sinCsrf)
    ->name('demo.blanco.activo');

Route::match($verbos, 'demo-waf/blanco/{familia}', $blanco)
    ->withoutMiddleware($sinCsrf)
    ->name('demo.blanco.deteccion');
