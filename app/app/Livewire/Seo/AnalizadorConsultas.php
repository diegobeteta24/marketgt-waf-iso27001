<?php

declare(strict_types=1);

namespace App\Livewire\Seo;

use App\Services\Seo\AnalizadorConsultas as ServicioAnalizadorConsultas;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Pantalla del análisis de consultas de búsqueda.
 *
 * El flujo es el que de verdad hace el responsable de un sitio: entra en Search Console,
 * exporta o copia la tabla de consultas, la pega aquí y pulsa analizar. No hay integración
 * con la interfaz de programación de Google a propósito: exigirla obligaría a dar de alta el
 * sitio, crear credenciales y mantenerlas, y el control dejaría de poder usarse el mismo día
 * en el que hace falta. Un pegado de texto no caduca ni se le vencen las credenciales.
 *
 * Analizar NO guarda nada. Se separa del registro por la misma razón que en el laboratorio de
 * contenido: hay que poder probar un pegado, cambiar el vocabulario y volver a probar sin
 * ensuciar el histórico con cada tanteo. Guardar es un acto deliberado y tiene su propio botón.
 */
class AnalizadorConsultas extends Component
{
    public string $pegado = '';

    public string $vocabulario = '';

    public string $marca = '';

    public string $dominioPropio = '';

    public int $minimoImpresiones = ServicioAnalizadorConsultas::MINIMO_IMPRESIONES;

    /**
     * Resultado del último análisis. Se guarda en la propiedad y no se recalcula en cada
     * render porque el pegado puede traer cientos de filas y la pantalla se redibuja con
     * cada tecla que se escribe en el vocabulario.
     *
     * @var array{filas: array<int, array<string, mixed>>, resumen: array<string, mixed>, contexto: array<string, mixed>}|null
     */
    public ?array $resultado = null;

    public bool $registrado = false;

    public function mount(): void
    {
        // La pantalla arranca cargada con el caso real. Es lo que permite abrir la página el
        // sábado, pulsar un botón y que se vea la detección, sin escribir nada en vivo.
        $this->cargarEjemplo();

        $this->dominioPropio = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function rules(): array
    {
        return [
            // El techo del pegado protege la petición, no la lógica: el servicio ya recorta
            // a 500 filas, pero un pegado de varios megabytes se descarta antes de recorrerlo.
            'pegado' => ['required', 'string', 'max:200000'],
            'vocabulario' => ['nullable', 'string', 'max:1000'],
            'marca' => ['nullable', 'string', 'max:100'],
            'dominioPropio' => ['nullable', 'string', 'max:255'],
            'minimoImpresiones' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'pegado' => 'consultas',
            'vocabulario' => 'vocabulario del negocio',
            'marca' => 'marca',
            'dominioPropio' => 'dominio propio',
            'minimoImpresiones' => 'mínimo de impresiones',
        ];
    }

    public function cargarEjemplo(): void
    {
        $servicio = app(ServicioAnalizadorConsultas::class);

        $this->pegado = $servicio->ejemploSearchConsole();
        $this->vocabulario = ServicioAnalizadorConsultas::VOCABULARIO_EJEMPLO;
        $this->marca = 'MarketGT';
        $this->resultado = null;
        $this->registrado = false;
    }

    public function limpiar(): void
    {
        $this->pegado = '';
        $this->resultado = null;
        $this->registrado = false;
    }

    public function analizar(ServicioAnalizadorConsultas $servicio): void
    {
        $this->validate();

        $this->registrado = false;

        $this->resultado = $servicio->analizar($this->pegado, [
            'vocabulario' => $this->vocabulario,
            'marca' => $this->marca,
            'dominio_propio' => $this->dominioPropio,
            'minimo_impresiones' => $this->minimoImpresiones,
        ]);

        $resumen = $this->resultado['resumen'];

        if ($resumen['recortado']) {
            Flux::toast(
                variant: 'warning',
                text: 'Se analizaron las primeras '.ServicioAnalizadorConsultas::LIMITE_FILAS.' consultas del pegado.',
            );

            return;
        }

        Flux::toast(
            variant: $resumen['envenenadas'] > 0 ? 'danger' : 'success',
            text: $resumen['envenenadas'] > 0
                ? $resumen['envenenadas'].' consultas envenenadas sobre '.$resumen['analizadas'].' analizadas.'
                : 'Ninguna consulta envenenada entre las '.$resumen['analizadas'].' analizadas.',
        );
    }

    /**
     * Guarda lo sospechoso y abre incidente por lo envenenado. Es el paso que conecta esta
     * pantalla con el resto del capítulo: el incidente aparece en el panel y la misma línea
     * se escribe en la bitácora que lee el motor de correlación del SIEM.
     */
    public function registrarHallazgos(ServicioAnalizadorConsultas $servicio): void
    {
        abort_unless(Auth::check(), 403);

        if ($this->resultado === null) {
            Flux::toast(variant: 'warning', text: 'Primero hay que analizar el pegado.');

            return;
        }

        $conteo = $servicio->registrar($this->resultado, [
            'ip' => request()->ip(),
            'agente_usuario' => request()->userAgent(),
            'ruta' => request()->path(),
            'usuario_id' => Auth::id(),
        ]);

        $this->registrado = $conteo['guardadas'] > 0;

        if ($conteo['fallidas'] > 0) {
            // Se avisa del fallo en lugar de enseñar un cero silencioso: si la tabla no está
            // o la base de datos no responde, quien mira la pantalla tiene que enterarse.
            Flux::toast(
                variant: 'danger',
                text: $conteo['guardadas'].' guardadas, '.$conteo['fallidas'].' no se pudieron guardar. Revise la bitácora.',
            );

            return;
        }

        Flux::toast(
            variant: $conteo['guardadas'] > 0 ? 'success' : 'warning',
            text: $conteo['guardadas'] > 0
                ? $conteo['guardadas'].' consultas guardadas y '.$conteo['incidentes'].' incidentes abiertos.'
                : 'No había ninguna consulta sospechosa que guardar.',
        );
    }

    public function render(): View
    {
        return view('livewire.seo.analizador-consultas');
    }
}
