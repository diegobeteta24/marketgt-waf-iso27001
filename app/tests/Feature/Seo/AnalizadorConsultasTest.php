<?php

namespace Tests\Feature\Seo;

use App\Services\Seo\AnalizadorConsultas;
use Tests\TestCase;

/**
 * El analizador de consultas de Search Console, sobre el caso real que lo origino.
 *
 * El comentario de CONSULTAS_EJEMPLO prometia que "si algun dia deja de detectarlo, la
 * prueba falla sola", y esa prueba no existia. Esta es.
 *
 * Fija ademas el vocabulario de apuestas en espanol. Faltaba entero: el sector se llamaba
 * "apuestas" y la consulta "apuestas" salia limpia, en una plataforma para comercios
 * guatemaltecos.
 */
class AnalizadorConsultasTest extends TestCase
{
    /**
     * @param  array<int, array{0: string, 1: int, 2: int}>  $consultas
     * @return array<string, array<string, mixed>>
     */
    private function analizar(array $consultas): array
    {
        // Columnas separadas por tabulador: lo que produce copiar la tabla de Search Console.
        $pegado = implode("\n", array_map(
            static fn (array $c): string => $c[0]."\t".$c[1]."\t".$c[2],
            $consultas,
        ));

        $resultado = app(AnalizadorConsultas::class)->analizar($pegado, [
            'vocabulario' => AnalizadorConsultas::VOCABULARIO_EJEMPLO,
        ]);

        $porConsulta = [];
        foreach ($resultado['filas'] as $fila) {
            $porConsulta[(string) $fila['consulta']] = $fila;
        }

        return $porConsulta;
    }

    public function test_el_caso_real_se_detecta_entero(): void
    {
        $filas = $this->analizar(AnalizadorConsultas::CONSULTAS_EJEMPLO);

        foreach (['p9bet login', '0016bet', '96n.com', 'kmj888', 'porh300'] as $envenenada) {
            $this->assertSame(
                AnalizadorConsultas::VEREDICTO_ENVENENADA,
                $filas[$envenenada]['veredicto'] ?? null,
                "La consulta del caso real \"{$envenenada}\" dejo de detectarse.",
            );
        }
    }

    public function test_las_consultas_legitimas_del_negocio_siguen_limpias(): void
    {
        $filas = $this->analizar(AnalizadorConsultas::CONSULTAS_EJEMPLO);

        foreach ([
            'camaras de seguridad guatemala',
            'mantenimiento de camaras de seguridad',
            'instalacion de camaras cctv zona 10',
            'camaras hikvision guatemala precio',
            'alarma con monitoreo para negocio',
        ] as $legitima) {
            $this->assertSame(
                AnalizadorConsultas::VEREDICTO_LIMPIA,
                $filas[$legitima]['veredicto'] ?? null,
                "La consulta legitima \"{$legitima}\" se marca como sospechosa.",
            );
        }
    }

    public function test_apuestas_en_espanol_se_detecta(): void
    {
        // La fila exacta de la captura: una palabra, un clic, dos impresiones.
        $filas = $this->analizar([['apuestas', 1, 2]]);

        $this->assertSame(AnalizadorConsultas::VEREDICTO_ENVENENADA, $filas['apuestas']['veredicto']);
    }

    public function test_el_resto_del_vocabulario_en_espanol_no_pasa_como_limpio(): void
    {
        $filas = $this->analizar([
            ['casa de apuestas guatemala', 0, 30],
            ['tragamonedas gratis', 0, 25],
            ['loteria nacional resultados', 0, 20],
            ['pronosticos deportivos hoy', 0, 18],
            ['giros gratis sin deposito', 0, 15],
        ]);

        foreach ($filas as $consulta => $fila) {
            $this->assertNotSame(
                AnalizadorConsultas::VEREDICTO_LIMPIA,
                $fila['veredicto'],
                "\"{$consulta}\" es vocabulario de apuestas y salio limpia.",
            );
        }
    }

    public function test_una_palabra_ambigua_dentro_de_una_consulta_del_negocio_no_la_envenena(): void
    {
        // El caso que justifica el descuento por hablar del negocio: "slot" es de casino,
        // pero aqui es la ranura de la tarjeta de memoria de una camara.
        $filas = $this->analizar([['slot para memoria sd de la camara', 2, 40]]);

        $this->assertNotSame(
            AnalizadorConsultas::VEREDICTO_ENVENENADA,
            $filas['slot para memoria sd de la camara']['veredicto'],
        );
    }
}
