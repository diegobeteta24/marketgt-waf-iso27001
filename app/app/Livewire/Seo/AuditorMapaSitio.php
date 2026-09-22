<?php

declare(strict_types=1);

namespace App\Livewire\Seo;

use App\Models\HallazgoMapaSitio;
use App\Services\Seo\AuditorMapaSitio as ServicioAuditorMapaSitio;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Auditoría del mapa del sitio y del archivo de exclusión de rastreadores.
 *
 * La pantalla tiene dos mitades y las dos hacen falta:
 *
 *   ARRIBA, el banco de pruebas. Se pegan consultas o direcciones y se ve el veredicto al
 *   teclear, sin descargar nada. Viene cargado con las consultas REALES del panel de Search
 *   Console de una empresa guatemalteca de cámaras de seguridad que está comprometida ahora
 *   mismo. Es la mitad que se puede demostrar en tres minutos delante de un tribunal: la
 *   auditoría completa necesita un sitio envenenado de verdad para enseñar algo, y esto no.
 *
 *   ABAJO, la auditoría de verdad. Descarga el mapa del sitio y robots.txt de la dirección
 *   que se indique, analiza cada dirección declarada, comprueba una muestra contra el
 *   servidor y compara todo contra la línea base sellada.
 *
 * Auditar SÍ registra: a diferencia del laboratorio de contenido, aquí no se está probando
 * una carga inventada, se está mirando un sitio real, y un hallazgo sobre un sitio real que
 * no queda escrito es un hallazgo que se pierde.
 */
#[Layout('layouts.app')]
#[Title('Auditoría del mapa del sitio')]
class AuditorMapaSitio extends Component
{
    public string $sitio = '';

    /** Mapa concreto a auditar. Vacío significa "el que declare robots.txt". */
    public string $mapa = '';

    public bool $comprobarRespuestas = true;

    /**
     * Resultado de la última auditoría, ya convertido a valores planos.
     *
     * El servicio devuelve objetos de fecha y Livewire tiene que poder serializar el estado
     * entre peticiones; guardar el resultado crudo rompía la pantalla en cuanto el mapa
     * traía un lastmod.
     *
     * @var array<string, mixed>|null
     */
    public ?array $resultado = null;

    public string $error = '';

    // --- Banco de pruebas -----------------------------------------------------

    public string $consultas = '';

    public string $vocabulario = '';

    public function mount(): void
    {
        $this->sitio = (string) config('app.url');

        // Las cinco marcas reales que aparecían en el panel de Search Console de la empresa
        // comprometida, escritas como aparecerían dentro del mapa del sitio: si Google
        // mostraba ese dominio para "p9bet login", es porque había una dirección bajo ese
        // dominio que hablaba de eso, y al rastreador se le llega por el mapa.
        //
        // Las dos últimas son direcciones legítimas del catálogo, y están aquí a propósito:
        // un control solo se puede defender si se ve TAMBIÉN lo que no marca. Una lista
        // donde todo sale en rojo no demuestra que el detector funcione, demuestra que
        // grita.
        $this->consultas = implode("\n", [
            'https://ejemplo-comprometido.gt/p9bet-login/',
            'https://ejemplo-comprometido.gt/0016bet',
            'https://ejemplo-comprometido.gt/96n.com/index.html',
            'https://ejemplo-comprometido.gt/kmj888',
            'https://ejemplo-comprometido.gt/porh300',
            'https://ejemplo-comprometido.gt/camaras-de-seguridad-guatemala',
            'https://ejemplo-comprometido.gt/servicios/mantenimiento-de-camaras',
        ]);

        // El vocabulario legítimo del negocio. Es lo que permite decidir que "porh300" es
        // ajeno: no aparece en ninguna lista negra del mundo, pero no comparte una sola
        // palabra con lo que este sitio vende, y eso sí se puede medir.
        $this->vocabulario = implode("\n", [
            'camaras de seguridad guatemala',
            'mantenimiento de camaras de seguridad',
            'instalacion de circuito cerrado de television',
            'videovigilancia alarmas monitoreo',
        ]);
    }

    // -------------------------------------------------------------------------
    // Banco de pruebas
    // -------------------------------------------------------------------------

