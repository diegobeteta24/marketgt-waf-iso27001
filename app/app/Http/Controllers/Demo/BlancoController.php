<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Blanco controlado del laboratorio de demostración.
 *
 * Este controlador es el destino de los ataques cuando el WAF los DEJA PASAR: en el modo
 * comparativo, la petición llega hasta aquí porque /demo-waf corre en solo detección. Su
 * único cometido es demostrar, de forma honesta y sin ser realmente explotable, que la
 * CAPA 5 (aplicación) detiene la misma amenaza que la CAPA 4 (WAF). Es defensa en
 * profundidad: dos controles independientes para el mismo ataque.
 *
 * NADA de lo que hay aquí ejecuta la carga del atacante. La inyección SQL se demuestra con
 * una consulta preparada (el parámetro viaja LIGADO, nunca concatenado); el XSS, con el
 * escapado que la aplicación aplicaría antes de reflejar; el recorrido de rutas, con la
 * normalización contra una lista blanca SIN abrir ningún fichero. Las familias que la
 * aplicación no puede demostrar por sí misma (escáner, método) se marcan como explicativas.
 */
class BlancoController extends Controller
{
    public function recibir(Request $peticion, string $familia): JsonResponse
    {
        $respuesta = match ($familia) {
            'sqli' => $this->inyeccionSql($peticion),
            'xss' => $this->scriptEntreSitios($peticion),
            'recorrido' => $this->recorridoRutas($peticion),
            'comandos' => $this->ejecucionComandos(),
            'escaner' => $this->escaner($peticion),
            'metodo' => $this->metodo($peticion),
            'seo' => $this->posicionamiento($peticion),
            default => [
                'capa' => 'aplicacion',
                'control' => 'ninguno',
                'veredicto' => 'Familia de ataque desconocida.',
                'es_explicativo' => true,
            ],
        };

        // La advertencia acompaña a TODA respuesta: quien mire el panel debe saber que este
        // blanco existe para la demostración y que el WAF sobre él está en solo detección
        // a propósito, no por un descuido.
        $respuesta['blanco'] = 'controlado';
        $respuesta['familia'] = $familia;
        $respuesta['advertencia'] = 'Blanco de laboratorio. El WAF sobre /demo-waf está en modo '
            .'solo detección a propósito, para demostrar la segunda capa de defensa.';

        return response()->json($respuesta);
    }

    /**
     * Demostración: la consulta preparada trata la carga como texto literal. El parámetro
     * se liga (?) y nunca se concatena, así que "' OR 1=1 --" busca un producto que se
     * llame literalmente así —y no existe— en lugar de alterar la consulta.
     *
     * @return array<string, mixed>
     */
    private function inyeccionSql(Request $peticion): array
    {
        $carga = (string) ($peticion->input('q') ?? $peticion->input('buscar') ?? '');

        $base = [
            'capa' => 'aplicacion',
            'control' => 'Consulta preparada (parámetros ligados por PDO)',
            'carga' => $carga,
            'es_explicativo' => false,
        ];

        try {
            if (Schema::hasTable('productos')) {
                $consulta = DB::table('productos')->where('nombre', 'like', '%'.$carga.'%');

                return array_merge($base, [
                    // toSql() muestra el marcador de posición "?": la carga NO está en la
                    // sentencia, viaja aparte como valor ligado. Esa es toda la defensa.
                    'sql' => $consulta->toSql(),
                    'valores_ligados' => $consulta->getBindings(),
                    'filas' => $consulta->count(),
                    'veredicto' => 'La carga se ligó como valor, no como SQL. Cero filas: no hubo fuga de datos.',
                ]);
            }
        } catch (Throwable $e) {
            // Si la tabla aún no existe (base sin migrar), se demuestra igual con el patrón
            // de la consulta, sin tocar la base.
        }

        return array_merge($base, [
            'sql' => 'select * from productos where nombre like ?',
            'valores_ligados' => ['%'.$carga.'%'],
            'filas' => 0,
            'veredicto' => 'La carga se ligaría como valor (marcador "?"), no como SQL. La inyección no altera la sentencia.',
        ]);
    }

