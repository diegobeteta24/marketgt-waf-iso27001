<?php

declare(strict_types=1);

namespace App\Livewire\Seguridad;

use App\Models\AsistenciaCapacitacion;
use App\Models\Capacitacion;
use App\Models\User;
use App\Services\Siem\CalculadoraMetricas;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Registro de capacitaciones: el control A.6.3 dejando de vivir en una carpeta.
 *
 * El panel existe por una razon muy concreta y no por completar el tablero. La metrica de
 * personal capacitado no se puede medir porque las asistencias se anotan en un documento
 * aparte, y los expedientes de formacion acaban siendo ficcion cuando anotar un hecho cuesta
 * diez pasos. Aqui cuesta dos clics: elegir la sesion y pulsar el resultado de cada persona.
 *
 * Nada de lo que se ve aqui se supone. Si no hay asistencias registradas, la metrica se
 * declara sin datos y el panel dice cuantas faltan.
 */
class PanelCapacitacion extends Component
{
    /**
     * Sesion abierta para pasar lista. Null significa que ninguna lo esta.
     */
    public ?int $sesionSeleccionada = null;

    /**
     * Puntuacion escrita para cada persona, indexada por identificador de usuario. Es
     * opcional: POL-006 admite registrar la asistencia el mismo dia y evaluar despues.
     *
     * @var array<int, string>
     */
    public array $puntuaciones = [];

    /**
     * Fecha en que se impartio la sesion que se esta sellando. Se pregunta en lugar de usar
     * "ahora" porque el acta se suele pasar al sistema al dia siguiente, y fechar la sesion
     * el dia en que se tecleo desplazaria la vigencia de todo el equipo.
     */
    public string $fechaImpartida = '';

    public string $modalidad = 'presencial';

    /**
     * @var array<string, string>
     */
    public const MODALIDADES = [
        'presencial' => 'Presencial',
        'remota' => 'Remota',
    ];

    public function mount(): void
    {
        $this->fechaImpartida = CarbonImmutable::now()->toDateString();
    }

    /**
     * Segunda linea de control sobre todo lo que escribe.
     *
     * La ruta ya exige rol de administrador o auditor, pero un componente de Livewire recibe
     * sus llamadas por su propio extremo: quien monte manana este panel en otra pagina, o
     * invoque el metodo a mano, se saltaria ese middleware. Y aqui se escribe la evidencia de
     * un control: permitir que un cliente de la tienda se declare capacitado seria regalar
     * precisamente la metrica que este panel existe para medir.
     */
    private function exigirResponsable(): User
    {
        $usuario = Auth::user();

        abort_unless($usuario instanceof User && $usuario->tieneRol('admin', 'auditor'), 403);

        /** @var User $usuario */
        return $usuario;
    }

    public function seleccionar(int $capacitacionId): void
    {
        $this->sesionSeleccionada = $this->sesionSeleccionada === $capacitacionId ? null : $capacitacionId;
        $this->puntuaciones = [];

        $sesion = $this->sesionSeleccionada === null
            ? null
            : Capacitacion::query()->find($this->sesionSeleccionada);

        if ($sesion instanceof Capacitacion) {
            $this->modalidad = $sesion->modalidad ?? 'presencial';
            $this->fechaImpartida = $sesion->impartida_en?->toDateString() ?? CarbonImmutable::now()->toDateString();

            // Las notas que ya constan vuelven a su caja. Sin esto, reconfirmar el resultado
            // de alguien enviaba la caja vacia y borraba en silencio su puntuacion, que es
            // justamente el dato que POL-006 seccion 6 exige por asistente: el acta perdia
            // evidencia por la via de volver a confirmarla.
            foreach (AsistenciaCapacitacion::query()->where('capacitacion_id', $sesion->id)->get() as $registro) {
                if ($registro->puntuacion !== null) {
                    $this->puntuaciones[$registro->usuario_id] = (string) $registro->puntuacion;
                }
            }
        }
    }

