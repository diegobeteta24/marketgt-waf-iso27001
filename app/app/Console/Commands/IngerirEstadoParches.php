<?php

namespace App\Console\Commands;

use App\Models\EstadoParche;
use App\Services\Siem\AnalizadorParches;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ingesta incremental del inventario de parches del anfitrion hacia estados_parche.
 *
 * Es la cuarta fuente del SIEM, junto al cortafuegos, la aplicacion y el sistema. Se
 * separa de siem:ingerir-waf por una razon de fondo: aquella normaliza eventos de trafico
 * que solo se anaden, y esta sigue el ciclo de vida de un parche, que cambia de pendiente
 * a aplicado. Dos cosas distintas con dos formas distintas de escribir en la base.
 *
 * Dos decisiones vienen del comando del cortafuegos porque alli ya se demostraron: se
 * recuerda el desplazamiento para no releer el archivo entero, y ninguna linea corrupta
 * detiene el proceso. El recolector escribe mientras esta ingesta lee, de modo que
 * encontrar una linea a medias es lo normal, no la excepcion.
 *
 * La tercera decision es propia: el desfase en horas se RECALCULA aqui a partir de las dos
 * marcas de tiempo de la propia fila, en lugar de creerse el que trae el archivo. Asi la
 * cifra que sostiene la metrica siempre se puede reproducir desde lo que hay guardado, y
 * un fallo del recolector se convierte en un aviso visible en lugar de en un plazo falso.
 */
class IngerirEstadoParches extends Command
{
    protected $signature = 'siem:ingerir-parches
        {--archivo= : Ruta del inventario a leer. Por defecto la configurada para la fuente de parches}
        {--reiniciar : Olvida el desplazamiento guardado y lee el archivo desde el principio}
        {--lote=200 : Filas por pasada de escritura}
        {--resumen : Muestra el estado de la metrica al terminar}';

    protected $description = 'Ingiere el inventario de parches recolectado en el anfitrion y lo guarda en estados_parche';

    /**
     * Ruta por defecto dentro del contenedor. Tiene que coincidir con el punto de montaje
     * declarado en docker-compose.yml y con la salida de infra/scripts/recolectar-parches.sh:
     * si los tres no coinciden, la ingesta no encuentra nada y la metrica vuelve a "sin
     * datos" sin un solo error por ninguna parte.
     */
    private const RUTA_POR_DEFECTO = '/var/lib/marketgt/parches/inventario-parches.jsonl';

    /**
     * Tolerancia entre el desfase que calcula el recolector y el que recalcula la ingesta.
     * Una hora cubre el redondeo de las dos partes; mas que eso significa que una de las
     * dos esta leyendo mal una fecha, y eso hay que decirlo en voz alta.
     */
    private const TOLERANCIA_DESFASE_HORAS = 1.0;

    public function handle(): int
    {
        $archivo = $this->resolverRuta();

        if (! is_file($archivo) || ! is_readable($archivo)) {
            $this->error("No se puede leer el inventario de parches en {$archivo}.");
            $this->line('Compruebe que infra/scripts/recolectar-parches.sh corre en el anfitrion y que');
            $this->line('su directorio de salida esta montado en el contenedor en modo solo lectura.');

            return self::FAILURE;
        }

        $clave = $this->claveMarcador($archivo);

        if ($this->option('reiniciar')) {
            DB::table('marcadores_ingesta')->where('clave', $clave)->update([
                'desplazamiento' => 0,
                'tamano_anterior' => 0,
                'updated_at' => CarbonImmutable::now(),
            ]);

            $this->warn('Desplazamiento reiniciado: se releera el inventario completo.');
        }

        $this->info("Ingesta del inventario de parches desde {$archivo}");

        $resultado = $this->procesarArchivo($archivo, $clave);

        $this->line(sprintf(
            '  %d parches guardados, %d ignorados, %d lineas descartadas, desplazamiento %d',
            $resultado['guardados'],
            $resultado['ignorados'],
            $resultado['descartadas'],
            $resultado['desplazamiento'],
        ));

        foreach ($resultado['avisos'] as $aviso) {
            $this->warn('  '.$aviso);
        }

        if ($this->option('resumen')) {
            $this->mostrarResumen();
        }

        return self::SUCCESS;
    }

