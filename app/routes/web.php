<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';

// Tienda de comercio electronico (Capa 5)
require __DIR__.'/tienda.php';

// Panel de monitoreo (vértice de detección del triángulo)
require __DIR__.'/siem.php';

// Integridad de posicionamiento y defensa contra manipulación del SEO
require __DIR__.'/seo.php';

// Controles de seguridad de la cuenta: sesiones activas y registro de auditoría
require __DIR__.'/seguridad.php';

// Consola de demostración de ataques (requiere rol de administrador)
require __DIR__.'/demo.php';
