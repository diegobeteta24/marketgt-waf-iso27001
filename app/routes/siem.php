<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Centro de monitoreo (SIEM)
|--------------------------------------------------------------------------
|
| El panel expone el detalle de los ataques recibidos: rutas sondeadas, cargas
| utiles y direcciones de origen. Entregarselo a un cliente de la tienda seria
| regalarle al atacante el mapa de lo que el WAF si deja pasar, por eso toda la
| seccion exige sesion iniciada y el rol de auditor o de administrador.
|
| El integrador debe incluir este archivo desde routes/web.php:
|     require __DIR__.'/siem.php';
|
*/

Route::middleware(['auth', 'verified', 'rol:admin,auditor'])
    ->prefix('siem')
    ->name('siem.')
    ->group(function () {
        // Con nombre explicito: un grupo con prefijo de nombre bautiza igualmente las rutas
        // anonimas, y una ruta llamada "siem." es una colision esperando a ocurrir.
        Route::redirect('/', 'siem/tablero')->name('inicio');

        Route::livewire('tablero', 'pages::siem.tablero')->name('tablero');

        Route::livewire('eventos', 'pages::siem.eventos')->name('eventos');

        Route::livewire('alertas', 'pages::siem.alertas')->name('alertas');

        Route::livewire('metricas', 'pages::siem.metricas')->name('metricas');
    });
