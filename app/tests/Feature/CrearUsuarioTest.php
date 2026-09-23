<?php

namespace Tests\Feature;

use App\Models\RegistroAuditoria;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CrearUsuarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);
    }

    private function contrasenaImpresa(string $salida): string
    {
        preg_match('/Contraseña\s*\|\s*(\S+)\s*\|/u', $salida, $c);

        return $c[1] ?? '';
    }

    public function test_crea_un_administrador_que_puede_entrar_con_la_contrasena_mostrada(): void
    {
        $codigo = Artisan::call('usuarios:crear', [
            'nombre' => 'Ing. Omar Sagastume',
            'correo' => 'Ing.Sagastume@marketgt.test',
        ]);
        $salida = Artisan::output();

        $this->assertSame(0, $codigo);

        $usuario = User::query()->where('email', 'ing.sagastume@marketgt.test')->firstOrFail();
        $contrasena = $this->contrasenaImpresa($salida);

        // La contraseña que se imprime es la que abre la cuenta: si no, el ingeniero recibe
        // una cadena que no sirve.
        $this->assertTrue(Hash::check($contrasena, $usuario->password));
        $this->assertNotNull($usuario->email_verified_at);
        $this->assertTrue($usuario->tieneRol(Rol::ADMINISTRADOR));
        $this->assertTrue($usuario->tieneRol(Rol::CLIENTE));
    }

    public function test_la_contrasena_cumple_la_politica_de_produccion(): void
    {
        Artisan::call('usuarios:crear', ['nombre' => 'Prueba', 'correo' => 'politica@marketgt.test']);
        $contrasena = $this->contrasenaImpresa(Artisan::output());

        $this->assertSame(20, strlen($contrasena));
        $this->assertMatchesRegularExpression('/[A-Z]/', $contrasena);
        $this->assertMatchesRegularExpression('/[a-z]/', $contrasena);
        $this->assertMatchesRegularExpression('/[0-9]/', $contrasena);
        $this->assertMatchesRegularExpression('/[!@#%*\-_+=?]/', $contrasena);
        // Nada que la terminal o un formulario puedan reinterpretar al pegarla.
        $this->assertDoesNotMatchRegularExpression('/[\s"\'`$\\\\]/', $contrasena);
    }

    public function test_deja_constancia_en_el_registro_de_auditoria(): void
    {
        Artisan::call('usuarios:crear', ['nombre' => 'Prueba', 'correo' => 'auditoria@marketgt.test']);

        $asiento = RegistroAuditoria::query()->where('accion', 'usuario.creado_por_consola')->firstOrFail();

        $this->assertSame('auditoria@marketgt.test', $asiento->correo);
        $this->assertSame(Rol::ADMINISTRADOR, $asiento->detalle['rol']);
        // La contraseña no aparece en el asiento, ni en claro ni resumida.
        $this->assertStringNotContainsStringIgnoringCase('password', json_encode($asiento->detalle));
    }

    public function test_nunca_sobrescribe_una_cuenta_existente(): void
    {
        $existente = User::factory()->create(['email' => 'dueno@marketgt.test']);
        $resumenOriginal = $existente->password;

        $codigo = Artisan::call('usuarios:crear', ['nombre' => 'Intruso', 'correo' => 'dueno@marketgt.test']);

        $this->assertSame(1, $codigo);
        $this->assertSame($resumenOriginal, $existente->fresh()->password);
        $this->assertNotSame('Intruso', $existente->fresh()->name);
    }

    public function test_rechaza_un_rol_que_no_existe(): void
    {
        $codigo = Artisan::call('usuarios:crear', [
            'nombre' => 'Prueba', 'correo' => 'rol@marketgt.test', '--rol' => 'superusuario',
        ]);

        $this->assertSame(1, $codigo);
        $this->assertFalse(User::query()->where('email', 'rol@marketgt.test')->exists());
    }

    public function test_rechaza_un_correo_mal_formado(): void
    {
        $codigo = Artisan::call('usuarios:crear', ['nombre' => 'Prueba', 'correo' => 'no-es-un-correo']);

        $this->assertSame(1, $codigo);
        $this->assertSame(0, User::query()->count());
    }
}
