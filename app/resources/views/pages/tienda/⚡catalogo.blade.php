<?php

use App\Models\Carrito;
use App\Models\Categoria;
use App\Models\Producto;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('pages::tienda.layout')]
#[Title('Catálogo')]
class extends Component
{
    use WithPagination;

    /**
     * Término de búsqueda escrito por el visitante.
     *
     * Este campo es el blanco de la demostración de inyección SQL. Viaja en la URL para
     * que el ataque pueda reproducirse copiando y pegando el enlace, y para que quede
     * registrado en el diario de auditoría del WAF con la carga completa a la vista.
     */
    #[Url(as: 'q', except: '')]
    public string $busqueda = '';

    #[Url(as: 'categoria', except: '')]
    public string $categoria = '';

    #[Url(as: 'orden', except: 'recomendados')]
    public string $orden = 'recomendados';

    /**
     * Cualquier cambio de filtro devuelve al visitante a la primera página: de lo contrario
     * una búsqueda con tres resultados mostraría la página 7 vacía.
     */
    public function updatedBusqueda(): void
    {
        $this->resetPage();
    }

    public function updatedCategoria(): void
    {
        $this->resetPage();
    }

    public function updatedOrden(): void
    {
        $this->resetPage();
    }

    public function limpiarFiltros(): void
    {
        $this->reset('busqueda', 'categoria', 'orden');
        $this->resetPage();
    }

    /**
     * Consulta del catálogo.
     *
     * ---------------------------------------------------------------------------------
     * DEFENSA EN PROFUNDIDAD CONTRA INYECCIÓN SQL
     * ---------------------------------------------------------------------------------
     * Capa 4 (perímetro de aplicación): el WAF Nginx + ModSecurity con el OWASP Core Rule
     * Set 4.29 inspecciona la petición y la corta antes de que llegue a PHP cuando el
     * puntaje de anomalía alcanza el umbral 5.
     *
     * Capa 5 (esta línea de código): aunque el WAF fallara, estuviera en modo de solo
     * detección o alguien lo puenteara, la consulta se arma con el constructor de Eloquent
     * y el término del visitante viaja SIEMPRE como parámetro ligado de una sentencia
     * preparada. El motor de MariaDB recibe la cadena como dato, nunca como código: un
     * valor como  ' OR 1=1 --  se busca literalmente dentro del nombre del producto y
     * devuelve cero resultados, no el catálogo completo.
     *
     * Las dos capas son independientes: ninguna depende de que la otra funcione. Esa
     * independencia es justamente lo que se demuestra el sábado, apagando el WAF y
     * repitiendo el mismo ataque contra la aplicación desnuda.
     *
     * Por la misma razón el criterio de ordenamiento NO se interpola: el nombre de una
     * columna no se puede ligar como parámetro, así que se resuelve contra una lista
     * blanca cerrada en resolverOrden(). Un valor inesperado cae al orden por omisión.
     *
     * @return LengthAwarePaginator<int, Producto>
     */
    #[Computed]
    public function productos(): LengthAwarePaginator
    {
        $termino = trim($this->busqueda);

        $consulta = Producto::query()
            ->disponibles()
            ->with('categoria')
            ->when($termino !== '', function (Builder $consulta) use ($termino): void {
                // Se neutralizan los comodines de LIKE para que un visitante no pueda pedir
                // "%" y forzar un recorrido completo de la tabla (extracción masiva, regla
                // propia 15000-15099 del WAF).
                // La comparación la resuelve la intercalación utf8mb4_unicode_ci de MariaDB,
                // que ignora mayúsculas y tildes: "cafe" encuentra "Café" sin necesidad de
                // normalizar el texto en PHP.
                $patron = '%'.addcslashes($termino, '%_\\').'%';

                $consulta->where(function (Builder $sub) use ($patron): void {
                    $sub->where('nombre', 'like', $patron)
                        ->orWhere('descripcion', 'like', $patron);
                });
            })
            ->when($this->categoria !== '', function (Builder $consulta): void {
                $consulta->whereHas('categoria', function (Builder $sub): void {
                    $sub->where('slug', $this->categoria);
                });
            });

        foreach ($this->resolverOrden() as [$columna, $direccion]) {
            $consulta->orderBy($columna, $direccion);
        }

        return $consulta->paginate(12)->withQueryString();
    }

