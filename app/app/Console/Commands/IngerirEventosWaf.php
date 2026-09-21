<?php

namespace App\Console\Commands;

use App\Models\EventoSeguridad;
use App\Services\Siem\Normalizador;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ingesta incremental del registro de auditoria del WAF hacia eventos_seguridad.
 *
 * Dos decisiones gobiernan este comando y las dos nacen de como muere una ingesta real:
 * se recuerda el desplazamiento para no releer el archivo entero en cada pasada, y ninguna
 * linea corrupta puede abortar el proceso. Un archivo de auditoria de ModSecurity se corta
 * a media escritura con total normalidad cuando el servidor rota el registro.
 */
class IngerirEventosWaf extends Command
{
    protected $signature = 'siem:ingerir-waf
        {--archivo= : Ruta del registro a leer. Por defecto la del registro de auditoria del WAF}
        {--formato=waf : waf, aplicacion o sistema}
        {--seguir : Queda escuchando el archivo en vivo, como "tail -f"}
        {--intervalo=2 : Segundos entre lecturas cuando se sigue el archivo}
        {--duracion=0 : Segundos maximos en modo seguir. 0 significa sin limite}
        {--reiniciar : Olvida el desplazamiento guardado y lee el archivo desde el principio}
        {--lote=500 : Filas por insercion masiva}';

    protected $description = 'Ingiere de forma incremental el registro de auditoria del WAF y lo normaliza en eventos_seguridad';

    /**
     * Rutas por defecto de cada fuente dentro del servidor endurecido del proyecto.
     */
    private const RUTAS_POR_DEFECTO = [
        'waf' => '/var/log/modsecurity/audit/audit.json',
        'aplicacion' => 'storage/logs/laravel.log',
        'sistema' => '/var/log/fail2ban.log',
    ];

    public function handle(Normalizador $normalizador): int
    {
        $formato = (string) $this->option('formato');

        if (! array_key_exists($formato, self::RUTAS_POR_DEFECTO)) {
            $this->error("Formato desconocido: {$formato}. Use waf, aplicacion o sistema.");

            return self::INVALID;
        }

        $archivo = $this->resolverRuta($formato);

        if (! is_file($archivo) || ! is_readable($archivo)) {
            $this->error("No se puede leer el archivo {$archivo}.");
            $this->line('Compruebe la ruta y que el usuario de PHP tenga permiso de lectura sobre el registro.');

            return self::FAILURE;
        }

        $clave = $this->claveMarcador($formato, $archivo);

        if ($this->option('reiniciar')) {
            DB::table('marcadores_ingesta')->where('clave', $clave)->update([
                'desplazamiento' => 0,
                'tamano_anterior' => 0,
                'updated_at' => CarbonImmutable::now(),
            ]);

            $this->warn('Desplazamiento reiniciado: se releera el archivo completo.');
        }

        $seguir = (bool) $this->option('seguir');
        $intervalo = max((int) $this->option('intervalo'), 1);
        $duracion = max((int) $this->option('duracion'), 0);
        $limite = CarbonImmutable::now()->addSeconds($duracion);

        $this->info("Ingesta de {$formato} desde {$archivo}");

        do {
            $resultado = $this->procesarArchivo($normalizador, $formato, $archivo, $clave);

            if ($resultado['procesadas'] > 0 || $resultado['descartadas'] > 0) {
                $this->line(sprintf(
                    '  [%s] %d eventos ingeridos, %d lineas descartadas, desplazamiento %d',
                    CarbonImmutable::now()->format('H:i:s'),
                    $resultado['procesadas'],
                    $resultado['descartadas'],
                    $resultado['desplazamiento'],
                ));
            }

            if (! $seguir) {
                break;
            }

            if ($duracion > 0 && CarbonImmutable::now()->greaterThanOrEqualTo($limite)) {
                $this->comment('Se alcanzo la duracion maxima indicada. Fin del seguimiento.');
                break;
            }

            sleep($intervalo);
        } while (true);

        return self::SUCCESS;
    }