    /**
     * @return array{guardados: int, ignorados: int, descartadas: int, desplazamiento: int, avisos: array<int, string>}
     */
    private function procesarArchivo(string $archivo, string $clave): array
    {
        $marcador = $this->marcador($clave, $archivo);
        $desplazamiento = (int) $marcador->desplazamiento;

        clearstatcache(true, $archivo);
        $tamano = (int) (filesize($archivo) ?: 0);
        $inodo = (string) (fileinode($archivo) ?: '');

        // Rotacion: si el archivo encogio o cambio de inodo, el desplazamiento guardado
        // apunta a otro contenido. Releer desde cero es seguro porque cada parche se
        // identifica por su huella y volver a verlo no duplica nada.
        if ($tamano < (int) $marcador->tamano_anterior || ($marcador->inodo !== null && $marcador->inodo !== '' && $marcador->inodo !== $inodo)) {
            $this->comment('  Rotacion detectada: se reinicia el desplazamiento.');
            $desplazamiento = 0;
        }

        if ($tamano === $desplazamiento) {
            $this->comment('  Sin novedades desde la ultima pasada.');

            return ['guardados' => 0, 'ignorados' => 0, 'descartadas' => 0, 'desplazamiento' => $desplazamiento, 'avisos' => []];
        }

        $manejador = fopen($archivo, 'rb');

        if ($manejador === false) {
            return ['guardados' => 0, 'ignorados' => 0, 'descartadas' => 0, 'desplazamiento' => $desplazamiento, 'avisos' => []];
        }

        fseek($manejador, $desplazamiento);

        $lote = max((int) $this->option('lote'), 1);
        $pendientesDeEscribir = [];
        $guardados = 0;
        $ignorados = 0;
        $descartadas = 0;
        $avisos = [];

        while (($linea = fgets($manejador)) !== false) {
            // Linea sin salto final: el recolector la esta escribiendo ahora mismo. Se deja
            // el desplazamiento antes de ella para leerla entera en la proxima pasada.
            if (! str_ends_with($linea, "\n")) {
                fseek($manejador, -strlen($linea), SEEK_CUR);
                break;
            }

            $desplazamiento = ftell($manejador) ?: $desplazamiento;
            $linea = trim($linea);

            if ($linea === '') {
                continue;
            }

            try {
                $datos = json_decode($linea, true, 64, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            } catch (Throwable $error) {
                $descartadas++;

                continue;
            }

            if (! is_array($datos)) {
                $descartadas++;

                continue;
            }

            $tipo = $datos['tipo'] ?? null;

            if ($tipo === 'recoleccion') {
                $avisos = array_merge($avisos, $this->avisosDeRecoleccion($datos));

                continue;
            }

            if ($tipo !== 'parche' || ! isset($datos['huella'], $datos['estado'])) {
                $descartadas++;

                continue;
            }

            $pendientesDeEscribir[] = $datos;

            if (count($pendientesDeEscribir) >= $lote) {
                $escritura = $this->escribirLote($pendientesDeEscribir);
                $guardados += $escritura['guardados'];
                $ignorados += $escritura['ignorados'];
                $avisos = array_merge($avisos, $escritura['avisos']);
                $pendientesDeEscribir = [];
            }
        }

        $posicionFinal = ftell($manejador);
        fclose($manejador);

        if ($pendientesDeEscribir !== []) {
            $escritura = $this->escribirLote($pendientesDeEscribir);
            $guardados += $escritura['guardados'];
            $ignorados += $escritura['ignorados'];
            $avisos = array_merge($avisos, $escritura['avisos']);
        }

        $this->guardarMarcador($clave, [
            'desplazamiento' => $posicionFinal === false ? $desplazamiento : $posicionFinal,
            'tamano_anterior' => $tamano,
            'inodo' => $inodo,
            'lineas_procesadas' => (int) $marcador->lineas_procesadas + $guardados,
            'lineas_descartadas' => (int) $marcador->lineas_descartadas + $descartadas,
            'ultima_ejecucion' => CarbonImmutable::now(),
        ]);

        return [
            'guardados' => $guardados,
            'ignorados' => $ignorados,
            'descartadas' => $descartadas,
            'desplazamiento' => $posicionFinal === false ? $desplazamiento : (int) $posicionFinal,
            'avisos' => $avisos,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $registros
     * @return array{guardados: int, ignorados: int, avisos: array<int, string>}
     */
    private function escribirLote(array $registros): array
    {
        $huellas = array_column($registros, 'huella');

        // Se traen las filas existentes antes de escribir por un motivo concreto:
        // visto_pendiente_desde es la primera observacion del parche y no se puede perder
        // cuando pasa a aplicado. Es el unico dato de plazo que tienen los parches sin
        // fecha de publicacion, y se destruiria con un simple upsert ciego.
        $existentes = EstadoParche::query()
            ->whereIn('huella', $huellas)
            ->get()
            ->keyBy('huella');

        $guardados = 0;
        $ignorados = 0;
        $avisos = [];

        foreach ($registros as $registro) {
            $huella = (string) $registro['huella'];
            $existente = $existentes->get($huella);

            if ($registro['estado'] === EstadoParche::ESTADO_NO_APLICABLE) {
                // Cierre de un pendiente que desaparecio. Solo actualiza lo que ya existe:
                // esta linea no trae paquete ni version, asi que crear una fila con ella
                // dejaria un registro sin identidad en la tabla.
                if ($existente === null) {
                    $ignorados++;

                    continue;
                }

                $existente->estado = EstadoParche::ESTADO_NO_APLICABLE;
                $existente->nota_publicacion = $this->texto($registro['nota_publicacion'] ?? null, 2000);
                $existente->recolectado_en = $this->momento($registro['recolectado_en'] ?? null) ?? CarbonImmutable::now();
                $existente->recolectado_por = $this->texto($registro['recolectado_por'] ?? null, 190) ?? 'desconocido';
                $existente->save();
                $guardados++;

                continue;
            }

            $fila = $this->componerFila($registro, $existente, $avisos);

            if ($fila === null) {
                $ignorados++;

                continue;
            }

            EstadoParche::query()->updateOrCreate(['huella' => $huella], $fila);
            $guardados++;
        }

        return ['guardados' => $guardados, 'ignorados' => $ignorados, 'avisos' => $avisos];
    }

    /**
     * @param  array<string, mixed>  $registro
     * @param  array<int, string>  $avisos
     * @return array<string, mixed>|null
     */
    private function componerFila(array $registro, ?EstadoParche $existente, array &$avisos): ?array
    {
        $paquete = $this->texto($registro['paquete'] ?? null, 190);
        $version = $this->texto($registro['version'] ?? null, 120);
        $anfitrion = $this->texto($registro['anfitrion'] ?? null, 190);
        $recolectadoEn = $this->momento($registro['recolectado_en'] ?? null);

        // Sin paquete, version, anfitrion o momento de recoleccion la fila no es una
        // medicion: es ruido. Se descarta en lugar de rellenar los huecos.
        if ($paquete === null || $version === null || $anfitrion === null || $recolectadoEn === null) {
            return null;
        }

        $estado = (string) $registro['estado'];

        if (! array_key_exists($estado, EstadoParche::ETIQUETAS_ESTADO)) {
            return null;
        }

        $publicadoEn = $this->momento($registro['publicado_en'] ?? null);
        $aplicadoEn = $this->momento($registro['aplicado_en'] ?? null);

        $desfase = null;

        if ($publicadoEn !== null && $aplicadoEn !== null) {
            $desfase = round($publicadoEn->diffInSeconds($aplicadoEn, false) / 3600, 2);

            $declarado = $registro['desfase_horas'] ?? null;

            if (is_numeric($declarado) && abs((float) $declarado - $desfase) > self::TOLERANCIA_DESFASE_HORAS) {
                $avisos[] = sprintf(
                    '%s %s: el recolector declara %.2f h de desfase y las marcas de tiempo dan %.2f h. Se guarda la recalculada.',
                    $paquete,
                    $version,
                    (float) $declarado,
                    $desfase,
                );
            }
        }

        // La primera observacion como pendiente manda siempre: si ya la teniamos guardada,
        // el archivo no puede retrasarla. Es el instante en que el hecho quedo registrado.
        $vistoPendiente = $existente?->visto_pendiente_desde !== null
            ? CarbonImmutable::parse($existente->visto_pendiente_desde)
            : $this->momento($registro['visto_pendiente_desde'] ?? null);

        $cves = $registro['identificadores_cve'] ?? [];

        return [
            'anfitrion' => $anfitrion,
            'paquete' => $paquete,
            'arquitectura' => $this->texto($registro['arquitectura'] ?? null, 40),
            'version' => $version,
            'version_anterior' => $this->texto($registro['version_anterior'] ?? null, 120),
            'estado' => $estado,
            // Se conserva el nulo tal cual: nulo significa "no clasificado", y convertirlo
            // en falso lo sacaria del denominador de seguridad sin que nadie lo decidiera.
            'es_seguridad' => isset($registro['es_seguridad']) && $registro['es_seguridad'] !== null
                ? (bool) $registro['es_seguridad']
                : null,
            'origen_archivo' => $this->texto($registro['origen_archivo'] ?? null, 120),
            'publicado_en' => $publicadoEn,
            'fuente_publicacion' => $this->texto($registro['fuente_publicacion'] ?? null, 500),
            'nota_publicacion' => $this->texto($registro['nota_publicacion'] ?? null, 2000),
            'identificadores_cve' => is_array($cves) ? array_values(array_filter($cves, 'is_string')) : [],
            'aplicado_en' => $aplicadoEn,
            'fuente_aplicacion' => $this->texto($registro['fuente_aplicacion'] ?? null, 500),
            'aplicado_por' => $this->texto($registro['aplicado_por'] ?? null, 190),
            'visto_pendiente_desde' => $vistoPendiente,
            'desfase_horas' => $desfase,
            'recolectado_en' => $recolectadoEn,
            'recolectado_por' => $this->texto($registro['recolectado_por'] ?? null, 190) ?? 'desconocido',
            'version_recolector' => $this->texto($registro['version_recolector'] ?? null, 40),
        ];
    }

    /**
     * Una fuente que el recolector no pudo leer tiene que llegar hasta aqui.
     *
     * Si /var/log/unattended-upgrades queda ilegible por un permiso, el inventario sale
     * corto y la cobertura de parcheo baja: sin este aviso, la caida parece un problema de
     * parcheo cuando en realidad es un problema de lectura. Son dos hallazgos distintos.
     *
     * @param  array<string, mixed>  $datos
     * @return array<int, string>
     */
    private function avisosDeRecoleccion(array $datos): array
    {
        $avisos = [];

        foreach ($datos['fuentes'] ?? [] as $fuente) {
            if (is_array($fuente) && ($fuente['leida'] ?? true) === false) {
                $avisos[] = sprintf(
                    'El recolector no pudo leer %s: %s',
                    $fuente['ruta'] ?? 'una fuente sin nombre',
                    $fuente['detalle'] ?? 'sin detalle',
                );
            }
        }

        foreach ($datos['avisos'] ?? [] as $aviso) {
            if (is_string($aviso) && $aviso !== '') {
                $avisos[] = 'Recolector: '.$aviso;
            }
        }

        if (($datos['ejecutado_como_root'] ?? true) === false) {
            $avisos[] = 'El recolector no corrio como root: los registros de /var/log pueden haber quedado fuera.';
        }

        return $avisos;
    }

    private function mostrarResumen(): void
    {
        $metrica = app(AnalizadorParches::class)->metrica();

        $this->newLine();
        $this->line('  <options=bold>'.$metrica['nombre'].'</>');
        $this->line('  Meta:   '.$metrica['meta']);
        $this->line('  Valor:  '.$metrica['valor_texto'].'   ('.$metrica['estado'].', muestra de '.$metrica['muestra'].')');
        $this->line('  Origen: '.$metrica['origen']);

        if ($metrica['advertencia'] !== null) {
            $this->warn('  Aviso:  '.$metrica['advertencia']);
        }
    }

    private function resolverRuta(): string
    {
        $archivo = $this->option('archivo');

        if (is_string($archivo) && $archivo !== '') {
            return $archivo;
        }

        $configurada = config('siem.rutas.parches');

        return is_string($configurada) && $configurada !== '' ? $configurada : self::RUTA_POR_DEFECTO;
    }

    private function claveMarcador(string $archivo): string
    {
        return substr(hash('sha256', 'parches|'.$archivo), 0, 40);
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

    /**
     * El recolector escribe las fechas en ISO-8601 con desfase horario. Se llevan a UTC
     * porque la aplicacion trabaja en UTC: guardar la hora local del anfitrion desplazaria
     * seis horas cada plazo, que en una ventana de 72 h decide si cumple o no.
     */
    private function momento(mixed $valor): ?CarbonImmutable
    {
        if (! is_string($valor) || $valor === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($valor)->utc();
        } catch (Throwable $error) {
            return null;
        }
    }

    private function texto(mixed $valor, int $limite): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $valor = trim($valor);

        return $valor === '' ? null : mb_substr($valor, 0, $limite);
    }
}
