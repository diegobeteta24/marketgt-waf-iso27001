<?php

namespace App\Livewire\Siem;

use App\Models\AlertaSeguridad;
use App\Models\EventoSeguridad;
use App\Models\User;
use App\Services\Siem\CalculadoraMetricas;
use App\Services\Siem\TriajeAsistido;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Acciones masivas de triaje para las alertas de trafico real.
 *
 * El lote automatico solo toca alertas nacidas de eventos sembrados. Todo lo demas lo decide
 * una persona, y revisar cincuenta alertas de una en una es la razon por la que en la
 * practica no se revisa ninguna. Esta pantalla da la via rapida sin regalar la trazabilidad:
 * cada alerta que cambia aqui queda con procedencia humana, con el nombre de quien la cambio
 * y con la nota que escribio.
 *
 * La nota es obligatoria al marcar falso positivo. Un falso positivo sin justificacion
 * escrita es indistinguible de una alerta cerrada para bajar el contador, y esa es
 * precisamente la sospecha que una auditoria viene a resolver.
 */
class AccionesMasivas extends Component
{
    #[Url(as: 'lote')]
    public string $filtroEstado = AlertaSeguridad::ESTADO_NUEVA;

    /**
     * Identificadores marcados en la lista. Llegan como texto desde las casillas.
     *
     * @var array<int, string>
     */
    public array $seleccionadas = [];

    public string $destino = '';

    public string $nota = '';

    /**
     * Las alertas de demostracion quedan fuera por defecto: para esas esta el comando, que
     * ademas deja constancia de que las decidio un proceso. Se pueden mostrar si alguien
     * quiere revisarlas a mano, y entonces contaran como triaje humano, que es lo correcto.
     */
    public bool $incluirDemostracion = false;

    public int $limite = 40;

    /**
     * Marca "todas las pendientes del filtro", no solo las visibles.
     *
     * Es una marca y no la lista de identificadores a proposito. Con cientos de alertas, la
     * lista viajaria desde el navegador en cada peticion, y cada identificador es un
     * argumento que el cortafuegos cuenta: ModSecurity rechaza por defecto las peticiones
     * de mas de mil argumentos. Una revision en lote no puede depender de que el WAF la
     * deje pasar. Los identificadores se resuelven en el servidor, en el momento de aplicar.
     */
    public bool $todasLasPendientes = false;

    /**
     * Minimo de caracteres de la nota de falso positivo. No es una cifra magica: es lo que
     * cuesta escribir "peticion legitima del rastreador de Google, verificada por PTR".
     */
    private const MINIMO_NOTA = 15;

    /**
     * A partir de cuantas alertas un lote exige nota escrita, sea cual sea el destino.
     *
     * Cuarenta es lo que cabe en pantalla. Por encima, quien aplica el lote no las ha visto
     * todas una por una, y lo honesto es que lo diga: que se reviso, con que criterio y por
     * que el resto comparte ese criterio. Esa nota es la diferencia entre una revision por
     * muestreo documentada y un contador bajado a ciegas.
     */
    private const LOTE_GRANDE = 40;

    /**
     * Misma comprobacion que el panel de triaje: la ruta exige rol, pero un componente de
     * Livewire recibe sus llamadas por su propio extremo y el middleware de la ruta no las ve.
     */
    private function exigirAnalista(): User
    {
        $usuario = Auth::user();

        abort_unless($usuario instanceof User && $usuario->tieneRol('admin', 'auditor'), 403);

        return $usuario;
    }

    public function updatedFiltroEstado(): void
    {
        $this->seleccionadas = [];
        $this->todasLasPendientes = false;
        $this->destino = '';
        unset($this->alertas, $this->totalPendientes);
    }

    public function updatedIncluirDemostracion(): void
    {
        $this->seleccionadas = [];
        $this->todasLasPendientes = false;
        unset($this->alertas, $this->totalPendientes);
    }

    /**
     * Tocar una casilla a mano vuelve a la seleccion explicita: quien desmarca una alerta
     * concreta no quiere que el lote la incluya igualmente.
     */
    public function updatedSeleccionadas(): void
    {
        $this->todasLasPendientes = false;
    }

