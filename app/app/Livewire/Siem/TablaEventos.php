<?php

namespace App\Livewire\Siem;

use App\Models\EventoSeguridad;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tabla de los ultimos eventos con la regla que se activo y la puntuacion de anomalia.
 *
 * Los filtros viajan en la barra de direcciones para que un analista pueda pasarle a otro
 * el enlace exacto de lo que esta viendo. En un turno de guardia eso ahorra media llamada.
 */
class TablaEventos extends Component
{
    use WithPagination;

    #[Url(as: 'fuente')]
    public string $fuente = '';

    #[Url(as: 'severidad')]
    public string $severidad = '';

    #[Url(as: 'bloqueo')]
    public string $bloqueo = '';

    #[Url(as: 'ip')]
    public string $direccionIp = '';

    #[Url(as: 'q')]
    public string $busqueda = '';

    #[Url(as: 'ventana')]
    public int $horas = 24;

    public int $porPagina = 25;

    /**
     * @var array<int, int>
     */
    public const VENTANAS = [1, 6, 24, 72, 168];

    public function mount(): void
    {
        if (! in_array($this->horas, self::VENTANAS, true)) {
            $this->horas = 24;
        }
    }

    /**
     * Cualquier cambio de filtro devuelve a la primera pagina: quedarse en la pagina siete
     * de un resultado que ahora tiene dos paginas es el clasico "no hay eventos" falso.
     */
    public function updated(string $propiedad, mixed $valor = null): void
    {
        if ($propiedad !== 'page') {
            $this->resetPage();
        }
    }

    public function limpiarFiltros(): void
    {
        $this->reset(['fuente', 'severidad', 'bloqueo', 'direccionIp', 'busqueda']);
        $this->horas = 24;
        $this->resetPage();
    }

    public function filtrarPorIp(string $direccion): void
    {
        $this->direccionIp = $direccion;
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, EventoSeguridad>
     */
    #[Computed]
    public function eventos(): LengthAwarePaginator
    {
        return $this->consulta()
            ->with('usuario:id,name')
            ->orderByDesc('marca_tiempo')
            ->orderByDesc('id')
            ->paginate($this->porPagina);
    }

    #[Computed]
    public function totalFiltrado(): int
    {
        return $this->consulta()->count();
    }

    /**
     * @return Builder<EventoSeguridad>
     */
    private function consulta(): Builder
    {
        $consulta = EventoSeguridad::query()
            ->where('marca_tiempo', '>=', Carbon::now()->subHours($this->horas));

        if ($this->fuente !== '') {
            $consulta->where('fuente', $this->fuente);
        }

        if ($this->severidad !== '') {
            $consulta->where('severidad', $this->severidad);
        }

        if ($this->bloqueo !== '') {
            $consulta->where('fue_bloqueado', $this->bloqueo === 'bloqueados');
        }

        if (trim($this->direccionIp) !== '') {
            $consulta->where('direccion_ip', 'like', trim($this->direccionIp).'%');
        }

        if (trim($this->busqueda) !== '') {
            $termino = '%'.trim($this->busqueda).'%';

            $consulta->where(function (Builder $interna) use ($termino): void {
                $interna->where('ruta', 'like', $termino)
                    ->orWhere('mensaje', 'like', $termino)
                    ->orWhere('identificador_transaccion', 'like', $termino)
                    ->orWhere('identificadores_regla', 'like', $termino);
            });
        }

        return $consulta;
    }

    /**
     * @return array<string, string>
     */
    public function fuentesDisponibles(): array
    {
        return [
            EventoSeguridad::FUENTE_WAF => 'WAF (ModSecurity)',
            EventoSeguridad::FUENTE_APLICACION => 'Aplicacion (Laravel)',
            EventoSeguridad::FUENTE_SISTEMA => 'Sistema (fail2ban / SSH)',
        ];
    }

    public function render(): View
    {
        return view('livewire.siem.tabla-eventos');
    }
}