    /**
     * Veredicto de cada línea escrita arriba. Es propiedad calculada y no acción porque el
     * campo se escribe en vivo: se teclea y la pantalla responde.
     *
     * @return array<int, array{texto: string, puntuacion: int, veredicto: string, motivos: array<int, array{regla: string, descripcion: string, puntos: int, evidencia: string}>}>
     */
    #[Computed]
    public function banco(): array
    {
        $servicio = app(ServicioAuditorMapaSitio::class);
        $propias = $servicio->normalizarVocabulario($this->lineas($this->vocabulario));

        $veredictos = [];

        foreach ($this->lineas($this->consultas) as $linea) {
            $veredictos[] = $servicio->analizarTexto($linea, $propias);
        }

        return $veredictos;
    }

    // -------------------------------------------------------------------------
    // Auditoría
    // -------------------------------------------------------------------------

    public function auditar(ServicioAuditorMapaSitio $auditor): void
    {
        abort_unless(Auth::check(), 403);

        $this->error = '';
        $this->resultado = null;

        try {
            $auditoria = $auditor->auditar($this->sitio, [
                'comprobar_respuestas' => $this->comprobarRespuestas,
                'muestra' => ServicioAuditorMapaSitio::MUESTRA_COMPROBACION,
                'mapa' => trim($this->mapa) === '' ? null : trim($this->mapa),
            ]);
        } catch (Throwable $fallo) {
            $this->error = $fallo->getMessage();

            Flux::toast(variant: 'danger', text: 'No se pudo auditar: '.$fallo->getMessage());

            return;
        }

        $escrito = ['hallazgos' => 0, 'incidentes' => 0];

        try {
            $escrito = $auditor->registrar($auditoria, [
                'ip' => request()->ip(),
                'agente_usuario' => request()->userAgent(),
                'usuario_id' => Auth::id(),
            ]);
        } catch (Throwable $fallo) {
            // Que falle la escritura no puede borrar el análisis de la pantalla: el
            // operador tiene delante el hallazgo aunque la tabla no lo haya aceptado.
            $this->error = 'El análisis se completó pero no se pudo guardar: '.$fallo->getMessage();
        }

        $this->resultado = $this->paraPantalla($auditoria, $escrito);

        unset($this->historial);

        $graves = (int) $this->resultado['resumen']['anomalas']
            + (int) $this->resultado['resumen']['inyectadas']
            + (int) $this->resultado['resumen']['fantasmas'];

        Flux::toast(
            variant: $graves > 0 || $this->resultado['resumen']['hallazgos_exclusion'] > 0 ? 'danger' : 'success',
            text: $graves > 0
                ? $graves.' dirección(es) anómala(s) en el mapa de '.$this->resultado['sitio']
                : 'Mapa del sitio sin anomalías: '.$this->resultado['resumen']['total'].' direcciones revisadas.',
        );
    }

    /**
     * Declara el estado actual como autorizado.
     *
     * Solo el administrador, y con confirmación: sellar un mapa que ya está envenenado
     * legitima el envenenamiento, porque a partir de ahí las direcciones inyectadas dejan
     * de ser "nuevas" y el control de cambio queda ciego para siempre.
     */
    public function sellarLineaBase(ServicioAuditorMapaSitio $auditor): void
    {
        $usuario = Auth::user();

        abort_unless($usuario !== null && $usuario->esAdministrador(), 403);

        if ($this->resultado === null) {
            Flux::toast(variant: 'warning', text: 'Audite primero: no hay nada que sellar.');

            return;
        }

        try {
            // Se vuelve a auditar en vez de sellar lo que hay en pantalla. El resultado
            // mostrado puede ser de hace media hora, y sellar una fotografía vieja escribe
            // como autorizado un estado que ya nadie está mirando.
            $auditoria = $auditor->auditar($this->sitio, [
                'comprobar_respuestas' => false,
                'mapa' => trim($this->mapa) === '' ? null : trim($this->mapa),
            ]);

            $auditor->sellar(
                $auditoria,
                'Sellado desde el panel por '.$usuario->name,
                $usuario->getAuthIdentifier(),
            );

            $this->resultado = $this->paraPantalla($auditoria, ['hallazgos' => 0, 'incidentes' => 0]);

            Flux::toast(variant: 'success', text: 'Línea base del mapa sellada y firmada.');
        } catch (Throwable $fallo) {
            $this->error = $fallo->getMessage();

            Flux::toast(variant: 'danger', text: 'No se pudo sellar la línea base.');
        }
    }

