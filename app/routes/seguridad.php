<?php

use App\Livewire\Seguridad\SesionesActivas;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Controles de seguridad de la capa 5 (aplicación)
|--------------------------------------------------------------------------
|
| El integrador enlaza este archivo desde routes/web.php con:
|
|     require __DIR__.'/seguridad.php';
|
| 'password.confirm' no es un adorno. Una sesión robada —por una cookie
| filtrada, un equipo prestado o un XSS que se coló entre las reglas del WAF—
| basta para navegar por la tienda, y con eso ya se acepta el riesgo. Lo que no
| puede bastar es para DEBILITAR la cuenta: quitar el segundo factor, borrar una
| passkey, regenerar los códigos de recuperación, cambiar la contraseña o
| expulsar al dueño legítimo de sus propias sesiones. Esas operaciones convierten
| un acceso temporal en un secuestro permanente, así que exigen volver a
| demostrar el conocimiento de la contraseña dentro de la ventana que marca
| AUTH_PASSWORD_TIMEOUT. La sesión dice "alguien sigue aquí"; la reautenticación
| dice "y ese alguien es el dueño".
|
*/

Route::middleware(['auth', 'password.confirm'])
    ->prefix('seguridad')
    ->name('seguridad.')
    ->group(function () {
        Route::get('sesiones', SesionesActivas::class)->name('sesiones');
    });
