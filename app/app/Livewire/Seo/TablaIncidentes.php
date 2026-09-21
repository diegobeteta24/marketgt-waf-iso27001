<?php

declare(strict_types=1);

namespace App\Livewire\Seo;

use App\Models\IncidenteSeo;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Triaje de los incidentes de posicionamiento.
 *
 * Cada incidente tiene dueño, estado y fecha de revisión. La diferencia entre una tabla de
 * hallazgos y un control operado es exactamente esta pantalla: sin ella, nadie puede
 * afirmar ante el auditor que alguien miró el hallazgo del martes.
 */
class TablaIncidentes extends Component
{
    #[Url(as: 'tipo')]
    public string $tipoFiltro = '';

    #[Url(as: 'estado')]
    public string $estadoFiltro = 'abiertos';

    public ?int $seleccionado = null;

    public string $notas = '';

    public int $limite = 30;

    /**
     * Transiciones admitidas. Un ciclo de vida sin reglas deja el histórico inservible:
     * nadie podría demostrar que un incidente cerrado fue antes revisado.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSICIONES = [
        IncidenteSeo::ESTADO_NUEVO => [
            IncidenteSeo::ESTADO_CONFIRMADO,
            IncidenteSeo::ESTADO_FALSO_POSITIVO,
        ],
        IncidenteSeo::ESTADO_CONFIRMADO => [
            IncidenteSeo::ESTADO_CERRADO,
            IncidenteSeo::ESTADO_FALSO_POSITIVO,
        ],
        IncidenteSeo::ESTADO_FALSO_POSITIVO => [],
        IncidenteSeo::ESTADO_CERRADO => [],
    ];

    public function seleccionar(int $incidenteId): void
    {
        if ($this->seleccionado === $incidenteId) {
            $this->seleccionado = null;
            $this->notas = '';

            return;
        }

        $this->seleccionado = $incidenteId;

        // value() y no find(): solo hace falta una columna, y así no se hidrata el modelo
        // entero con su evidencia JSON nada más desplegar la fila.
        $this->notas = (string) (IncidenteSeo::query()->whereKey($incidenteId)->value('notas') ?? '');
    }

    public function cambiarEstado(int $incidenteId, string $nuevoEstado): void
    {
        // La ruta ya exige rol de administrador o auditor; esta comprobación es la segunda
        // línea, por si este componente se monta mañana desde otra página sin ese filtro.
        abort_unless(Auth::check(), 403);

        $incidente = IncidenteSeo::query()->findOrFail($incidenteId);

        if (! in_array($nuevoEstado, self::TRANSICIONES[$incidente->estado] ?? [], true)) {
            Flux::toast(variant: 'danger', text: 'Esa transición no está permitida desde el estado actual.');

            return;
        }

        $incidente->estado = $nuevoEstado;
        $incidente->revisado_por = (int) Auth::id();
        $incidente->revisado_en = Carbon::now();
        $incidente->notas = trim($this->notas) === '' ? $incidente->notas : trim($this->notas);
        $incidente->save();

        unset($this->incidentes, $this->conteos);

        Flux::toast(
            variant: 'success',
            text: 'Incidente '.$incidente->id.' marcado como '.$incidente->etiquetaEstado().'.',
        );
    }

    public function guardarNotas(int $incidenteId): void
    {
        abort_unless(Auth::check(), 403);

        $incidente = IncidenteSeo::query()->findOrFail($incidenteId);
        $incidente->notas = trim($this->notas) === '' ? null : trim($this->notas);
        $incidente->revisado_por = (int) Auth::id();
        $incidente->save();

        unset($this->incidentes);

        Flux::toast(variant: 'success', text: 'Nota de triaje guardada.');
    }

    /**
     * @return Collection<int, IncidenteSeo>
     */
    #[Computed]
    public function incidentes(): Collection
    {
        if (! Schema::hasTable('incidentes_seo')) {
            /** @var Collection<int, IncidenteSeo> $vacia */
            $vacia = new Collection;

            return $vacia;
        }

        $consulta = IncidenteSeo::query()->with(['revisor:id,name', 'usuario:id,name']);

        if ($this->estadoFiltro === 'abiertos') {
            $consulta->abiertos();
        } elseif ($this->estadoFiltro !== '' && $this->estadoFiltro !== 'todos') {
            $consulta->where('estado', $this->estadoFiltro);
        }

        if ($this->tipoFiltro !== '') {
            $consulta->deTipo($this->tipoFiltro);
        }

        return $consulta
            // La gravedad manda sobre la hora: un cloaking de hace veinte minutos importa
            // más que un contenido retenido de hace dos.
            ->orderByRaw("FIELD(severidad, 'critica', 'alta', 'media', 'baja', 'informativa')")
            ->orderByDesc('ultima_vez_en')
            ->limit($this->limite)
            ->get();
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function conteos(): array
    {
        if (! Schema::hasTable('incidentes_seo')) {
            return [];
        }

        $filas = IncidenteSeo::query()
            ->selectRaw('tipo, COUNT(*) as total')
            ->groupBy('tipo')
            ->pluck('total', 'tipo');

        $conteos = [];

        foreach (array_keys(IncidenteSeo::ETIQUETAS_TIPO) as $tipo) {
            $conteos[$tipo] = (int) ($filas[$tipo] ?? 0);
        }

        return $conteos;
    }

    /**
     * @return array<int, string>
     */
    public function transicionesDe(IncidenteSeo $incidente): array
    {
        return self::TRANSICIONES[$incidente->estado] ?? [];
    }

    public function render(): View
    {
        return view('livewire.seo.tabla-incidentes');
    }
}
