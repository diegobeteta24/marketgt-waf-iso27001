# Laravel detrás del WAF — lo que hay que tocar en la aplicación

Sin estos cuatro puntos, la app **arranca pero se comporta mal** detrás del proxy:
sesiones que se pierden, enlaces en `http://`, IPs de todos los usuarios iguales
y el rate limit de Laravel contando a todo el mundo como un solo cliente.

## 1. Confiar en el proxy (obligatorio)

`bootstrap/app.php` (Laravel 11, 12 y 13):

```php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withMiddleware(function (Middleware $middleware) {
        // El WAF es el único que habla con Laravel y vive en la red docker
        // privada, así que confiar en ese rango es seguro. NO uses '*' salvo
        // que el contenedor sea inalcanzable desde fuera (aquí lo es: `expose`,
        // no `ports`, y la red `appnet` es internal).
        $middleware->trustProxies(
            at: ['172.16.0.0/12', '10.0.0.0/8'],
            headers: Request::HEADER_X_FORWARDED_FOR
                   | Request::HEADER_X_FORWARDED_HOST
                   | Request::HEADER_X_FORWARDED_PORT
                   | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->create();
```

La imagen del WAF ya envía `X-Forwarded-For`, `X-Forwarded-Proto`,
`X-Forwarded-Port` y `X-REAL-IP` (plantilla `includes/proxy_backend.conf`).
Con `REAL_IP_HEADER=CF-Connecting-IP` en el compose, el `X-Forwarded-For` que
recibe Laravel ya trae la IP real del visitante y no la de Cloudflare.

## 2. Forzar HTTPS en las URLs generadas

`app/Providers/AppServiceProvider.php`:

```php
public function boot(): void
{
    if (app()->environment('production')) {
        \Illuminate\Support\Facades\URL::forceScheme('https');
    }
}
```

Y en `.env`: `APP_URL=https://marketgt.tudominio.com`, `SESSION_SECURE_COOKIE=true`.

## 3. Cabeceras CSP y HSTS (Capa 5 del proyecto)

Ponlas **en Laravel**, no en el WAF: así siguen a la aplicación aunque cambie
el proxy, y quedan bajo control de versiones junto al código.

```php
// app/Http/Middleware/CabecerasDeSeguridad.php
public function handle($request, Closure $next)
{
    $response = $next($request);

    $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
    $response->headers->set('Content-Security-Policy',
        "default-src 'self'; script-src 'self'; style-src 'self'; ".
        "img-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->headers->set('Permissions-Policy', 'geolocation=(), camera=(), microphone=()');

    return $response;
}
```

> Cuidado: si usas Vite en modo desarrollo, `script-src 'self'` rompe el HMR.
> En producción (`npm run build`) no hay problema.

## 4. Tamaños y tiempos: que coincidan en las tres capas

Si no coinciden, una subida de imagen falla con un error distinto en cada capa
y se pierde media hora buscándolo:

| Capa | Ajuste | Valor propuesto |
|---|---|---|
| WAF (nginx) | `client_max_body_size` | ya es `0` (sin límite) en la plantilla de la imagen |
| WAF (ModSecurity) | `MODSEC_REQ_BODY_LIMIT` | `26214400` (25 MB) |
| WAF (ModSecurity) | `MODSEC_REQ_BODY_NOFILES_LIMIT` | `1048576` (1 MB de formulario) |
| WAF (CRS) | `MAX_FILE_SIZE` / `COMBINED_FILE_SIZES` | `8388608` / `25165824` |
| App (nginx interno) | `NGINX_CLIENT_MAX_BODY_SIZE` | `25M` |
| App (PHP) | `PHP_UPLOAD_MAX_FILE_SIZE`, `PHP_POST_MAX_SIZE` | `25M` |
| App (Laravel) | regla `max:8192` en el FormRequest | 8 MB |

Tiempos: `PROXY_TIMEOUT=120s` en el WAF debe ser **mayor** que
`PHP_FPM_REQUEST_TERMINATE_TIMEOUT` de la app, si no verás 504 del proxy en vez
del error real de PHP.

## 5. Ficheros estáticos y /storage

El WAF hace `proxy_pass` de **todo**, incluidos CSS, JS e imágenes: no sirve
nada de su propio disco. Eso es lo correcto aquí — quien decide qué es estático
es el nginx interno de la app, con su `try_files`.

- `php artisan storage:link` tiene que ejecutarse **dentro** del contenedor de
  la app (o en el `Dockerfile.app`), no en el WAF.
- Las imágenes subidas se sirven desde `/storage/...`, que el CRS trata como
  extensión estática (`STATIC_EXTENSIONS`) y por tanto con menos inspección.
- Cloudflare cachea esas rutas; en la demo, purga la caché antes de enseñar un
  cambio o parecerá que el despliegue no funcionó.