    /**
     * @return array{procesadas: int, descartadas: int, desplazamiento: int}
     */
    private function procesarArchivo(Normalizador $normalizador, string $formato, string $archivo, string $clave): array
    {
        $marcador = $this->marcador($clave, $archivo);
        $desplazamiento = (int) $marcador->desplazamiento;

        clearstatcache(true, $archivo);
        $tamano = (int) (filesize($archivo) ?: 0);
        $inodo = (string) (fileinode($archivo) ?: '');

        // Rotacion del registro: si el archivo encogio o cambio de inodo, el desplazamiento
        // guardado apunta a datos que ya no son los mismos. Volver a cero es lo unico correcto.
        if ($tamano < (int) $marcador->tamano_anterior || ($marcador->inodo !== null && $marcador->inodo !== '' && $marcador->inodo !== $inodo)) {
            $this->comment('  Rotacion detectada: se reinicia el desplazamiento.');
            $desplazamiento = 0;
        }

        if ($tamano === $desplazamiento) {
            return ['procesadas' => 0, 'descartadas' => 0, 'desplazamiento' => $desplazamiento];
        }

        $manejador = fopen($archivo, 'rb');

        if ($manejador === false) {
            return ['procesadas' => 0, 'descartadas' => 0, 'desplazamiento' => $desplazamiento];
        }

        fseek($manejador, $desplazamiento);

        $lote = max((int) $this->option('lote'), 1);
        $filas = [];
        $procesadas = 0;
        $descartadas = 0;

        while (($linea = fgets($manejador)) !== false) {
            // Una linea sin salto final es una escritura a medias del WAF. Se deja el
            // desplazamiento antes de ella para volver a leerla completa en la proxima pasada.
            if (! str_ends_with($linea, "\n")) {
                fseek($manejador, -strlen($linea), SEEK_CUR);
                break;
            }

            $desplazamiento = ftell($manejador) ?: $desplazamiento;
            $linea = trim($linea);

            if ($linea === '') {
                continue;
            }

            $datos = $this->normalizarLinea($normalizador, $formato, $linea);

            if ($datos === null) {
                $descartadas++;

                continue;
            }

            $filas[] = $this->prepararFila($datos);
            $procesadas++;

            if (count($filas) >= $lote) {
                $this->insertarLote($filas);
                $filas = [];
            }
        }

        $posicionFinal = ftell($manejador);
        fclose($manejador);

        if ($filas !== []) {
            $this->insertarLote($filas);
        }

        $this->guardarMarcador($clave, [
            'desplazamiento' => $posicionFinal === false ? $desplazamiento : $posicionFinal,
            'tamano_anterior' => $tamano,
            'inodo' => $inodo,
            'lineas_procesadas' => (int) $marcador->lineas_procesadas + $procesadas,
            'lineas_descartadas' => (int) $marcador->lineas_descartadas + $descartadas,
            'ultima_ejecucion' => CarbonImmutable::now(),
        ]);

        return [
            'procesadas' => $procesadas,
            'descartadas' => $descartadas,
            'desplazamiento' => $posicionFinal === false ? $desplazamiento : (int) $posicionFinal,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizarLinea(Normalizador $normalizador, string $formato, string $linea): ?array
    {
        try {
            if ($formato === 'waf') {
                $decodificado = json_decode($linea, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);

                if (! is_array($decodificado)) {
                    return null;
                }

                return $normalizador->desdeAuditoriaWaf($decodificado);
            }

            if ($formato === 'aplicacion') {
                return $normalizador->desdeRegistroAplicacion($linea);
            }

            return $normalizador->desdeRegistroSistema($linea);
        } catch (Throwable $error) {
            // La ingesta jamas se detiene por una linea: se cuenta como descartada y sigue.
            // Perder un evento es malo; perder el resto del archivo es mucho peor.
            $this->warn('  Linea descartada: '.$error->getMessage());

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function prepararFila(array $datos): array
    {
        $ahora = CarbonImmutable::now();

        $datos['identificadores_regla'] = json_encode($datos['identificadores_regla'] ?? [], JSON_UNESCAPED_UNICODE);
        $datos['etiquetas'] = json_encode($datos['etiquetas'] ?? [], JSON_UNESCAPED_UNICODE);
        $datos['marca_tiempo'] = $datos['marca_tiempo'] instanceof CarbonInterface
            ? $datos['marca_tiempo']->toDateTimeString()
            : (string) $datos['marca_tiempo'];
        $datos['fue_bloqueado'] = (bool) ($datos['fue_bloqueado'] ?? false);
        $datos['es_demostracion'] = (bool) ($datos['es_demostracion'] ?? false);
        $datos['created_at'] = $ahora;
        $datos['updated_at'] = $ahora;

        return $datos;
    }

    /**
     * @param  array<int, array<string, mixed>>  $filas
     */
    private function insertarLote(array $filas): void
    {
        $filas = $this->soltarCuentasInexistentes($filas);

        // insertOrIgnore apoyado en el indice unico de la huella: reprocesar el mismo tramo
        // del archivo no duplica eventos, que es justo lo que pasa tras una rotacion mal leida.
        EventoSeguridad::query()->insertOrIgnore($filas);
    }

    /**
     * Anula usuario_id cuando la cuenta ya no existe en users.
     *
     * eventos_seguridad tiene clave foranea hacia users e insertOrIgnore convierte el fallo
     * de esa clave en un aviso: la fila entera se descarta sin decir nada. El registro de una
     * cuenta dada de baja desapareceria del SIEM justo cuando mas interesa investigarla.
     * Perder el enlace con la cuenta es aceptable; perder el evento no lo es.
     *
     * @param  array<int, array<string, mixed>>  $filas
     * @return array<int, array<string, mixed>>
     */
    private function soltarCuentasInexistentes(array $filas): array
    {
        $identificadores = array_values(array_unique(array_filter(
            array_column($filas, 'usuario_id'),
            static fn (mixed $identificador): bool => is_numeric($identificador),
        )));

        if ($identificadores === []) {
            return $filas;
        }

        $existentes = array_flip(array_map(
            static fn (mixed $identificador): int => (int) $identificador,
            DB::table('users')->whereIn('id', $identificadores)->pluck('id')->all(),
        ));

        foreach ($filas as $indice => $fila) {
            $identificador = $fila['usuario_id'] ?? null;

            if ($identificador !== null && ! isset($existentes[(int) $identificador])) {
                $filas[$indice]['usuario_id'] = null;
            }
        }

        return $filas;
    }

    private function resolverRuta(string $formato): string
    {
        $archivo = $this->option('archivo');

        if (is_string($archivo) && $archivo !== '') {
            return $archivo;
        }

        // config('siem.*') queda como punto de extension: si el integrador publica un
        // config/siem.php, manda ese valor; si no existe, se usa la ruta por defecto.
        $configurada = config('siem.rutas.'.$formato);

        if (is_string($configurada) && $configurada !== '') {
            return $configurada;
        }

        $porDefecto = self::RUTAS_POR_DEFECTO[$formato];

        return str_starts_with($porDefecto, '/') ? $porDefecto : base_path($porDefecto);
    }

    private function claveMarcador(string $formato, string $archivo): string
    {
        return substr(hash('sha256', $formato.'|'.$archivo), 0, 40);
    }

    private function marcador(string $clave, string $archivo): object
    {
        $marcador = DB::table('marcadores_ingesta')->where('clave', $clave)->first();

        if ($marcador !== null) {
            return $marcador;
        }

        DB::table('marcadores_ingesta')->insert([
            'clave' => $clave,
            'ruta_archivo' => $archivo,
            'desplazamiento' => 0,
            'tamano_anterior' => 0,
            'lineas_procesadas' => 0,
            'lineas_descartadas' => 0,
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);

        /** @var object $creado */
        $creado = DB::table('marcadores_ingesta')->where('clave', $clave)->first();

        return $creado;
    }

    /**
     * @param  array<string, mixed>  $valores
     */
    private function guardarMarcador(string $clave, array $valores): void
    {
        $valores['updated_at'] = CarbonImmutable::now();

        DB::table('marcadores_ingesta')->where('clave', $clave)->update($valores);
    }
}
