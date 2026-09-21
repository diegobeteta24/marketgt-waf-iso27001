<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\RegistroAuditoria;
use App\Models\User;
use App\Services\Seguridad\Auditor;
use App\Services\Seguridad\BitacoraSeguridad;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;

/**
 * Escucha los eventos de autenticación y los deja escritos.
 *
 * Es un suscriptor y no un montón de escuchadores sueltos porque todos estos
 * eventos cuentan la misma historia —quién entró, quién lo intentó y qué tocó de
 * su cuenta— y leerla repartida en quince archivos hace imposible comprobar que
 * no falta ninguno.
 *
 * Reparto deliberado entre los dos destinos:
 *   - Bitácora JSON (storage/logs/seguridad.log): TODOS los eventos. Es el flujo
 *     que consume el SIEM y donde se detecta el patrón —cien fallos desde una IP
 *     en dos minutos— que ningún evento aislado revela.
 *   - Tabla registros_auditoria: solo lo que cambia el estado de seguridad de una
 *     cuenta. Un fallo de contraseña no va a la tabla; activar o desactivar el
 *     segundo factor, sí.
 */
class RegistrarEventosAutenticacion
{
    public function __construct(
        private readonly BitacoraSeguridad $bitacora,
        private readonly Auditor $auditor,
    ) {}

    public function subscribe(Dispatcher $eventos): array
    {
        return [
            Login::class => 'alIniciarSesion',
            Failed::class => 'alFallarInicioSesion',
            Logout::class => 'alCerrarSesion',
            Lockout::class => 'alBloquearPorIntentos',
            Registered::class => 'alRegistrarse',
            Verified::class => 'alVerificarCorreo',
            PasswordReset::class => 'alRestablecerContrasena',
            PasswordResetLinkSent::class => 'alEnviarEnlaceRestablecimiento',
            CurrentDeviceLogout::class => 'alCerrarDispositivoActual',
            OtherDeviceLogout::class => 'alCerrarOtrosDispositivos',

            TwoFactorAuthenticationEnabled::class => 'alActivarSegundoFactor',
            TwoFactorAuthenticationConfirmed::class => 'alConfirmarSegundoFactor',
            TwoFactorAuthenticationDisabled::class => 'alDesactivarSegundoFactor',
            TwoFactorAuthenticationChallenged::class => 'alExigirSegundoFactor',
            TwoFactorAuthenticationFailed::class => 'alFallarSegundoFactor',
            ValidTwoFactorAuthenticationCodeProvided::class => 'alAcertarSegundoFactor',
            RecoveryCodeReplaced::class => 'alUsarCodigoRecuperacion',
            RecoveryCodesGenerated::class => 'alRegenerarCodigosRecuperacion',
        ];
    }

    public function alIniciarSesion(Login $evento): void
    {
        $usuario = $evento->user;

        $this->bitacora->registrar('inicio_sesion', [
            'usuario_id' => $usuario->getAuthIdentifier(),
            'correo' => $usuario->getAttribute('email'),
            'detalle' => [
                'guardia' => $evento->guard,
                'recordarme' => $evento->remember,
            ],
        ]);

        $this->auditor->registrar('inicio_sesion', [
            'usuario_id' => $usuario->getAuthIdentifier(),
            'correo' => $usuario->getAttribute('email'),
        ]);

        // Sello del último acceso. Se guarda sin tocar updated_at para que la
        // columna siga contando cambios reales del perfil y no cada visita.
        if ($usuario instanceof User) {
            $usuario->forceFill([
                'ultimo_acceso_en' => now(),
                'ultima_ip_acceso' => request()->ip(),
            ])->saveQuietly();
        }
    }

