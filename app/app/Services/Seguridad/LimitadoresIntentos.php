<?php

declare(strict_types=1);

namespace App\Services\Seguridad;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limitadores de intentos con nombre.
 *
 * Los umbrales están en config/seguridad.php con su justificación; aquí solo se
 * decide cómo se agrupa cada cuenta de intentos. La referencia es NIST SP 800-63B
 * apartado 5.2.2: limitar los intentos fallidos consecutivos por cuenta y aplicar
 * retardo o bloqueo temporal, en lugar del bloqueo permanente de la cuenta —el
 * bloqueo permanente convierte cualquier intento de fuerza bruta en una negación
 * de servicio contra la víctima, que es justo lo que busca el atacante.
 *
 * Todos los limitadores cuentan por dirección IP real. Eso depende de que los
 * proxies de confianza estén declarados en bootstrap/app.php: detrás de Nginx y
 * Cloudflare, sin esa configuración, todas las peticiones llegan con la IP del
 * contenedor y los cinco intentos por minuto se reparten entre todo Internet.
 */
class LimitadoresIntentos
{
    public static function registrar(): void
    {
        self::acceso();
        self::segundoFactor();
        self::passkeys();
        self::registro();
        self::restablecimiento();
        self::reautenticacion();
        self::sensibles();
    }

    /**
     * Inicio de sesión: dos cuentas simultáneas, por correo y por IP.
     *
     * Con una sola clave "correo|ip" —el valor por omisión de Fortify— basta con
     * rotar la IP para volver a tener cinco intentos contra el mismo correo, que
     * es exactamente lo que hace una botnet de relleno de credenciales. Separadas,
     * la cuenta por correo frena la fuerza bruta contra una víctima concreta y la
     * cuenta por IP frena el barrido de muchas cuentas desde un mismo origen.
     */
    private static function acceso(): void
    {
        RateLimiter::for('login', function (Request $peticion): array {
            $porCorreo = self::umbral('acceso_por_correo');
            $porIp = self::umbral('acceso_por_ip');

            $correo = Str::transliterate(Str::lower((string) $peticion->input(Fortify::username())));

            return [
                Limit::perMinutes($porCorreo['minutos'], $porCorreo['intentos'])->by('acceso:correo:'.$correo),
                Limit::perMinutes($porIp['minutos'], $porIp['intentos'])->by('acceso:ip:'.$peticion->ip()),
            ];
        });
    }

    /**
     * Segundo factor. Se cuenta por el identificador del usuario que quedó a medio
     * autenticar en la sesión, no por el correo: en este punto la contraseña ya se
     * validó y quien ataca es alguien que la tiene.
     */
    private static function segundoFactor(): void
    {
        RateLimiter::for('two-factor', function (Request $peticion): Limit {
            $umbral = self::umbral('segundo_factor');

            $identificador = $peticion->session()->get('login.id') ?? $peticion->ip();

            return Limit::perMinutes($umbral['minutos'], $umbral['intentos'])
                ->by('segundo-factor:'.$identificador);
        });
    }

    private static function passkeys(): void
    {
        RateLimiter::for('passkeys', function (Request $peticion): Limit {
            $umbral = self::umbral('passkeys');

            $credencial = (string) $peticion->input('credential.id');

            return Limit::perMinutes($umbral['minutos'], $umbral['intentos'])->by(
                'passkey:'.($credencial !== '' ? $credencial : $peticion->session()->getId()).'|'.$peticion->ip(),
            );
        });
    }

    private static function registro(): void
    {
        RateLimiter::for('registro', function (Request $peticion): Limit {
            $umbral = self::umbral('registro');

            return Limit::perMinutes($umbral['minutos'], $umbral['intentos'])
                ->by('registro:'.$peticion->ip())
                ->response(fn (): Response => response(
                    'Demasiadas cuentas creadas desde esta dirección. Intente de nuevo más tarde.',
                    429,
                ));
        });
    }

    /**
     * Restablecimiento de contraseña. Se cuenta por correo y por IP a la vez, con
     * la misma lógica que el inicio de sesión: sin la cuenta por correo, rotar la
     * IP permite inundar de correos el buzón de una víctima concreta.
     */
    private static function restablecimiento(): void
    {
        RateLimiter::for('restablecer-contrasena', function (Request $peticion): array {
            $umbral = self::umbral('restablecer_contrasena');

            $correo = Str::transliterate(Str::lower((string) $peticion->input('email')));

            return [
                Limit::perMinutes($umbral['minutos'], $umbral['intentos'])->by('restablecer:correo:'.$correo),
                Limit::perMinutes($umbral['minutos'], $umbral['intentos'] * 2)->by('restablecer:ip:'.$peticion->ip()),
            ];
        });
    }

    /**
     * Confirmación de contraseña previa a una operación sensible. El atacante que
     * llega aquí ya tiene la sesión de la víctima; lo único que le falta es la
     * contraseña, así que el punto final es tan atacable como el inicio de sesión.
     */
    private static function reautenticacion(): void
    {
        RateLimiter::for('reautenticacion', function (Request $peticion): Limit {
            $umbral = self::umbral('reautenticacion');

            $usuario = $peticion->user();

            return Limit::perMinutes($umbral['minutos'], $umbral['intentos'])
                ->by('reautenticacion:'.($usuario?->getAuthIdentifier() ?? $peticion->ip()));
        });
    }

    /**
     * Limitador despachador para las rutas que Fortify registra sin posibilidad de
     * pasarles un limitador propio (registro, olvido de contraseña, confirmación).
     *
     * Se aplica al grupo "web" entero y devuelve Limit::none() en todo lo que no
     * sea una de esas rutas. Parece un rodeo, pero la alternativa era desactivar
     * las rutas de Fortify y volver a declararlas a mano solo para colgarles un
     * "throttle:", lo que significaría mantener nosotros el código de
     * autenticación que el paquete ya mantiene, actualización tras actualización.
     */
    private static function sensibles(): void
    {
        RateLimiter::for('sensibles', function (Request $peticion) {
            if (! $peticion->isMethod('POST')) {
                return Limit::none();
            }

            return match (true) {
                $peticion->is('register') => RateLimiter::limiter('registro')($peticion),
                $peticion->is('forgot-password'), $peticion->is('reset-password') => RateLimiter::limiter('restablecer-contrasena')($peticion),
                $peticion->is('user/confirm-password') => RateLimiter::limiter('reautenticacion')($peticion),
                default => Limit::none(),
            };
        });
    }

    /**
     * @return array{intentos: int, minutos: int}
     */
    private static function umbral(string $nombre): array
    {
        /** @var array{intentos?: int, minutos?: int} $umbral */
        $umbral = config('seguridad.limitadores.'.$nombre, []);

        return [
            'intentos' => (int) ($umbral['intentos'] ?? 5),
            'minutos' => max(1, (int) ($umbral['minutos'] ?? 1)),
        ];
    }
}