    /**
     * Lista blanca de ordenamientos permitidos.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function resolverOrden(): array
    {
        return match ($this->orden) {
            'precio-asc' => [['precio', 'asc']],
            'precio-desc' => [['precio', 'desc']],
            'nombre' => [['nombre', 'asc']],
            'recientes' => [['id', 'desc']],
            default => [['categoria_id', 'asc'], ['nombre', 'asc']],
        };
    }

    /** @return Collection<int, Categoria> */
    #[Computed]
    public function categorias(): Collection
    {
        return Categoria::query()->activas()->get();
    }

    #[Computed]
    public function hayFiltros(): bool
    {
        return $this->busqueda !== '' || $this->categoria !== '' || $this->orden !== 'recomendados';
    }

    /**
     * Agrega una unidad del producto al carrito del visitante.
     *
     * El identificador que llega del navegador no se cree: se vuelve a consultar el
     * producto contra la base y se revalidan precio y existencias del lado del servidor.
     * El precio jamás se toma del formulario, de lo contrario cualquiera podría comprar
     * un dije de jade por un quetzal manipulando la petición.
     */
    public function agregar(int $productoId): void
    {
        $producto = Producto::disponibles()->find($productoId);

        if ($producto === null) {
            Flux::toast(variant: 'danger', text: 'Ese producto ya no está disponible.');

            return;
        }

        $carrito = Carrito::actual(crear: true);
        $linea = $carrito->lineas()->firstOrNew(['producto_id' => $producto->id]);
        $cantidadPedida = (int) ($linea->cantidad ?? 0) + 1;

        if ($cantidadPedida > $producto->existencias) {
            Flux::toast(
                variant: 'danger',
                text: $producto->existencias === 0
                    ? 'Este producto está agotado.'
                    : 'Solo quedan '.$producto->existencias.' unidades de este producto.',
            );

            return;
        }

        $linea->cantidad = $cantidadPedida;
        $linea->precio_unitario = $producto->precio;
        $linea->save();

        $this->dispatch('carrito-actualizado');

        Flux::toast(variant: 'success', text: $producto->nombre.' se agregó al carrito.');
    }
}; ?>