    /**
     * Demostración: la aplicación escapa la salida antes de reflejarla. Se devuelve la
     * versión escapada (lo que Blade produciría con {{ }}) para que se vea que el navegador
     * mostraría texto, no ejecutaría un guión.
     *
     * @return array<string, mixed>
     */
    private function scriptEntreSitios(Request $peticion): array
    {
        $carga = (string) $peticion->input('q', '');

        return [
            'capa' => 'aplicacion',
            'control' => 'Escapado automático de salida (htmlspecialchars, como {{ }} en Blade)',
            'carga' => $carga,
            'reflejo_escapado' => e($carga),
            'veredicto' => 'La aplicación reflejaría el texto escapado: el navegador lo muestra, no lo ejecuta.',
            'es_explicativo' => false,
        ];
    }

    /**
     * Demostración: la ruta se normaliza y se exige que quede DENTRO del directorio
     * permitido. No se abre ningún fichero; solo se resuelve la ruta lógicamente.
     *
     * @return array<string, mixed>
     */
    private function recorridoRutas(Request $peticion): array
    {
        $carga = (string) $peticion->input('archivo', '');

        // Lista blanca: solo el nombre base, contra un directorio fijo. "../../etc/passwd"
        // colapsa a "passwd", que no existe en el directorio de descargas permitido.
        $directorioPermitido = '/var/www/html/storage/app/descargas';
        $nombreBase = basename(str_replace('\\', '/', urldecode($carga)));
        $rutaResuelta = $directorioPermitido.'/'.$nombreBase;
        $dentro = str_starts_with($rutaResuelta, $directorioPermitido.'/');

        return [
            'capa' => 'aplicacion',
            'control' => 'Normalización con lista blanca (basename + directorio fijo)',
            'carga' => $carga,
            'nombre_base' => $nombreBase,
            'ruta_resuelta' => $rutaResuelta,
            'dentro_del_directorio' => $dentro,
            'veredicto' => 'El recorrido se descarta: solo se admite el nombre base dentro del directorio permitido.',
            'es_explicativo' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ejecucionComandos(): array
    {
        return [
            'capa' => 'aplicacion',
            'control' => 'Ausencia de superficie: la aplicación no invoca la shell con entrada del usuario',
            'veredicto' => 'No hay nada que ejecutar: MarketGT no pasa parámetros a exec/shell. La defensa es de diseño.',
            'es_explicativo' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function escaner(Request $peticion): array
    {
        return [
            'capa' => 'aplicacion',
            'control' => 'Registro del User-Agent (la detección de herramientas es tarea del WAF)',
            'user_agent' => (string) $peticion->userAgent(),
            'veredicto' => 'A nivel de aplicación solo se registra la huella; cortar al escáner corresponde a la Capa 4.',
            'es_explicativo' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function metodo(Request $peticion): array
    {
        return [
            'capa' => 'aplicacion',
            'control' => 'Enrutador de Laravel: responde 405 a un método no declarado en la ruta',
            'metodo_recibido' => $peticion->method(),
            'veredicto' => 'La aplicación no enruta métodos que no usa; el WAF los rechaza antes con 403.',
            'es_explicativo' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function posicionamiento(Request $peticion): array
    {
        return [
            'capa' => 'aplicacion',
            'control' => 'Escapado del contenido publicable + noindex en resultados de búsqueda',
            'carga' => (string) ($peticion->input('comentario')
                ?? $peticion->input('descripcion')
                ?? $peticion->input('redirect')
                ?? $peticion->input('q')
                ?? ''),
            'veredicto' => 'El contenido de usuario se escapa antes de indexarse y las páginas sensibles llevan noindex.',
            'es_explicativo' => false,
        ];
    }
}
