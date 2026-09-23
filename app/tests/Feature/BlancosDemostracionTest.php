<?php

namespace Tests\Feature;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Los blancos de la consola de demostracion tienen que quedar fuera de la proteccion CSRF.
 *
 * Son destinos de ataque anonimo: un atacante real no lleva el token de sesion de nadie.
 * Con CSRF activo, todo POST se detiene en 419 antes del controlador, y la consola deja de
 * poder ensenar la segunda capa de defensa, la consulta preparada que neutraliza lo que el
 * WAF deja pasar.
 *
 * Eso ya ocurrio: la exclusion nombraba las clases de CSRF de versiones anteriores de
 * Laravel, y en la 13 la del grupo web es otra. La ruta DECIA estar exenta y no lo estaba.
 *
 * Por eso esta prueba no hace un POST: durante las pruebas Laravel omite la comprobacion de
 * CSRF, y un POST habria pasado igual con el defecto. Lo que se comprueba es la causa: que
 * cada middleware de CSRF que el grupo web tenga HOY este excluido en los blancos. Si una
 * version futura lo vuelve a renombrar, esta prueba falla sin que nadie tenga que recordarlo.
 */
class BlancosDemostracionTest extends TestCase
{
    /**
     * @return array<int, string>
     */
    private function middlewareDeCsrfDelGrupoWeb(): array
    {
        $grupos = app(Kernel::class)->getMiddlewareGroups();

        return array_values(array_filter(
            $grupos['web'] ?? [],
            static fn (string $clase): bool => (bool) preg_match('/Csrf|Forgery/i', $clase),
        ));
    }

    public function test_el_grupo_web_tiene_una_proteccion_csrf_que_excluir(): void
    {
        // Si esto fallara, la prueba de abajo pasaria sin comprobar nada.
        $this->assertNotEmpty($this->middlewareDeCsrfDelGrupoWeb());
    }

    public function test_los_dos_blancos_excluyen_toda_la_proteccion_csrf_del_grupo_web(): void
    {
        foreach (['demo.blanco.activo', 'demo.blanco.deteccion'] as $nombre) {
            $ruta = Route::getRoutes()->getByName($nombre);

            $this->assertNotNull($ruta, "No existe la ruta {$nombre}.");

            foreach ($this->middlewareDeCsrfDelGrupoWeb() as $clase) {
                $this->assertContains(
                    $clase,
                    $ruta->excludedMiddleware(),
                    "{$nombre} no excluye {$clase}: todo POST a ese blanco respondera 419.",
                );
            }
        }
    }
}