<div class="space-y-6 sm:space-y-8">
    {{-- El relleno del encabezado se reduce en móvil: en 375 px cada píxel de ancho cuenta. --}}
    <section class="overflow-hidden rounded-2xl bg-linear-to-br from-emerald-600 to-teal-700 px-5 py-8 text-white sm:rounded-3xl sm:px-10 sm:py-14">
        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-100">Tienda en línea</p>
        <h1 class="mt-3 max-w-2xl text-2xl font-bold leading-tight tracking-tight sm:text-4xl">
            Lo mejor de Guatemala, de la mano de quien lo hace
        </h1>
        <p class="mt-3 max-w-xl text-sm text-emerald-50 sm:text-base">
            Café de altura, textiles de telar, jade del Motagua y tecnología para el día a día.
            Envíos a los veintidós departamentos.
        </p>
    </section>

    <section class="space-y-4">
        {{-- Filtros: una columna a ancho completo en el teléfono, buscador entero y los dos
             desplegables a la par desde sm, y la fila de doce columnas desde lg. --}}
        {{-- Los controles de Flux miden 40 px de alto; aquí se elevan a los 44 px reales
             que necesita el dedo, sin tocar el marcado del proveedor. --}}
        <div class="grid gap-3 [&_input]:min-h-11 [&_select]:min-h-11 sm:grid-cols-2 lg:grid-cols-12">
            <div class="sm:col-span-2 lg:col-span-6">
                <flux:input
                    wire:model.live.debounce.400ms="busqueda"
                    type="search"
                    icon="magnifying-glass"
                    placeholder="Buscar café, huipil, jade, audífonos..."
                    aria-label="Buscar productos en el catálogo"
                />
            </div>

            <div class="sm:col-span-1 lg:col-span-3">
                <flux:select wire:model.live="categoria" aria-label="Filtrar por categoría">
                    <flux:select.option value="">Todas las categorías</flux:select.option>
                    @foreach ($this->categorias as $categoria)
                        <flux:select.option value="{{ $categoria->slug }}">{{ $categoria->nombre }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="sm:col-span-1 lg:col-span-3">
                <flux:select wire:model.live="orden" aria-label="Ordenar resultados">
                    <flux:select.option value="recomendados">Recomendados</flux:select.option>
                    <flux:select.option value="precio-asc">Precio: de menor a mayor</flux:select.option>
                    <flux:select.option value="precio-desc">Precio: de mayor a menor</flux:select.option>
                    <flux:select.option value="nombre">Nombre: A &rarr; Z</flux:select.option>
                    <flux:select.option value="recientes">Novedades</flux:select.option>
                </flux:select>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="min-w-0 flex-1 break-words text-sm text-zinc-500 dark:text-zinc-400">
                {{ $this->productos->total() }}
                {{ \Illuminate\Support\Str::plural('producto', $this->productos->total()) }}
                @if (trim($this->busqueda) !== '')
                    {{-- Blade escapa el término: aquí también se detiene un intento de XSS
                         reflejado sobre el mismo campo de búsqueda.
                         break-all es indispensable: la carga útil de un ataque es una cadena
                         larguísima sin espacios y, sin cortarla, desborda la pantalla del
                         teléfono justo en la demostración del WAF. --}}
                    para <span class="break-all font-medium text-zinc-700 dark:text-zinc-200">&laquo;{{ trim($this->busqueda) }}&raquo;</span>
                @endif
            </p>

            @if ($this->hayFiltros)
                <flux:button size="sm" variant="ghost" icon="x-mark" class="min-h-11 shrink-0" wire:click="limpiarFiltros">
                    Limpiar filtros
                </flux:button>
            @endif
        </div>
    </section>

    @if ($this->productos->isEmpty())
        <section class="rounded-2xl border border-dashed border-zinc-300 bg-white px-4 py-12 text-center sm:px-6 sm:py-16 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-700">
                <flux:icon.magnifying-glass class="text-zinc-400" />
            </div>
            <h2 class="mt-4 text-base font-semibold text-zinc-900 dark:text-white">Sin resultados</h2>
            <p class="mx-auto mt-1 max-w-sm text-sm text-zinc-500 dark:text-zinc-400">
                No encontramos productos que coincidan con la búsqueda. Probá con otra palabra
                o quitá los filtros para ver el catálogo completo.
            </p>
            <div class="mt-5">
                <flux:button variant="primary" icon="arrow-path" class="min-h-11 w-full sm:w-auto" wire:click="limpiarFiltros">
                    Ver todo el catálogo
                </flux:button>
            </div>
        </section>
    @else
        {{-- Una columna en el teléfono, dos desde sm, tres desde md y cuatro desde lg.
             Antes eran dos columnas fijas incluso en 375 px y la tarjeta quedaba ilegible. --}}
        <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
            @foreach ($this->productos as $producto)
                <x-pages::tienda.tarjeta-producto :producto="$producto" />
            @endforeach
        </section>

        {{-- El paginador de Flux puede ser más ancho que la pantalla cuando hay muchas
             páginas: el contenedor absorbe el desplazamiento para que no lo haga la página.
             Además sus flechas miden 32 px, por debajo de los 44 px que
             necesita el dedo: se agrandan solo por debajo de md para no alterar el
             paginador de escritorio, que es marcado del proveedor y no se toca. --}}
        <div class="overflow-x-auto max-md:[&_button]:min-h-11 max-md:[&_button]:min-w-11">
            <flux:pagination :paginator="$this->productos" />
        </div>
    @endif
</div>