    public function seleccionarTodas(): void
    {
        $this->todasLasPendientes = false;
        $this->seleccionadas = $this->alertas->pluck('id')
            ->map(static fn (int $id): string => (string) $id)
            ->all();
    }

    public function marcarTodasLasPendientes(): void
    {
        $this->todasLasPendientes = true;

        // Las visibles se marcan tambien, solo para que la pantalla lo refleje.
        $this->seleccionadas = $this->alertas->pluck('id')
            ->map(static fn (int $id): string => (string) $id)
            ->all();
    }

    public function limpiarSeleccion(): void
    {
        $this->todasLasPendientes = false;
        $this->seleccionadas = [];
    }

    /**
     * Cuantas alertas va a tocar el lote si se aplica ahora.
     */
    public function cantidadMarcada(): int
    {
        return $this->todasLasPendientes ? $this->totalPendientes : count($this->seleccionadas);
    }

    /**
     * @return array<int, int>
     */
    private function identificadoresDelLote(): array
    {
        if ($this->todasLasPendientes) {
            return $this->consultaPendientes()->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        }

        return array_map('intval', $this->seleccionadas);
    }

    /**
     * Aplica el cambio de estado a todas las alertas marcadas.
     *
     * Lo que no hace, y es deliberado: no fuerza transiciones. Si una alerta no admite el
     * destino elegido se queda fuera y se informa. Un lote que atropella el ciclo de vida
     * deja un historico en el que nadie puede afirmar que una alerta cerrada fue revisada.
     */
    public function aplicar(TriajeAsistido $triaje): void
    {
        $analista = $this->exigirAnalista();

        $cantidad = $this->cantidadMarcada();

        $this->validate(
            [
                // Con la marca de "todas las pendientes" la lista del navegador no manda:
                // se exige que haya al menos una pendiente, que se comprueba abajo.
                'seleccionadas' => $this->todasLasPendientes ? ['array'] : ['required', 'array', 'min:1'],
                'destino' => ['required', 'string', 'in:'.implode(',', array_keys(AlertaSeguridad::ETIQUETAS_ESTADO))],
                'nota' => $this->exigeNota()
                    ? ['required', 'string', 'min:'.self::MINIMO_NOTA]
                    : ['nullable', 'string'],
            ],
            [
                'seleccionadas.required' => 'Marque al menos una alerta.',
                'seleccionadas.min' => 'Marque al menos una alerta.',
                'destino.required' => 'Elija a que estado pasan las alertas marcadas.',
                'nota.required' => match (true) {
                    $this->destino === AlertaSeguridad::ESTADO_FALSO_POSITIVO => 'Para marcar falso positivo hay que escribir por que. Un triaje sin justificacion no es auditable.',
                    $this->destino === AlertaSeguridad::ESTADO_CERRADA => 'Para cerrar hay que escribir que se comprobo y por que no tuvo impacto.',
                    default => 'Un lote de '.$cantidad.' alertas exige justificacion escrita: que se reviso, con que criterio, '
                        .'y por que el resto lo comparte. Sin ella no se distingue una revision de un contador bajado a ciegas.',
                },
                'nota.min' => 'Explique el motivo con al menos '.self::MINIMO_NOTA.' caracteres: quien lea esto manana no estara en la sala.',
            ],
        );

        $identificadores = $this->identificadoresDelLote();

        if ($identificadores === []) {
            $this->addError('seleccionadas', 'No hay ninguna alerta pendiente con este filtro.');

            return;
        }

        /** @var Collection<int, AlertaSeguridad> $alertas */
        $alertas = AlertaSeguridad::query()->whereKey($identificadores)->get();

        $nota = trim($this->nota);
        $momento = CarbonImmutable::now();
        $aplicadas = 0;
        $omitidas = 0;

        // Una transaccion por lote: si algo falla a mitad, no queda la mitad de las alertas
        // con estado nuevo y la otra mitad sin procedencia registrada.
        DB::transaction(function () use ($alertas, $triaje, $analista, $nota, $momento, &$aplicadas, &$omitidas): void {
            foreach ($alertas as $alerta) {
                if (! in_array($this->destino, PanelAlertas::TRANSICIONES[$alerta->estado] ?? [], true)) {
                    $omitidas++;

                    continue;
                }

                // El cambio de estado lo hace el modelo: es quien sabe que marcas de tiempo
                // sellar. Aqui solo se anade la procedencia, que es lo que faltaba.
                $alerta->cambiarEstado($this->destino, $analista->getKey(), $nota !== '' ? $nota : null);

                $triaje->registrarTriajeHumano(
                    $alerta,
                    $analista,
                    'acciones masivas del panel de alertas',
                    $nota !== '' ? $nota : null,
                    $momento,
                );

                $aplicadas++;
            }
        });

        $this->seleccionadas = [];
        $this->todasLasPendientes = false;
        $this->nota = '';
        $this->destino = '';

        unset($this->alertas, $this->totalPendientes, $this->conteos, $this->porRegla);

        if ($aplicadas === 0) {
            Flux::toast(
                variant: 'danger',
                text: 'Ninguna alerta cambio: el estado elegido no es valido desde el estado actual de las marcadas.',
            );

            return;
        }

        Flux::toast(
            variant: 'success',
            text: $omitidas === 0
                ? $aplicadas.' alertas triadas y firmadas a su nombre.'
                : $aplicadas.' alertas triadas. '.$omitidas.' quedaron fuera por transicion no permitida.',
        );
    }

