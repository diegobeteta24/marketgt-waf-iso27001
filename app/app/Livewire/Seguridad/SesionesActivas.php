<?php

declare(strict_types=1);

namespace App\Livewire\Seguridad;

use App\Models\User;
use App\Services\Seguridad\Auditor;
use App\Services\Seguridad\BitacoraSeguridad;
use App\Services\Seguridad\GestorSesiones;
use Flux\Flux;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Pantalla de sesiones activas.
 *
 * Llega protegida por 'password.confirm' desde routes/seguridad.php, y además
 * vuelve a pedir la contraseña para cerrar las demás sesiones. No es celo
 * excesivo: cerrar las sesiones ajenas es una operación de recuperación de cuenta
 * y Laravel necesita la contraseña en claro para ejecutarla.
 */
#[Layout('layouts.app')]
#[Title('Sesiones activas')]
class SesionesActivas extends Component
{
    public string $contrasena = '';

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function sesiones(): array
    {
        $usuario = auth()->user();

        if (! $usuario instanceof User) {
            return [];
        }

        return app(GestorSesiones::class)->listar($usuario, session()->getId());
    }

    #[Computed]
    public function disponible(): bool
    {
        return app(GestorSesiones::class)->disponible();
    }

    /**
     * Cierra todas las sesiones menos la actual.
     *
     * Las dos operaciones hacen falta y cada una cubre un vector distinto:
     *
     *   - logoutOtherDevices vuelve a calcular el resumen de la contraseña (misma
     *     contraseña, sal nueva). Eso invalida las cookies de "recuérdame" ajenas,
     *     porque el testigo de recuerdo lleva dentro el resumen y SessionGuard lo
     *     compara con el guardado. Sin esto, borrar las filas no bastaría: la
     *     cookie del atacante volvería a autenticarlo sin contraseña mientras la
     *     víctima cree haberlo expulsado.
     *   - El borrado de las filas de la tabla sessions es lo que expulsa de verdad
     *     a las sesiones abiertas. Conviene no atribuírselo al rehash: la
     *     expulsión por cambio de resumen la aplica el middleware AuthenticateSession
     *     ('auth.session'), que este proyecto NO tiene colgado del grupo web. Con
     *     el controlador de sesión "database" el borrado logra el mismo efecto y de
     *     forma inmediata, pero si algún día se pasa a otro controlador habrá que
     *     añadir ese middleware o esta pantalla dejará de expulsar a nadie.
     */
    public function cerrarOtras(GestorSesiones $gestor, BitacoraSeguridad $bitacora, Auditor $auditor): void
    {
        $this->validate(
            ['contrasena' => ['required', 'string', 'current_password']],
            [
                'contrasena.required' => 'Escriba su contraseña para confirmar.',
                'contrasena.current_password' => 'La contraseña no coincide.',
            ],
        );

        $usuario = auth()->user();

        if (! $usuario instanceof User) {
            return;
        }

        auth()->logoutOtherDevices($this->contrasena);

        $cerradas = $gestor->cerrarOtras($usuario, session()->getId());

        $this->reset('contrasena');

        $bitacora->registrar('sesiones_cerradas', [
            'usuario_id' => $usuario->id,
            'correo' => $usuario->email,
            'nivel' => 'notice',
            'detalle' => ['sesiones_cerradas' => $cerradas],
        ]);

        $auditor->registrar('sesiones_cerradas', [
            'tipo_recurso' => 'usuario',
            'identificador_recurso' => $usuario->id,
            'detalle' => ['sesiones_cerradas' => $cerradas],
        ]);

        unset($this->sesiones);

        Flux::toast(
            variant: 'success',
            text: $cerradas === 1
                ? 'Se cerró 1 sesión.'
                : 'Se cerraron '.$cerradas.' sesiones.',
        );
    }

    /**
     * Cierra una sesión concreta. El identificador llega del navegador, así que el
     * gestor comprueba que la fila pertenezca a quien la pide antes de borrarla.
     */
    public function cerrarUna(string $sesionId, GestorSesiones $gestor, BitacoraSeguridad $bitacora): void
    {
        $usuario = auth()->user();

        if (! $usuario instanceof User || $sesionId === session()->getId()) {
            return;
        }

        if ($gestor->cerrarUna($usuario, $sesionId)) {
            $bitacora->registrar('sesion_cerrada_individual', [
                'usuario_id' => $usuario->id,
                'correo' => $usuario->email,
                'nivel' => 'notice',
            ]);

            Flux::toast(variant: 'success', text: 'Sesión cerrada.');
        }

        unset($this->sesiones);
    }

    public function render(): View
    {
        return view('livewire.seguridad.sesiones-activas');
    }
}
