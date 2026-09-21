<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Controles de seguridad de la capa 5 (aplicación) — MarketGT
|--------------------------------------------------------------------------
|
| Todo lo que un auditor necesita revisar de los controles de aplicación está
| en este archivo. La alternativa era esparcir los umbrales por el código, y
| entonces la pregunta "¿cuántos intentos de inicio de sesión permite el
| sistema?" se contesta leyendo tres clases en vez de una línea. Aquí se
| contesta leyendo una línea, que es como debe poder contestarse en una
| auditoría.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Roles del sistema
    |--------------------------------------------------------------------------
    |
    | Tres roles y ninguno más. La clave es el nombre interno que viaja en el
    | middleware ("rol:administrador"); el valor es la etiqueta que se muestra.
    |
    */

    'roles' => [
        'administrador' => 'Administrador',
        'auditor' => 'Auditor',
        'cliente' => 'Cliente',
    ],

    /*
    | Nombres cortos que otros componentes ya escribieron en sus archivos de
    | rutas (routes/siem.php declara "rol:admin,auditor"). Traducirlos aquí
    | cuesta una línea; obligar a los demás componentes a reescribir sus rutas
    | cuesta una tarde de integración y una demostración rota el sábado.
    */

    'alias_roles' => [
        'admin' => 'administrador',
        'administradora' => 'administrador',
        'auditoria' => 'auditor',
        'clientes' => 'cliente',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cabeceras de seguridad HTTP
    |--------------------------------------------------------------------------
    |
    | Las aplica App\Http\Middleware\CabecerasSeguridad. Qué ataque mitiga cada
    | cabecera está escrito en el middleware, junto a la línea que la emite, y
    | no aquí: el valor y su justificación no deben poder separarse.
    |
    */

    'cabeceras' => [

        'activas' => env('SEGURIDAD_CABECERAS_ACTIVAS', true),

        'politica_contenido' => [

            /*
            | Tres modos:
            |   'aplicar'     → Content-Security-Policy (bloquea de verdad)
            |   'reportar'    → Content-Security-Policy-Report-Only (solo avisa)
            |   'desactivada' → no se emite
            |
            | Sin valor explícito se decide por entorno: en producción se aplica
            | y en local se reporta. Un desarrollador que pelea a diario contra
            | su propia CSP acaba desactivándola en producción, que es justo lo
            | que se quiere evitar.
            */

            'modo' => env('SEGURIDAD_CSP_MODO'),

            /*
            | Destino de los informes de violación. Vacío en la demostración
            | porque no hay recolector montado; en producción apuntaría al SIEM.
            */

            'uri_reporte' => env('SEGURIDAD_CSP_URI_REPORTE'),

            /*
            | Orígenes extra por directiva, para no tener que tocar código
            | cuando entre una pasarela de pago o una fuente tipográfica externa.
            */

            'origenes_adicionales' => [
                'script-src' => [],
                'style-src' => [],
                'img-src' => [],
                'connect-src' => [],
                'font-src' => [],
                'frame-src' => [],
            ],

            /*
            | Alpine.js —que viaja dentro de Livewire— compila las expresiones
            | de x-data y x-on con "new Function", y eso obliga a incluir
            | 'unsafe-eval' en script-src. Existe una compilación de Alpine apta
            | para CSP, pero Livewire 4 no la usa. El interruptor se deja
            | documentado en vez de fingir que la restricción no existe: quien
            | lo apague sabrá que la interfaz deja de responder.
            */

            'permitir_eval' => env('SEGURIDAD_CSP_PERMITIR_EVAL', true),

            /*
            | Servidor de desarrollo de Vite. Solo se añade en entorno local.
            */

            'origenes_vite' => [
                'http://localhost:5173',
                'http://127.0.0.1:5173',
                'ws://localhost:5173',
                'ws://127.0.0.1:5173',
            ],
        ],

        /*
        | HSTS: un año, subdominios incluidos y apto para la lista de precarga
        | que traen los navegadores de fábrica.
        */

        'hsts' => [
            'edad_maxima' => 31536000,
            'incluir_subdominios' => true,
            'precarga' => true,
        ],

        'referrer_policy' => 'strict-origin-when-cross-origin',

        'x_frame_options' => 'DENY',

        'cross_origin_opener_policy' => 'same-origin',

        'cross_origin_resource_policy' => 'same-origin',

        /*
        | Permissions-Policy: se apaga todo lo que la tienda no usa. Lo que no
        | se enciende no se puede secuestrar desde un marco inyectado.
        */

        'permissions_policy' => [
            'accelerometer' => '()',
            'autoplay' => '()',
            'camera' => '()',
            'display-capture' => '()',
            'encrypted-media' => '()',
            'geolocation' => '()',
            'gyroscope' => '()',
            'magnetometer' => '()',
            'microphone' => '()',
            'midi' => '()',
            'payment' => '()',
            'usb' => '()',
            // WebAuthn: si se cierra, las passkeys de Fortify dejan de registrarse.
            'publickey-credentials-get' => '(self)',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Limitación de intentos
    |--------------------------------------------------------------------------
    |
    | Umbrales conforme a NIST SP 800-63B, apartado 5.2.2 (limitación de tasa de
    | los autenticadores): la guía exige limitar los intentos fallidos
    | consecutivos a no más de 100 por cuenta y recomienda retardo progresivo o
    | bloqueo temporal antes de llegar a ese techo. Los valores de abajo quedan
    | muy por debajo de ese techo porque además hay que contener el relleno de
    | credenciales distribuido, no solo la fuerza bruta contra una sola cuenta.
    |
    */

    'limitadores' => [

        // Por cuenta: cinco intentos por minuto. Quien recuerda su contraseña
        // necesita uno; quien duda, tres.
        'acceso_por_correo' => ['intentos' => 5, 'minutos' => 1],

        // Por dirección IP: más holgado a propósito. Una casa, una oficina o un
        // laboratorio de la universidad comparten IP tras NAT, y un umbral
        // estrecho aquí deja fuera a un aula entera por culpa de un compañero.
        'acceso_por_ip' => ['intentos' => 20, 'minutos' => 1],

        // Segundo factor: el código TOTP tiene seis dígitos y vive 30 segundos.
        // Con cinco intentos por minuto, acertarlo al azar exige del orden de
        // 10^5 minutos de intentos: la fuerza bruta deja de ser una opción.
        'segundo_factor' => ['intentos' => 5, 'minutos' => 1],

        // Passkeys: el desafío WebAuthn no se adivina. El límite existe para que
        // nadie use el punto final como generador de carga.
        'passkeys' => ['intentos' => 10, 'minutos' => 1],

        // Registro: contiene la creación masiva de cuentas para inyectar enlaces
        // en los perfiles, uno de los ataques de posicionamiento que vigilan las
        // reglas propias 15000-15099 del WAF.
        'registro' => ['intentos' => 5, 'minutos' => 10],

        // Restablecimiento: cada intento envía un correo. El límite protege la
        // reputación del servidor de correo tanto como a la cuenta.
        'restablecer_contrasena' => ['intentos' => 3, 'minutos' => 10],

        // Confirmación de contraseña previa a las operaciones sensibles.
        'reautenticacion' => ['intentos' => 5, 'minutos' => 1],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bitácora de seguridad
    |--------------------------------------------------------------------------
    |
    | Un objeto JSON por línea en storage/logs/seguridad.log. El panel SIEM lo
    | lee con la misma rutina con la que lee el registro de auditoría de
    | ModSecurity; por eso el formato es JSON plano y no el formato de texto de
    | Laravel. Un registro que hay que desarmar con expresiones regulares es un
    | registro que tarde o temprano se deja de revisar.
    |
    */

    'bitacora' => [
        'canal' => env('SEGURIDAD_CANAL_BITACORA', 'seguridad'),
        'limite_agente_usuario' => 512,
    ],

    /*
    |--------------------------------------------------------------------------
    | Registro de acciones sensibles (tabla registros_auditoria)
    |--------------------------------------------------------------------------
    */

    'auditoria' => [

        // Solo se auditan los métodos que cambian estado. Registrar cada GET
        // llenaría la tabla de ruido y escondería lo que sí importa.
        'metodos' => ['POST', 'PUT', 'PATCH', 'DELETE'],

        // Rutas que no aportan nada a una auditoría y sí mucho volumen.
        'rutas_ignoradas' => [
            'up',
            'livewire/update',
            'livewire/livewire.js',
            'livewire/livewire.min.js*',
            'flux/*',
            'build/*',
            '_debugbar/*',
        ],

        // Nunca deben llegar a la base de datos, ni siquiera cifrados: no hacen
        // falta para auditar y su sola presencia convierte la tabla en objetivo.
        'campos_censurados' => [
            'password',
            'password_confirmation',
            'current_password',
            'contrasena',
            'contrasena_actual',
            'code',
            'codigo',
            'recovery_code',
            'token',
            '_token',
            'two_factor_secret',
            'credential',
            'numero_tarjeta',
            'cvv',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sesiones
    |--------------------------------------------------------------------------
    */

    'sesiones' => [
        // El controlador de sesión debe ser "database" para poder enumerarlas y
        // cerrarlas; con "file" o "cookie" la pantalla de sesiones activas no
        // tiene de dónde leer. La pantalla lo comprueba y lo dice, en vez de
        // mostrar una lista vacía que parecería significar "no hay sesiones".
        'tabla' => env('SESSION_TABLE', 'sessions'),
        'maximo_listado' => 25,
    ],
];