    /**
     * Fallo de credenciales. Se registra el correo tecleado pero jamás la
     * contraseña: quien se equivoca de campo y escribe su contraseña en la casilla
     * del correo no merece que el sistema la archive en claro para siempre.
     */
    public function alFallarInicioSesion(Failed $evento): void
    {
        $correo = $evento->credentials['email'] ?? null;

        $this->bitacora->registrar('inicio_sesion_fallido', [
            'usuario_id' => $evento->user?->getAuthIdentifier(),
            'correo' => is_string($correo) ? $correo : null,
            'nivel' => 'warning',
            'detalle' => [
                'guardia' => $evento->guard,
                // Distingue "la cuenta no existe" de "la contraseña no coincide".
                // El sistema no se lo dice al atacante en pantalla —eso permitiría
                // enumerar usuarios— pero sí lo deja escrito para el analista.
                'cuenta_existe' => $evento->user !== null,
            ],
        ]);
    }

    public function alCerrarSesion(Logout $evento): void
    {
        $this->bitacora->registrar('cierre_sesion', [
            'usuario_id' => $evento->user?->getAuthIdentifier(),
            'correo' => $evento->user?->getAttribute('email'),
            'detalle' => ['guardia' => $evento->guard],
        ]);
    }

    /**
     * Se alcanzó el límite de intentos. Es el evento que dispara la alerta en el
     * SIEM: un bloqueo aislado es alguien que olvidó su contraseña, veinte
     * bloqueos en un minuto son un ataque de relleno de credenciales.
     */
    public function alBloquearPorIntentos(Lockout $evento): void
    {
        $this->bitacora->registrar('bloqueo_por_intentos', [
            'correo' => (string) $evento->request->input('email'),
            'ip' => $evento->request->ip(),
            'nivel' => 'warning',
            'detalle' => ['ruta' => $evento->request->path()],
        ]);

        $this->auditor->registrar('bloqueo_por_intentos', [
            'resultado' => RegistroAuditoria::DENEGADO,
            'correo' => (string) $evento->request->input('email'),
        ]);
    }

    public function alRegistrarse(Registered $evento): void
    {
        $this->bitacora->registrar('cuenta_registrada', [
            'usuario_id' => $evento->user->getAuthIdentifier(),
            'correo' => $evento->user->getAttribute('email'),
        ]);

        $this->auditor->registrar('cuenta_registrada', [
            'usuario_id' => $evento->user->getAuthIdentifier(),
            'correo' => $evento->user->getAttribute('email'),
            'tipo_recurso' => 'usuario',
            'identificador_recurso' => $evento->user->getAuthIdentifier(),
        ]);
    }

    public function alVerificarCorreo(Verified $evento): void
    {
        $this->bitacora->registrar('correo_verificado', [
            'usuario_id' => $evento->user->getAuthIdentifier(),
            'correo' => $evento->user->getAttribute('email'),
        ]);
    }

    public function alRestablecerContrasena(PasswordReset $evento): void
    {
        $this->bitacora->registrar('contrasena_restablecida', [
            'usuario_id' => $evento->user->getAuthIdentifier(),
            'correo' => $evento->user->getAttribute('email'),
            'nivel' => 'notice',
        ]);

        $this->auditor->registrar('contrasena_restablecida', [
            'usuario_id' => $evento->user->getAuthIdentifier(),
            'correo' => $evento->user->getAttribute('email'),
        ]);
    }

    public function alEnviarEnlaceRestablecimiento(PasswordResetLinkSent $evento): void
    {
        $this->bitacora->registrar('enlace_restablecimiento_enviado', [
            'correo' => $evento->user->getAttribute('email'),
        ]);
    }

    public function alCerrarDispositivoActual(CurrentDeviceLogout $evento): void
    {
        $this->bitacora->registrar('cierre_dispositivo_actual', [
            'usuario_id' => $evento->user?->getAuthIdentifier(),
            'correo' => $evento->user?->getAttribute('email'),
        ]);
    }

