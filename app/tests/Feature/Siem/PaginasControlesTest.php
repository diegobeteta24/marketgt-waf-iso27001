<?php

namespace Tests\Feature\Siem;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Las dos pantallas desde las que se alimentan vertices del triangulo.
 *
 * Existian como componentes, con pruebas propias, y no tenian ruta. Nada fallaba: el
 * componente se probaba montandolo directamente y pasaba. Lo que nadie comprobaba era que
 * una persona pudiera llegar a el con el navegador, y la capacitacion solo se registra
 * desde ahi, asi que esa metrica no podia tener datos nunca.
 *
 * Por eso estas pruebas piden la URL y no montan el componente: lo que se certifica es
 * el camino entero, desde la ruta y el middleware de rol hasta la vista.
 */
class PaginasControlesTest extends TestCase
{
    use RefreshDatabase;

    private function conRol(string $rol, string $nombre): User
    {
        $registro = Rol::query()->firstOrCreate(
            ['nombre' => $rol],
            ['etiqueta' => ucfirst($rol), 'descripcion' => 'Rol de prueba.'],
        );

        $usuario = User::factory()->create(['name' => $nombre]);
        $usuario->roles()->attach($registro->id);

        return $usuario;
    }

    public function test_el_auditor_abre_la_pagina_de_continuidad(): void
    {
        $this->actingAs($this->conRol(Rol::AUDITOR, 'Ana Auditora'))
            ->get(route('siem.continuidad'))
            ->assertOk()
            ->assertSee('Continuidad y recuperacion');
    }

    public function test_el_auditor_abre_la_pagina_de_capacitacion(): void
    {
        $this->actingAs($this->conRol(Rol::AUDITOR, 'Ana Auditora'))
            ->get(route('siem.capacitacion'))
            ->assertOk()
            ->assertSee('Capacitacion del personal');
    }

    public function test_el_administrador_abre_las_dos(): void
    {
        $admin = $this->conRol(Rol::ADMINISTRADOR, 'Alex Admin');

        $this->actingAs($admin)->get(route('siem.continuidad'))->assertOk();
        $this->actingAs($admin)->get(route('siem.capacitacion'))->assertOk();
    }

    public function test_un_cliente_no_entra_en_ninguna(): void
    {
        // El registro de asistencias es la evidencia de un control. Que un cliente de la
        // tienda pudiera abrirlo seria que cualquiera pudiera fabricarla.
        $cliente = $this->conRol(Rol::CLIENTE, 'Carlos Cliente');

        $this->actingAs($cliente)->get(route('siem.continuidad'))->assertForbidden();
        $this->actingAs($cliente)->get(route('siem.capacitacion'))->assertForbidden();
    }

    public function test_sin_sesion_se_va_al_inicio_de_sesion(): void
    {
        $this->get(route('siem.continuidad'))->assertRedirect(route('login'));
        $this->get(route('siem.capacitacion'))->assertRedirect(route('login'));
    }

    public function test_las_dos_aparecen_en_la_navegacion_del_centro_de_monitoreo(): void
    {
        // Una ruta que no esta en el menu es una ruta que en la practica nadie abre, que es
        // como estas dos pantallas llegaron hasta aqui sin que nadie lo notara.
        $this->actingAs($this->conRol(Rol::AUDITOR, 'Ana Auditora'))
            ->get(route('siem.continuidad'))
            ->assertSee(route('siem.continuidad'), false)
            ->assertSee(route('siem.capacitacion'), false);
    }
}
