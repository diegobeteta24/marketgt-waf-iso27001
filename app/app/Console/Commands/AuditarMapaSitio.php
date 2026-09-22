<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HallazgoMapaSitio;
use App\Services\Seo\AuditorMapaSitio as ServicioAuditorMapaSitio;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Auditoría periódica del mapa del sitio y del archivo de exclusión de rastreadores.
 *
 * POR QUÉ EL COMANDO ES EL CONTROL DE VERDAD Y LA PANTALLA SOLO LA DEMOSTRACIÓN
 * ---------------------------------------------------------------------------
 * Nadie revisa a mano su mapa del sitio todos los días. La empresa del caso real llevaba
 * meses con páginas de apuestas alojadas bajo su dominio y se enteró mirando Search Console
 * por otra razón. Un control que depende de que alguien se acuerde de abrir una pantalla no
 * es un control de detección: es una esperanza.
 *
 * Lo que este comando aporta sobre seo:vigilar, que ya existe y no se duplica aquí:
 * aquel sella y compara el sitemap que la APLICACIÓN genera en memoria, y por tanto solo
 * puede ver lo que la aplicación produce. Este descarga lo que el sitio PUBLICADO entrega
 * de verdad, que es lo que lee Google, y sirve además para auditar un sitio ajeno —el de la
 * empresa donde se trabaja, por ejemplo— sin tener acceso a su código.
 *
 * ISO/IEC 27001:2022 -> A.8.9 (gestión de configuraciones), A.8.16 (seguimiento de
 * actividades), A.5.23 (servicios en la nube y activos expuestos).
 *
 * Programación sugerida (routes/console.php, lo hace el integrador):
 *     Schedule::command('seo:auditar-mapa')->hourly();
 *
 * Devuelve 1 cuando encuentra algo, para que la tarea programada lo trate como fallo y el
 * SIEM lo recoja sin tener que interpretar la salida.
 */
class AuditarMapaSitio extends Command
{
    protected $signature = 'seo:auditar-mapa
        {sitio? : Dirección del sitio a auditar; por omisión, la del sitio publicado}
        {--mapa= : Mapa concreto a auditar en lugar del que declare robots.txt}
        {--sin-comprobar : No pide ninguna página; solo analiza lo declarado}
        {--muestra= : Cuántas direcciones sospechosas se comprueban por HTTP}
        {--sellar : Guarda el estado actual como línea base autorizada}
        {--nota= : Justificación del sellado, para el registro de cambios}
        {--json : Salida en JSON, para encadenar con otra herramienta}';

    protected $description = 'Audita el mapa del sitio y el archivo de exclusión de rastreadores de un sitio publicado';

    public function handle(ServicioAuditorMapaSitio $auditor): int
    {
        // Sin argumento se audita el sitio publicado, no app.url: en el equipo de
        // desarrollo app.url es localhost y auditar localhost no dice nada sobre lo que
        // Google está viendo, que es la única pregunta que este comando contesta.
        $sitio = (string) ($this->argument('sitio') ?? $auditor->direccionPorDefecto());

        try {
            $auditoria = $auditor->auditar($sitio, [
                'comprobar_respuestas' => ! $this->option('sin-comprobar'),
                'muestra' => $this->option('muestra') !== null
                    ? (int) $this->option('muestra')
                    : ServicioAuditorMapaSitio::MUESTRA_COMPROBACION,
                'mapa' => $this->option('mapa') !== null ? (string) $this->option('mapa') : null,
            ]);
        } catch (Throwable $fallo) {
            $this->error('No se pudo auditar '.$sitio.': '.$fallo->getMessage());

            return self::FAILURE;
        }

        if ($this->option('sellar')) {
            return $this->sellar($auditor, $auditoria);
        }

        $escrito = ['hallazgos' => 0, 'incidentes' => 0];

        if (Schema::hasTable('hallazgos_mapa_sitio')) {
            try {
                $escrito = $auditor->registrar($auditoria);
            } catch (Throwable $fallo) {
                // El hallazgo ya está en la bitácora que escribe RegistroIncidentesSeo; que
                // falle la tabla no puede hacer que la tarea programada calle.
                $this->aviso('No se pudieron guardar los hallazgos: '.$fallo->getMessage());
            }
        } else {
            $this->aviso('Falta la tabla hallazgos_mapa_sitio: se analiza pero no se guarda la evidencia.');
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(
                $this->comoJson($auditoria, $escrito),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE,
            ));
        } else {
            $this->informar($auditoria, $escrito);
        }

