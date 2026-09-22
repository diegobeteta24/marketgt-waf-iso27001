<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El punto de entrada del proyecto.
 *
 * Sustituye a la prueba de ejemplo del andamiaje de Laravel, que afirmaba que la raiz
 * devuelve 200. Desde que la raiz redirige al catalogo esa prueba fallaba, y una suite con
 * un fallo permanente deja de avisar: cuando todo el mundo sabe que "ese siempre esta rojo",
 * el siguiente fallo de verdad tampoco se mira.
 *
 * Lo que se comprueba es lo que el proyecto promete: quien abre el enlace cae en la tienda,
 * sin sesion, porque el catalogo es el activo publico que todo el esquema protege.
 */
class PuntoDeEntradaTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_raiz_lleva_al_catalogo(): void
    {
        $this->get('/')->assertRedirect('/tienda');
    }

    public function test_el_catalogo_se_abre_sin_iniciar_sesion(): void
    {
        $this->get(route('tienda.catalogo'))->assertOk();
    }

    public function test_el_centro_de_monitoreo_no_se_abre_sin_iniciar_sesion(): void
    {
        // La otra mitad de la promesa: la tienda es publica, el panel de ataques no.
        $this->get(route('siem.tablero'))->assertRedirect(route('login'));
    }
}
