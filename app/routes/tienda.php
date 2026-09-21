<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas de la tienda MarketGT
|--------------------------------------------------------------------------
|
| Archivo propio del componente de comercio electrónico. El integrador lo
| enlaza desde routes/web.php con:
|
|     require __DIR__.'/tienda.php';
|
| El catálogo, la ficha de producto y el carrito son públicos a propósito:
| la demostración del sábado ataca el buscador sin iniciar sesión, igual que
| lo haría un visitante anónimo cualquiera desde Internet.
|
*/

Route::prefix('tienda')->name('tienda.')->group(function () {
    Route::livewire('/', 'pages::tienda.catalogo')->name('catalogo');

    Route::livewire('producto/{slug}', 'pages::tienda.producto')->name('producto');

    Route::livewire('carrito', 'pages::tienda.carrito')->name('carrito');

    Route::livewire('pago', 'pages::tienda.pago')->name('pago');

    // El comprobante se abre con el número público del pedido (MG-2026-K7Q3ZA), que es
    // aleatorio: nadie puede recorrer los pedidos ajenos sumando uno al identificador.
    Route::livewire('comprobante/{numero}', 'pages::tienda.comprobante')->name('comprobante');
});