    public function alCerrarOtrosDispositivos(OtherDeviceLogout $evento): void
    {
        $this->bitacora->registrar('cierre_otros_dispositivos', [
            'usuario_id' => $evento->user?->getAuthIdentifier(),
            'correo' => $evento->user?->getAttribute('email'),
            'nivel' => 'notice',
        ]);

        $this->auditor->registrar('sesiones_cerradas', [
            'usuario_id' => $evento->user?->getAuthIdentifier(),
            'correo' => $evento->user?->getAttribute('email'),
        ]);
    }

    public function alActivarSegundoFactor(TwoFactorAuthenticationEnabled $evento): void
    {
        $this->registrarCambioDeCuenta('segundo_factor_activado', $evento->user, 'notice');
    }

    public function alConfirmarSegundoFactor(TwoFactorAuthenticationConfirmed $evento): void
    {
        $this->registrarCambioDeCuenta('segundo_factor_confirmado', $evento->user, 'notice');
    }

    /**
     * Desactivar el segundo factor debilita la cuenta, así que se registra con
     * nivel de aviso: si el dueño no lo hizo, esta línea es la prueba del robo.
     */
    public function alDesactivarSegundoFactor(TwoFactorAuthenticationDisabled $evento): void
    {
        $this->registrarCambioDeCuenta('segundo_factor_desactivado', $evento->user, 'warning');
    }

    public function alExigirSegundoFactor(TwoFactorAuthenticationChallenged $evento): void
    {
        $this->bitacora->registrar('segundo_factor_exigido', [
            'usuario_id' => $evento->user->getAuthIdentifier(),
            'correo' => $evento->user->getAttribute('email'),
        ]);
    }

    /**
     * Código del segundo factor incorrecto. Es el evento más valioso de todos:
     * significa que alguien ya tiene la contraseña correcta y solo le falta el
     * teléfono. Merece aviso aunque ocurra una sola vez.
     */
    public function alFallarSegundoFactor(TwoFactorAuthenticationFailed $evento): void
    {
        $this->bitacora->registrar('segundo_factor_fallido', [
            'usuario_id' => $evento->user->getAuthIdentifier(),
            'correo' => $evento->user->getAttribute('email'),
            'nivel' => 'warning',
        ]);

        $this->auditor->registrar('segundo_factor_fallido', [
            'usuario_id' => $evento->user->getAuthIdentifier(),
            'correo' => $evento->user->getAttribute('email'),
            'resultado' => RegistroAuditoria::FALLO,
        ]);
    }

    public function alAcertarSegundoFactor(ValidTwoFactorAuthenticationCodeProvided $evento): void
    {
        $this->bitacora->registrar('segundo_factor_superado', [
            'usuario_id' => $evento->user->getAuthIdentifier(),
            'correo' => $evento->user->getAttribute('email'),
        ]);
    }

    /**
     * Se gastó un código de recuperación. Casi siempre significa que la persona
     * perdió el teléfono; a veces significa que alguien más lo encontró.
     */
    public function alUsarCodigoRecuperacion(RecoveryCodeReplaced $evento): void
    {
        $this->registrarCambioDeCuenta('codigo_recuperacion_usado', $evento->user, 'warning');
    }

    public function alRegenerarCodigosRecuperacion(RecoveryCodesGenerated $evento): void
    {
        $this->registrarCambioDeCuenta('codigos_recuperacion_regenerados', $evento->user, 'notice');
    }

    /**
     * Cambios que alteran la fuerza de la cuenta: van a los dos destinos.
     */
    private function registrarCambioDeCuenta(string $evento, mixed $usuario, string $nivel): void
    {
        $identificador = $usuario?->getAuthIdentifier();
        $correo = $usuario?->getAttribute('email');

        $this->bitacora->registrar($evento, [
            'usuario_id' => $identificador,
            'correo' => $correo,
            'nivel' => $nivel,
        ]);

        $this->auditor->registrar($evento, [
            'usuario_id' => $identificador,
            'correo' => $correo,
            'tipo_recurso' => 'usuario',
            'identificador_recurso' => $identificador,
        ]);
    }
}
