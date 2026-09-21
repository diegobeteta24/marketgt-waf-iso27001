<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        /*
        |------------------------------------------------------------------
        | Confianza en los proxies
        |------------------------------------------------------------------
        |
        | La aplicación vive detrás del cortafuegos de aplicación, que actúa
        | como proxy inverso. Sin declarar los rangos de la red de contenedores
        | como confiables ocurren tres cosas, todas silenciosas:
        |
        |   · El limitador de tasa cuenta a todos los visitantes como uno solo,
        |     porque ve siempre la dirección del proxy. El control de fuerza
        |     bruta quedaría anulado.
        |   · Las direcciones se generan con esquema http, lo que rompe el
        |     registro de passkeys: WebAuthn exige un origen seguro.
        |   · El panel de detección registraría al proxy como origen de todos
        |     los ataques, dejando el módulo sin ningún valor forense.
        |
        | Se incluye la cabecera de puerto además de las habituales. Sin ella
        | la aplicación deduce el puerto interno del contenedor y las
        | redirecciones del segundo factor salen apuntando a ese puerto, lo que
        | rompe tanto el acceso con contraseña de un solo uso como con passkey.
        |
        */
        $middleware->trustProxies(
            at: [
                '172.16.0.0/12',   // red interna de contenedores
                '10.0.0.0/8',      // red privada del proveedor
                '192.168.0.0/16',
            ],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        /*
        |------------------------------------------------------------------
        | Alias de middleware
        |------------------------------------------------------------------
        |
        | «rol» acepta varios roles separados por coma, de modo que una ruta
        | puede declararse como rol:admin,auditor. La comprobación es siempre
        | del lado del servidor: que el menú oculte un enlace no es un control
        | de acceso, solo evita que el usuario tropiece con pantallas negadas.
        |
        */
        $middleware->alias([
            'rol'       => \App\Http\Middleware\VerificarRol::class,
            'auditoria' => \App\Http\Middleware\RegistrarAuditoria::class,
        ]);

        /*
        |------------------------------------------------------------------
        | Middleware aplicado a todas las peticiones web
        |------------------------------------------------------------------
        |
        | Las cabeceras de seguridad se aplican a toda respuesta, no solo a las
        | rutas autenticadas: una política de contenido que solo protege el
        | área privada deja expuesta la tienda, que es justamente la superficie
        | pública y el blanco de la inyección de contenido.
        |
        | La detección de suplantación de rastreadores va también en el grupo
        | web porque el fraude de posicionamiento ocurre sobre las páginas
        | públicas del catálogo, que es donde un rastreador falsificado busca
        | inyectar o extraer contenido.
        |
        */
        $middleware->web(append: [
            \App\Http\Middleware\CabecerasSeguridad::class,
            \App\Http\Middleware\DetectarCloaking::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