    /**
     * @return Collection<int, AlertaSeguridad>
     */
    #[Computed]
    public function alertas(): Collection
    {
        $consulta = $this->consultaPendientes()->with(['analista:id,name', 'usuarioObjetivo:id,name']);

        return $this->ordenarPorGravedad($consulta)
            ->orderByDesc('detectada_en')
            ->limit($this->limite)
            ->get();
    }

    /**
     * Ordena de lo mas grave a lo menos grave.
     *
     * Con CASE y no con FIELD(), que es propio de MariaDB: el conjunto de pruebas del
     * proyecto corre sobre SQLite, y una pantalla que no se puede renderizar en las pruebas
     * es una pantalla que nadie comprueba hasta que falla delante del tribunal. El orden sale
     * de ESCALA_SEVERIDAD para que no haya dos listas de severidades que puedan divergir.
     *
     * @param  Builder<AlertaSeguridad>  $consulta
     * @return Builder<AlertaSeguridad>
     */
    private function ordenarPorGravedad(Builder $consulta): Builder
    {
        $casos = [];
        $enlaces = [];

        foreach (EventoSeguridad::ESCALA_SEVERIDAD as $posicion => $severidad) {
            $casos[] = 'WHEN ? THEN '.$posicion;
            $enlaces[] = $severidad;
        }

        return $consulta->orderByRaw(
            'CASE severidad '.implode(' ', $casos).' ELSE '.count($enlaces).' END',
            $enlaces,
        );
    }

    #[Computed]
    public function totalPendientes(): int
    {
        return $this->consultaPendientes()->count();
    }