    /**
     * Sella el hecho de que la sesion ocurrio. Hasta este momento la sesion es un plan, y
     * ninguna asistencia suya puede acreditar nada: la vigencia se cuenta desde aqui.
     */
    public function marcarImpartida(int $capacitacionId): void
    {
        $responsable = $this->exigirResponsable();

        $this->validate(
            ['fechaImpartida' => ['required', 'date', 'before_or_equal:today']],
            [
                'fechaImpartida.before_or_equal' => 'Una sesion no se puede dar por impartida en el futuro: '
                    .'la fecha es la del hecho, no la del plan.',
            ],
        );

        $sesion = Capacitacion::query()->findOrFail($capacitacionId);

        if ($sesion->estado === Capacitacion::ESTADO_CANCELADA) {
            Flux::toast(variant: 'danger', text: 'La sesion esta cancelada: reactivela antes de darla por impartida.');

            return;
        }

        // La hora del dia no se conoce y no se inventa: se sella el mediodia de la fecha
        // declarada. La vigencia se cuenta en meses, de modo que la hora no cambia ninguna
        // cifra, y fingir una hora exacta si daria una precision que el acta no tiene.
        $sesion->impartida_en = CarbonImmutable::parse($this->fechaImpartida)->setTime(12, 0);
        $sesion->estado = Capacitacion::ESTADO_IMPARTIDA;
        $sesion->modalidad = array_key_exists($this->modalidad, self::MODALIDADES) ? $this->modalidad : null;
        $sesion->registrada_por = $responsable->id;
        $sesion->actor = $responsable->name.' <'.$responsable->email.'>';
        $sesion->registrada_en = CarbonImmutable::now();
        $sesion->save();

        $this->olvidarCalculos();

        Flux::toast(
            variant: 'success',
            text: 'Sesion '.$sesion->codigo.' marcada como impartida el '.$sesion->impartida_en->format('d/m/Y').'.',
        );
    }

    /**
     * Devuelve una sesion a estado planificado.
     *
     * Existe porque equivocarse de fecha es lo mas facil del mundo y la alternativa —dejar
     * el error puesto— contamina la vigencia de todo el equipo. Las asistencias NO se borran:
     * siguen ahi, dejan de acreditar mientras la sesion no tenga fecha, y vuelven a contar
     * cuando se sella la correcta. Borrarlas convertiria una correccion en una perdida de
     * evidencia.
     */
    public function devolverAPlanificada(int $capacitacionId): void
    {
        $responsable = $this->exigirResponsable();

        $sesion = Capacitacion::query()->findOrFail($capacitacionId);

        $sesion->impartida_en = null;
        $sesion->estado = Capacitacion::ESTADO_PLANIFICADA;
        $sesion->registrada_por = $responsable->id;
        $sesion->actor = $responsable->name.' <'.$responsable->email.'>';
        $sesion->registrada_en = CarbonImmutable::now();
        $sesion->save();

        $this->olvidarCalculos();

        Flux::toast(variant: 'warning', text: 'Sesion '.$sesion->codigo.' devuelta a planificada. Las asistencias se conservan.');
    }