    /**
     * Hallazgos graves guardados de auditorías anteriores. Es la memoria del control: sin
     * ella, cada pasada empieza de cero y el CAMBIO —que es lo que delata la inyección—
     * no se puede ver.
     *
     * @return array<int, HallazgoMapaSitio>
     */
    #[Computed]
    public function historial(): array
    {
        if (! $this->tablaLista()) {
            return [];
        }

        return HallazgoMapaSitio::query()
            ->graves()
            ->when(
                $this->resultado !== null,
                fn ($consulta) => $consulta->where('ejecucion', '!=', (string) $this->resultado['ejecucion']),
            )
            ->orderByDesc('created_at')
            ->limit(15)
            ->get()
            ->all();
    }

    #[Computed]
    public function tablaLista(): bool
    {
        return Schema::hasTable('hallazgos_mapa_sitio');
    }

    public function render(): View
    {
        return view('livewire.seo.auditor-mapa-sitio');
    }

    // -------------------------------------------------------------------------
    // Utilidades
    // -------------------------------------------------------------------------

    /**
     * Convierte el resultado del servicio en valores que Livewire pueda llevar y traer.
     *
     * @param  array<string, mixed>  $auditoria
     * @param  array{hallazgos: int, incidentes: int}  $escrito
     * @return array<string, mixed>
     */
    private function paraPantalla(array $auditoria, array $escrito): array
    {
        $direcciones = array_map(
            static fn (array $d): array => [
                'url' => (string) $d['url'],
                'origen' => (string) $d['origen'],
                'profundidad' => (int) $d['profundidad'],
                'lastmod' => $d['lastmod'] === null ? null : (string) $d['lastmod'],
                'puntuacion' => (int) $d['puntuacion'],
                'veredicto' => (string) $d['veredicto'],
                'motivos' => $d['motivos'],
                'codigo_http' => $d['codigo_http'] === null ? null : (int) $d['codigo_http'],
                'titulo_remoto' => $d['titulo_remoto'],
                'destino_final' => $d['destino_final'],
            ],
            // La pantalla solo pinta lo que tiene alguna señal. Las direcciones limpias son
            // el 99 % del mapa y su única información útil es el recuento, que ya está en
            // el resumen: listarlas obligaría a buscar la aguja a mano.
            array_values(array_filter(
                $auditoria['direcciones'],
                static fn (array $d): bool => (int) $d['puntuacion'] >= ServicioAuditorMapaSitio::UMBRAL_SOSPECHA,
            )),
        );

        $base = $auditoria['linea_base'];

        return [
            'ejecucion' => (string) $auditoria['ejecucion'],
            'momento' => $auditoria['momento'] instanceof Carbon
                ? $auditoria['momento']->format('d/m/Y H:i:s')
                : (string) $auditoria['momento'],
            'sitio' => (string) $auditoria['sitio'],
            'base' => (string) $auditoria['base'],
            'mapas' => $auditoria['mapas'],
            'exclusion' => [
                'url' => (string) $auditoria['exclusion']['url'],
                'disponible' => (bool) $auditoria['exclusion']['disponible'],
                'bytes' => (int) $auditoria['exclusion']['bytes'],
                'sitemaps_declarados' => $auditoria['exclusion']['sitemaps_declarados'],
                'hallazgos' => $auditoria['exclusion']['hallazgos'],
            ],
            'direcciones' => array_slice($direcciones, 0, 120),
            'resumen' => $auditoria['resumen'],
            'linea_base' => [
                'existe' => (bool) $base['existe'],
                'cambio' => (bool) $base['cambio'],
                'crecimiento_subito' => (bool) $base['crecimiento_subito'],
                'exclusion_cambiada' => (bool) $base['exclusion_cambiada'],
                'cantidad_anterior' => $base['cantidad_anterior'],
                'cantidad_actual' => (int) $base['cantidad_actual'],
                'nuevas' => array_slice((array) $base['nuevas'], 0, 25),
                'desaparecidas' => array_slice((array) $base['desaparecidas'], 0, 10),
                'sellada_en' => $base['sellada_en'] instanceof Carbon
                    ? $base['sellada_en']->format('d/m/Y H:i')
                    : null,
            ],
            'errores' => $auditoria['errores'],
            'duracion_ms' => (int) $auditoria['duracion_ms'],
            'escrito' => $escrito,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function lineas(string $texto): array
    {
        $lineas = [];

        foreach (preg_split('/\R/', $texto) ?: [] as $linea) {
            $linea = trim((string) $linea);

            if ($linea !== '') {
                // Tope defensivo: el campo es libre y una pasada del detector por cada
                // línea de un pegado de mil filas dejaría la pantalla colgada.
                $lineas[] = mb_substr($linea, 0, 300);
            }

            if (count($lineas) >= 40) {
                break;
            }
        }

        return $lineas;
    }
}