    /**
     * Cobertura de triaje partida por procedencia. Es la cifra que justifica esta pantalla:
     * sin ella, un cien por cien de cobertura no distingue el trabajo del turno del resultado
     * de haber ejecutado un comando.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function conteos(): array
    {
        [$desde, $hasta] = $this->ventanaObservacion();

        return app(TriajeAsistido::class)->conteosProcedencia($desde, $hasta);
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function porRegla(): array
    {
        [$desde, $hasta] = $this->ventanaObservacion();

        return app(TriajeAsistido::class)->conteosPorRegla($desde, $hasta);
    }

    /**
     * Las ultimas decisiones de triaje con su procedencia completa.
     *
     * Esta lista es la respuesta a la unica pregunta que importa del panel: de donde sale el
     * numero. Sin ella la evidencia se escribe en la base y no la ve nadie, que a efectos de
     * una auditoria es lo mismo que no haberla escrito.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function ultimasDecisiones(): array
    {
        $triaje = app(TriajeAsistido::class);

        if (! $triaje->procedenciaRegistrable()) {
            return [];
        }

        [$desde, $hasta] = $this->ventanaObservacion();

        return AlertaSeguridad::query()
            ->whereBetween('detectada_en', [$desde, $hasta])
            ->whereNotNull('procedencia_triaje')
            ->orderByDesc('triado_en')
            ->limit(5)
            ->get()
            ->map(static fn (AlertaSeguridad $alerta): array => [
                'alerta' => $alerta,
                'procedencia' => $triaje->procedenciaDe($alerta),
            ])
            ->all();
    }

    /**
     * Dias de la ventana de observacion, preguntados a la calculadora de metricas.
     *
     * No se escribe un treinta a mano aqui a proposito. Esta pantalla y el triangulo publican
     * la MISMA cifra con el mismo titulo; si cada una contara su propia ventana, el dia que
     * la calculadora cambiara la suya el panel mostraria dos coberturas distintas y nadie
     * podria decir cual de las dos responde a la pregunta.
     */
    public function diasObservacion(): int
    {
        return app(CalculadoraMetricas::class)->diasObservacion();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function ventanaObservacion(): array
    {
        $hasta = CarbonImmutable::now();

        return [$hasta->subDays($this->diasObservacion()), $hasta];
    }

    /**
     * @return array<int, array<string, string>>
     */
    #[Computed]
    public function criterios(): array
    {
        return TriajeAsistido::CRITERIOS;
    }

    /**
     * Destinos que ofrece el selector: los permitidos desde el estado de alguna de las alertas
     * marcadas. Se muestra la union y no la interseccion porque marcar veinte alertas de dos
     * estados distintos es normal; las que no admitan el destino se informan al aplicar.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function destinosPosibles(): array
    {
        $estados = match (true) {
            // Con todas las pendientes marcadas, los estados salen de la consulta completa,
            // no de las cuarenta que se ven.
            $this->todasLasPendientes => $this->consultaPendientes()->distinct()->pluck('estado')->all(),
            $this->seleccionadas === [] => [$this->filtroEstado === 'abiertas' ? AlertaSeguridad::ESTADO_NUEVA : $this->filtroEstado],
            default => AlertaSeguridad::query()
                ->whereKey(array_map('intval', $this->seleccionadas))
                ->distinct()
                ->pluck('estado')
                ->all(),
        };

        $destinos = [];

        foreach ($estados as $estado) {
            foreach (PanelAlertas::TRANSICIONES[$estado] ?? [] as $destino) {
                $destinos[$destino] = true;
            }
        }

        return array_keys($destinos);
    }

    public function exigeNota(): bool
    {
        // Cerrar tambien exige nota: es el cierre de lo que se reviso sin impacto, y sin
        // justificacion no se distingue de cerrar para bajar el contador.
        return in_array($this->destino, [AlertaSeguridad::ESTADO_FALSO_POSITIVO, AlertaSeguridad::ESTADO_CERRADA], true)
            || $this->cantidadMarcada() > self::LOTE_GRANDE;
    }

    /**
     * @return Builder<AlertaSeguridad>
     */
    private function consultaPendientes(): Builder
    {
        $consulta = AlertaSeguridad::query()
            ->when(
                $this->filtroEstado === 'abiertas',
                fn (Builder $c) => $c->whereIn('estado', AlertaSeguridad::ESTADOS_ABIERTOS),
                fn (Builder $c) => $c->where('estado', $this->filtroEstado),
            );

        if ($this->incluirDemostracion) {
            return $consulta;
        }

        // La definicion de "real" la pone el servicio, la misma que usan el comando y la
        // metrica. Repetirla aqui seria abrir la puerta a que el panel liste alertas que el
        // lote considera suyas, o al reves.
        return app(TriajeAsistido::class)->soloReales($consulta);
    }

    public function render(): View
    {
        return view('livewire.siem.acciones-masivas');
    }
}