    /**
     * Anota el resultado de una persona en la sesion abierta. Este es el hecho que la
     * metrica cuenta, y el unico camino por el que entra.
     */
    public function registrar(int $usuarioId, string $resultado): void
    {
        $responsable = $this->exigirResponsable();

        // Sin sesion abierta no hay nada que anotar. Se devuelve un aviso en lugar de un 404
        // porque este caso lo produce un panel que se quedo abierto mientras otra persona
        // corregia la sesion, no un intento de manipulacion.
        $sesion = $this->sesionSeleccionada === null
            ? null
            : Capacitacion::query()->find($this->sesionSeleccionada);

        if (! $sesion instanceof Capacitacion) {
            Flux::toast(variant: 'danger', text: 'Elija primero la sesion a la que corresponde la asistencia.');

            return;
        }

        $persona = User::query()->findOrFail($usuarioId);

        if (! $sesion->fueImpartida()) {
            Flux::toast(
                variant: 'danger',
                text: 'Primero marque la sesion como impartida: una asistencia a una sesion que no ocurrio no es un hecho.',
            );

            return;
        }

        if (! array_key_exists($resultado, AsistenciaCapacitacion::ETIQUETAS_RESULTADO)) {
            Flux::toast(variant: 'danger', text: 'Resultado desconocido.');

            return;
        }

        $puntuacion = $this->puntuacionDe($usuarioId);

        if ($puntuacion === false) {
            Flux::toast(variant: 'danger', text: 'La puntuacion debe ser un numero entero entre 0 y 100.');

            return;
        }

        // Una nota es el resultado de una evaluacion. Guardarla junto a "asistio, sin evaluar"
        // dejaba una fila que afirmaba las dos cosas a la vez —hay puntuacion, no hubo
        // evaluacion— y el panel la mostraba como "Asistio, sin evaluar - 95 %". El acta
        // tiene que decir una sola cosa, y cual de las dos lo sabe quien estuvo alli.
        if ($puntuacion !== null && $resultado === AsistenciaCapacitacion::RESULTADO_PENDIENTE) {
            Flux::toast(
                variant: 'danger',
                text: 'Hay una nota escrita: eso es un resultado de evaluacion. Pulse "Supero" o "No supero", '
                    .'o borre la nota para anotar solo la presencia.',
            );

            return;
        }

        // Coherencia entre la nota y el veredicto. Si el acta dice 60 y alguien pulsa
        // "supero", una de las dos cosas es falsa, y el panel no elige cual: lo devuelve.
        if ($puntuacion !== null && $resultado === AsistenciaCapacitacion::RESULTADO_SUPERADA && $puntuacion < $sesion->umbral_aprobacion) {
            Flux::toast(
                variant: 'danger',
                text: 'Puntuacion '.$puntuacion.' por debajo del umbral de '.$sesion->umbral_aprobacion.' %: no se puede registrar como superada.',
            );

            return;
        }

        if ($puntuacion !== null && $resultado === AsistenciaCapacitacion::RESULTADO_NO_SUPERADA && $puntuacion >= $sesion->umbral_aprobacion) {
            Flux::toast(
                variant: 'danger',
                text: 'Puntuacion '.$puntuacion.' alcanza el umbral de '.$sesion->umbral_aprobacion.' %: revise el resultado antes de registrarlo.',
            );

            return;
        }

        AsistenciaCapacitacion::registrar(
            capacitacion: $sesion,
            persona: $persona,
            datos: [
                // La funcion se copia de los roles que la cuenta tiene HOY, que es un hecho
                // de la base con su fecha de concesion, y queda congelada en el acta.
                'funcion' => $this->funcionDe($persona),
                'asistio' => true,
                'modalidad' => $sesion->modalidad,
                'resultado' => $resultado,
                'puntuacion' => $puntuacion,
            ],
            actor: $responsable,
        );

        $this->olvidarCalculos();

        Flux::toast(
            variant: 'success',
            text: $persona->name.': '.AsistenciaCapacitacion::ETIQUETAS_RESULTADO[$resultado].' en '.$sesion->codigo.'.',
        );
    }

