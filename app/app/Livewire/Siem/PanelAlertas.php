<?php

namespace App\Livewire\Siem;

use App\Models\AlertaSeguridad;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Panel de triaje. Es el componente que responde a la brecha que senala el anexo del
 * proyecto: hay herramienta desplegada pero no esta definido quien revisa las alertas.
 *
 * Aqui cada alerta tiene dueno, estado y marca de tiempo. La diferencia entre un SIEM de
 * demostracion y uno operado es exactamente esta pantalla.
 */
class PanelAlertas extends Component
{
    #[Url(as: 'estado')]
    public string $estadoFiltro = 'abiertas';

    #[Url(as: 'gravedad')]
    public string $severidadFiltro = '';

    public ?int $alertaSeleccionada = null;

    public string $notas = '';

    public int $limite = 25;

    /**
     * Transiciones permitidas desde cada estado. Un ciclo de vida sin reglas deja el
     * historico inservible: nadie podria afirmar que una alerta cerrada fue antes revisada.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSICIONES = [
        AlertaSeguridad::ESTADO_NUEVA => [
            AlertaSeguridad::ESTADO_EN_TRIAJE,
            AlertaSeguridad::ESTADO_FALSO_POSITIVO,
        ],
        AlertaSeguridad::ESTADO_EN_TRIAJE => [
            AlertaSeguridad::ESTADO_CONTENIDA,
            AlertaSeguridad::ESTADO_FALSO_POSITIVO,
            AlertaSeguridad::ESTADO_CERRADA,
        ],
        AlertaSeguridad::ESTADO_CONTENIDA => [
            AlertaSeguridad::ESTADO_CERRADA,
        ],
        AlertaSeguridad::ESTADO_CERRADA => [],
        AlertaSeguridad::ESTADO_FALSO_POSITIVO => [],
    ];

    public function seleccionar(int $alertaId): void
    {
        if ($this->alertaSeleccionada === $alertaId) {
            $this->alertaSeleccionada = null;
            $this->notas = '';

            return;
        }

        $this->alertaSeleccionada = $alertaId;
        $this->notas = (string) (AlertaSeguridad::query()->find($alertaId)?->notas_triaje ?? '');
    }

    /**
     * Mueve una alerta por su ciclo de vida dejando constancia de quien lo hizo.
     */
    public function cambiarEstado(int $alertaId, string $nuevoEstado): void
    {
        // La ruta ya exige el rol de auditor o administrador; esta comprobacion es la segunda
        // linea, por si el componente se monta manana desde otra pagina sin ese middleware.
        abort_unless(Auth::check(), 403);

        $alerta = AlertaSeguridad::query()->findOrFail($alertaId);

        if (! in_array($nuevoEstado, self::TRANSICIONES[$alerta->estado] ?? [], true)) {
            Flux::toast(variant: 'danger', text: 'Esa transicion no esta permitida desde el estado actual.');

            return;
        }

        $alerta->cambiarEstado($nuevoEstado, Auth::id(), $this->notas);

        unset($this->alertas, $this->conteosPorEstado);

        Flux::toast(
            variant: 'success',
            text: 'Alerta '.$alerta->id.' marcada como '.$alerta->etiquetaEstado().'.',
        );
    }

    public function guardarNotas(int $alertaId): void
    {
        abort_unless(Auth::check(), 403);

        $alerta = AlertaSeguridad::query()->findOrFail($alertaId);
        $alerta->notas_triaje = trim($this->notas) === '' ? null : trim($this->notas);
        $alerta->atendida_por = Auth::id();
        $alerta->save();

        unset($this->alertas);

        Flux::toast(variant: 'success', text: 'Nota de triaje guardada.');
    }

    /**
     * @return Collection<int, AlertaSeguridad>
     */
    #[Computed]
    public function alertas(): Collection
    {
        $consulta = AlertaSeguridad::query()
            ->with(['analista:id,name', 'usuarioObjetivo:id,name']);

        if ($this->estadoFiltro === 'abiertas') {
            $consulta->abiertas();
        } elseif ($this->estadoFiltro !== '' && $this->estadoFiltro !== 'todas') {
            $consulta->where('estado', $this->estadoFiltro);
        }

        if ($this->severidadFiltro !== '') {
            $consulta->where('severidad', $this->severidadFiltro);
        }

        return $consulta
            ->orderByRaw("FIELD(severidad, 'critica', 'alta', 'media', 'baja', 'informativa')")
            ->orderByDesc('detectada_en')
            ->limit($this->limite)
            ->get();
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function conteosPorEstado(): array
    {
        $filas = AlertaSeguridad::query()
            ->selectRaw('estado, COUNT(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        $conteos = [];

        foreach (array_keys(AlertaSeguridad::ETIQUETAS_ESTADO) as $estado) {
            $conteos[$estado] = (int) ($filas[$estado] ?? 0);
        }

        return $conteos;
    }

    /**
     * @return array<int, string>
     */
    public function transicionesDe(AlertaSeguridad $alerta): array
    {
        return self::TRANSICIONES[$alerta->estado] ?? [];
    }

    public function render(): View
    {
        return view('livewire.siem.panel-alertas');
    }
}