        $graves = $this->graves($auditoria);

        return $graves === [] && $auditoria['exclusion']['hallazgos'] === [] && ! $auditoria['linea_base']['cambio']
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * Aviso que no puede ensuciar la salida encadenable.
     *
     * Con --json la salida tiene que ser JSON y nada más: escribir "Falta la tabla…" en el
     * mismo flujo rompía a quien la leyera con jq o la mandara al SIEM, que es justo para
     * lo que existe esa opción. El aviso no se pierde, se manda al flujo de error, donde
     * una persona lo sigue viendo en la terminal y una tubería no lo confunde con datos.
     */
    private function aviso(string $texto): void
    {
        if ($this->option('json')) {
            // getErrorStyle() devuelve el flujo de error cuando la salida es una consola de
            // verdad y se devuelve a sí mismo cuando no lo es (una prueba con búfer, por
            // ejemplo): el aviso nunca se pierde y en la terminal va donde tiene que ir.
            $this->output->getErrorStyle()->writeln('<comment>'.$texto.'</comment>');

            return;
        }

        $this->warn($texto);
    }

    /**
     * @param  array<string, mixed>  $auditoria
     */
    private function sellar(ServicioAuditorMapaSitio $auditor, array $auditoria): int
    {
        $graves = $this->graves($auditoria);

        // Sellar un mapa que ya está envenenado legitima el envenenamiento: a partir de ahí
        // las direcciones inyectadas dejan de ser "nuevas" y el control de cambio queda
        // ciego para siempre. Se exige decirlo en voz alta con --nota, no se impide: puede
        // haber un caso legítimo, pero tiene que quedar escrito quién lo decidió y por qué.
        if ($graves !== [] && (string) ($this->option('nota') ?? '') === '') {
            $this->error('Hay '.count($graves).' dirección(es) anómala(s) sin revisar. Sellar ahora las declararía autorizadas.');
            $this->line('Revise los hallazgos y, si aun así quiere sellar, repita con --nota="motivo".');

            return self::FAILURE;
        }

        $auditor->sellar($auditoria, $this->option('nota') !== null ? (string) $this->option('nota') : null);

        $this->info('Línea base del mapa de '.$auditoria['sitio'].' sellada con '.$auditoria['resumen']['total'].' direcciones.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $auditoria
     * @param  array{hallazgos: int, incidentes: int}  $escrito
     */
    private function informar(array $auditoria, array $escrito): void
    {
        $resumen = $auditoria['resumen'];

        $this->line('Auditoría del mapa del sitio · '.$auditoria['sitio'].' · '.$auditoria['momento']->format('d/m/Y H:i:s'));
        $this->newLine();

        $this->table(
            ['Mapa', 'Tipo', 'Entradas', 'Detalle'],
            array_map(
                static fn (array $m): array => [
                    mb_substr((string) $m['url'], 0, 60),
                    (string) $m['tipo'],
                    (string) $m['direcciones'],
                    mb_substr((string) $m['detalle'], 0, 40),
                ],
                $auditoria['mapas'],
            ),
        );

        $this->line(sprintf(
            '%d direcciones declaradas · %d limpias · %d sospechosas · %d anómalas · %d inyectadas y vivas · %d fantasma',
            $resumen['total'],
            $resumen['limpias'],
            $resumen['sospechosas'],
            $resumen['anomalas'],
            $resumen['inyectadas'],
            $resumen['fantasmas'],
        ));

        $graves = $this->graves($auditoria);

        if ($graves !== []) {
            $this->newLine();
            $this->table(
                ['Dirección', 'Puntos', 'Veredicto', 'HTTP', 'Reglas'],
                array_map(
                    static fn (array $d): array => [
                        // El final de la dirección y no el principio: el prefijo es igual en
                        // todo el sitio y lo que distingue a la inyectada está al final.
                        mb_strlen((string) $d['url']) > 58 ? '…'.mb_substr((string) $d['url'], -57) : (string) $d['url'],
                        (string) $d['puntuacion'],
                        HallazgoMapaSitio::ETIQUETAS_VEREDICTO[$d['veredicto']] ?? (string) $d['veredicto'],
                        $d['codigo_http'] === null ? '-' : (string) $d['codigo_http'],
                        mb_substr(implode(',', array_column($d['motivos'], 'regla')), 0, 46),
                    ],
                    array_slice($graves, 0, 25),
                ),
            );
        }

        $exclusion = $auditoria['exclusion'];

        $this->newLine();
        $this->line('Archivo de exclusión: '.$exclusion['url'].' · '.($exclusion['disponible'] ? $exclusion['bytes'].' bytes' : 'no disponible'));

        foreach ($exclusion['hallazgos'] as $hallazgo) {
            $this->warn('  ['.$hallazgo['regla'].' +'.$hallazgo['puntos'].'] '.$hallazgo['descripcion']);
            $this->line('    '.mb_substr((string) $hallazgo['evidencia'], 0, 120));
        }

        $base = $auditoria['linea_base'];

        $this->newLine();

        if (! $base['existe']) {
            $this->line('Sin línea base sellada: ejecute con --sellar para poder detectar el CAMBIO, que es donde está el valor de este control.');
        } elseif ($base['cambio'] || $base['exclusion_cambiada']) {
            $this->error(sprintf(
                'Cambio respecto de la línea base: %d direcciones nuevas y %d desaparecidas (antes %d, ahora %d).%s',
                count((array) $base['nuevas']),
                count((array) $base['desaparecidas']),
                (int) $base['cantidad_anterior'],
                (int) $base['cantidad_actual'],
                $base['crecimiento_subito'] ? ' El crecimiento no lo explica el ritmo de publicación del sitio.' : '',
            ));

            foreach (array_slice((array) $base['nuevas'], 0, 15) as $nueva) {
                $this->line('  + '.$nueva);
            }
        } else {
            $this->info('El mapa sigue siendo el autorizado.');
        }

        foreach ($auditoria['errores'] as $fallo) {
            $this->warn($fallo);
        }

        $this->newLine();
        $this->line($escrito['hallazgos'].' hallazgo(s) y '.$escrito['incidentes'].' incidente(s) escritos · corrida '.$auditoria['ejecucion'].' · '.$auditoria['duracion_ms'].' ms');
    }

    /**
     * @param  array<string, mixed>  $auditoria
     * @return array<int, array<string, mixed>>
     */
    private function graves(array $auditoria): array
    {
        return array_values(array_filter(
            $auditoria['direcciones'],
            static fn (array $d): bool => in_array($d['veredicto'], HallazgoMapaSitio::VEREDICTOS_GRAVES, true),
        ));
    }

    /**
     * Salida legible por una máquina, con la misma información que la tabla.
     *
     * Existe porque la auditoría se puede querer encadenar: pasar el resultado a un correo,
     * a un panel externo o al SIEM sin obligar a nadie a analizar el texto de una tabla de
     * consola, que es la forma más frágil de integrar dos herramientas.
     *
     * @param  array<string, mixed>  $auditoria
     * @param  array{hallazgos: int, incidentes: int}  $escrito
     * @return array<string, mixed>
     */
    private function comoJson(array $auditoria, array $escrito): array
    {
        return [
            'ejecucion' => $auditoria['ejecucion'],
            'momento' => $auditoria['momento']->toIso8601String(),
            'sitio' => $auditoria['sitio'],
            'resumen' => $auditoria['resumen'],
            'linea_base' => [
                'existe' => $auditoria['linea_base']['existe'],
                'cambio' => $auditoria['linea_base']['cambio'],
                'crecimiento_subito' => $auditoria['linea_base']['crecimiento_subito'],
                'cantidad_anterior' => $auditoria['linea_base']['cantidad_anterior'],
                'cantidad_actual' => $auditoria['linea_base']['cantidad_actual'],
                'nuevas' => $auditoria['linea_base']['nuevas'],
            ],
            'exclusion' => [
                'url' => $auditoria['exclusion']['url'],
                'disponible' => $auditoria['exclusion']['disponible'],
                'hallazgos' => $auditoria['exclusion']['hallazgos'],
            ],
            'direcciones' => array_map(
                static fn (array $d): array => [
                    'url' => $d['url'],
                    'puntuacion' => $d['puntuacion'],
                    'veredicto' => $d['veredicto'],
                    'codigo_http' => $d['codigo_http'],
                    'titulo_remoto' => $d['titulo_remoto'],
                    'reglas' => array_column($d['motivos'], 'regla'),
                ],
                $this->graves($auditoria),
            ),
            'errores' => $auditoria['errores'],
            'escrito' => $escrito,
            'duracion_ms' => $auditoria['duracion_ms'],
        ];
    }
}