    /**
     * Retira una asistencia mal atribuida.
     *
     * Solo para el caso real de haber pulsado en la fila equivocada. Se deja explicito que
     * es un borrado y no una correccion: corregir el resultado se hace volviendo a pulsar.
     */
    public function retirarAsistencia(int $usuarioId): void
    {
        $this->exigirResponsable();

        if ($this->sesionSeleccionada === null) {
            return;
        }

        AsistenciaCapacitacion::query()
            ->where('capacitacion_id', $this->sesionSeleccionada)
            ->where('usuario_id', $usuarioId)
            ->delete();

        $this->olvidarCalculos();

        Flux::toast(variant: 'warning', text: 'Asistencia retirada.');
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function medicion(): array
    {
        return Capacitacion::medicionPersonalCapacitado();
    }

    /**
     * La metrica con la misma forma que produce CalculadoraMetricas, para que el panel la
     * pinte igual que a las demas del triangulo y el integrador pueda copiarla sin traducir.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function metrica(): array
    {
        $medicion = $this->medicion();
        $meta = (float) config('siem.metas.personal_capacitado_porcentaje', 100);

        if (! $medicion['medible']) {
            return [
                'clave' => 'personal_capacitado',
                'nombre' => 'Personal capacitado',
                'meta' => $this->textoMeta($meta),
                'valor' => null,
                'valor_texto' => 'sin datos',
                'estado' => CalculadoraMetricas::SIN_DATOS,
                'muestra' => 0,
                'origen' => $medicion['origen'],
                'advertencia' => $medicion['advertencia'],
            ];
        }

        $porcentaje = (float) $medicion['porcentaje'];

        return [
            'clave' => 'personal_capacitado',
            'nombre' => 'Personal capacitado',
            'meta' => $this->textoMeta($meta),
            'valor' => $porcentaje,
            'valor_texto' => number_format($porcentaje, $porcentaje == (int) $porcentaje ? 0 : 1).' %',
            'estado' => $porcentaje >= $meta ? CalculadoraMetricas::CUMPLE : CalculadoraMetricas::INCUMPLE,
            'muestra' => (int) $medicion['muestra'],
            'origen' => $medicion['origen'],
            'advertencia' => $medicion['advertencia'],
        ];
    }

    /**
     * @return Collection<int, Capacitacion>
     */
    #[Computed]
    public function sesiones(): Collection
    {
        return Capacitacion::query()
            ->withCount(['asistencias', 'asistencias as asistencias_superadas_count' => fn ($consulta) => $consulta->where('evaluacion_superada', true)])
            ->orderByRaw('impartida_en IS NULL')
            ->orderByDesc('impartida_en')
            ->orderBy('codigo')
            ->get();
    }

    /**
     * El equipo, que es el denominador. Se lee de la base —cuentas activas con rol de
     * equipo— y no de una lista escrita a mano, para que nadie pueda reducir el denominador
     * y subir el porcentaje sin tocar ninguna concesion de rol.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function equipo(): Collection
    {
        return Capacitacion::equipo();
    }

    #[Computed]
    public function sesion(): ?Capacitacion
    {
        return $this->sesionSeleccionada === null
            ? null
            : Capacitacion::query()->find($this->sesionSeleccionada);
    }

    /**
     * Asistencias ya registradas en la sesion abierta, por identificador de usuario.
     *
     * @return array<int, AsistenciaCapacitacion>
     */
    #[Computed]
    public function asistenciasDeLaSesion(): array
    {
        if ($this->sesionSeleccionada === null) {
            return [];
        }

        return AsistenciaCapacitacion::query()
            ->where('capacitacion_id', $this->sesionSeleccionada)
            ->get()
            ->keyBy('usuario_id')
            ->all();
    }

    public function funcionDe(User $persona): ?string
    {
        $etiquetas = $persona->etiquetasRoles();

        return $etiquetas === [] ? null : implode(' · ', $etiquetas);
    }

    /**
     * Lee la puntuacion escrita para una persona.
     *
     * Devuelve null cuando no se escribio ninguna —que es valido, POL-006 admite registrar
     * presencia y evaluar despues— y false cuando lo escrito no es una nota, para que quien
     * llama distinga "no hay dato" de "el dato es invalido" y no los trate igual.
     */
    private function puntuacionDe(int $usuarioId): int|false|null
    {
        $valor = trim((string) ($this->puntuaciones[$usuarioId] ?? ''));

        if ($valor === '') {
            return null;
        }

        if (! ctype_digit($valor)) {
            return false;
        }

        $numero = (int) $valor;

        return $numero >= 0 && $numero <= 100 ? $numero : false;
    }

    private function textoMeta(float $meta): string
    {
        return number_format($meta, 0).' % del equipo con la capacitacion del semestre vigente';
    }

    /**
     * Las propiedades calculadas se guardan por peticion: tras escribir hay que olvidarlas o
     * el panel seguiria mostrando la cifra anterior justo despues de corregirla.
     */
    private function olvidarCalculos(): void
    {
        unset($this->medicion, $this->metrica, $this->sesiones, $this->sesion, $this->asistenciasDeLaSesion);
    }

    public function render(): View
    {
        return view('livewire.seguridad.panel-capacitacion');
    }
}
