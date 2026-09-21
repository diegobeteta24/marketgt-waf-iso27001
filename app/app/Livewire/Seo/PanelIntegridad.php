<?php

declare(strict_types=1);

namespace App\Livewire\Seo;

use App\Models\IncidenteSeo;
use App\Models\LineaBaseSeo;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

/**
 * Estado de la defensa contra ataques de posicionamiento.
 *
 * El panel contesta cuatro preguntas que un auditor hace en este orden: ¿hay ataques en
 * curso?, ¿de qué tipo?, ¿está sellada la línea base de los artefactos de indexación? y
 * ¿cuándo se comprobó por última vez? Si la respuesta a la última es "hace tres días", el
 * control existe en el papel y no en la realidad, y eso es exactamente lo que se busca
 * sacar a la luz.
 */
class PanelIntegridad extends Component
{
    /** Salida de la última ejecución manual de la vigilancia, para verla en pantalla. */
    public string $salidaVigilancia = '';

    public bool $vigilanciaCorrecta = true;

    public function ejecutarVigilancia(): void
    {
        abort_unless(Auth::check(), 403);

        try {
            // --sin-red en la ejecución desde el panel: la comprobación de las páginas
            // pide la portada por HTTP y, si el servidor web está ocupado sirviendo esta
            // misma petición con un solo proceso de PHP, se bloquearía a sí mismo. Desde
            // la tarea programada, que corre en su propio proceso, sí se comprueban.
            $codigo = Artisan::call('seo:vigilar', ['--sin-red' => true]);

            $this->salidaVigilancia = trim(Artisan::output());
            $this->vigilanciaCorrecta = $codigo === 0;

            unset($this->contadores, $this->lineasBase, $this->ultimosIncidentes);

            Flux::toast(
                variant: $codigo === 0 ? 'success' : 'danger',
                text: $codigo === 0
                    ? 'Integridad verificada: sin cambios respecto de la línea base.'
                    : 'Se detectaron cambios no autorizados. Revise los incidentes.',
            );
        } catch (Throwable $error) {
            $this->vigilanciaCorrecta = false;
            $this->salidaVigilancia = $error->getMessage();

            Flux::toast(variant: 'danger', text: 'No se pudo ejecutar la vigilancia.');
        }
    }

    /**
     * Sellar es declarar "este es el estado autorizado". Solo el administrador puede
     * hacerlo: si cualquiera pudiera sellar, un atacante con una cuenta de auditor
     * legitimaría su propio cambio y el control de integridad quedaría anulado.
     */
    public function sellarLineaBase(): void
    {
        $usuario = Auth::user();

        abort_unless($usuario !== null && $usuario->esAdministrador(), 403);

        try {
            Artisan::call('seo:vigilar', [
                '--sellar' => true,
                '--sin-red' => true,
                '--nota' => 'Sellado desde el panel por '.$usuario->name,
            ]);

            // El comando corre en consola y no conoce al usuario de la sesión. La firma se
            // completa aquí: una línea base sin responsable no sirve para auditar nada.
            LineaBaseSeo::query()->update(['sellada_por' => $usuario->getAuthIdentifier()]);

            $this->salidaVigilancia = trim(Artisan::output());
            $this->vigilanciaCorrecta = true;

            unset($this->lineasBase, $this->contadores);

            Flux::toast(variant: 'success', text: 'Línea base sellada y firmada.');
        } catch (Throwable $error) {
            $this->salidaVigilancia = $error->getMessage();

            Flux::toast(variant: 'danger', text: 'No se pudo sellar la línea base.');
        }
    }

    /**
     * @return array<string, int|string|null>
     */
    #[Computed]
    public function contadores(): array
    {
        if (! $this->tablasListas()) {
            return [];
        }

        $desde = Carbon::now()->subDay();

        return [
            'incidentes_24h' => (int) IncidenteSeo::query()->where('ultima_vez_en', '>=', $desde)->sum('repeticiones'),
            'abiertos' => IncidenteSeo::query()->abiertos()->count(),
            'rastreadores_falsos' => (int) IncidenteSeo::query()
                ->deTipo(IncidenteSeo::TIPO_CRAWLER_FALSIFICADO)
                ->where('ultima_vez_en', '>=', $desde)
                ->sum('repeticiones'),
            'contenido_retenido' => IncidenteSeo::query()
                ->deTipo(IncidenteSeo::TIPO_CONTENIDO_SPAM)
                ->where('ultima_vez_en', '>=', $desde)
                ->count(),
            'cloaking' => IncidenteSeo::query()
                ->deTipo(IncidenteSeo::TIPO_CLOAKING)
                ->where('ultima_vez_en', '>=', $desde)
                ->count(),
            'integridad_rota' => IncidenteSeo::query()
                ->whereIn('tipo', [IncidenteSeo::TIPO_INTEGRIDAD, IncidenteSeo::TIPO_SITEMAP_AJENO])
                ->abiertos()
                ->count(),
        ];
    }

    /**
     * @return Collection<int, LineaBaseSeo>
     */
    #[Computed]
    public function lineasBase(): Collection
    {
        if (! $this->tablasListas()) {
            /** @var Collection<int, LineaBaseSeo> $vacia */
            $vacia = new Collection;

            return $vacia;
        }

        return LineaBaseSeo::query()->with('firmante:id,name')->orderBy('artefacto')->get();
    }

    /**
     * @return Collection<int, IncidenteSeo>
     */
    #[Computed]
    public function ultimosIncidentes(): Collection
    {
        if (! $this->tablasListas()) {
            /** @var Collection<int, IncidenteSeo> $vacia */
            $vacia = new Collection;

            return $vacia;
        }

        return IncidenteSeo::query()
            ->orderByDesc('ultima_vez_en')
            ->limit(6)
            ->get();
    }

    /**
     * Última vez que alguien comprobó de verdad. Un control de detección que nadie ejecuta
     * es un control que no existe, y esta fecha es la que lo delata sin discusión.
     */
    #[Computed]
    public function ultimaComprobacion(): ?Carbon
    {
        if (! $this->tablasListas()) {
            return null;
        }

        /** @var Carbon|null $momento */
        $momento = LineaBaseSeo::query()->max('updated_at');

        return $momento === null ? null : Carbon::parse($momento);
    }

    #[Computed]
    public function tablasListas(): bool
    {
        return Schema::hasTable('incidentes_seo') && Schema::hasTable('lineas_base_seo');
    }

    public function render(): View
    {
        return view('livewire.seo.panel-integridad');
    }
}
