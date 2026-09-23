<?php

namespace App\Console\Commands;

use App\Models\PruebaRestauracion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use JsonException;

/**
 * Registra una prueba de restauracion cronometrada, que es lo unico que demuestra el RTO y
 * el RPO que declara el anexo del proyecto.
 *
 * Reparto de responsabilidades, y no es casual:
 *
 *   infra/scripts/probar-restauracion.sh    EJECUTA y mide. Vive donde estan las
 *                                           herramientas: mariadb-dump, gpg y docker, que
 *                                           no existen dentro del contenedor de PHP.
 *   este comando                            JUZGA y guarda. Recalcula el veredicto a partir
 *                                           de los hechos medidos en vez de creerse el que
 *                                           trae el acta, deriva el tiempo de recuperacion
 *                                           y sella la procedencia.
 *
 * Quien ejecuta la prueba no se pone la nota a si mismo. Si el acta dice "satisfactoria"
 * pero las filas no cuadran o el cifrador no resulto ser AES-256, aqui se registra como
 * fallida y se dice por que.
 */
class ProbarRestauracion extends Command
{
    protected $signature = 'siem:probar-restauracion
        {--registrar= : Registra un acta en JSON ya producida por el guion. "-" la lee de la entrada estandar}
        {--guion= : Ruta de infra/scripts/probar-restauracion.sh}
        {--conexion= : Conexion de base de datos que se prueba. Por defecto la predeterminada}
        {--contenedor= : Contenedor de MariaDB cuando los clientes no estan en el PATH}
        {--respaldos= : Directorio de los respaldos reales, de donde sale el punto de recuperacion}
        {--frase= : Frase de cifrado del volcado. Mejor por SIEM_FRASE_RESPALDO que por la linea de ordenes}
        {--admin-usuario= : Cuenta con permiso para crear y borrar la base de prueba}
        {--admin-clave= : Clave de esa cuenta. Mejor por SIEM_BD_ADMIN_CLAVE}
        {--origen= : manual, programado o guion. Por defecto guion al registrar y manual al ejecutar}
        {--usuario= : Identificador o correo de la persona que pidio la prueba}
        {--tiempo-maximo=1800 : Segundos maximos que se le conceden al guion}
        {--conservar : No borra el volcado cifrado al terminar}
        {--json : Imprime el acta registrada en JSON}';

    protected $description = 'Ejecuta o registra una prueba de restauracion cronometrada y de ahi salen el RTO y el RPO medidos';

    /**
     * La salida del guion se guarda como acta. Se recorta porque una restauracion con miles
     * de avisos de MariaDB llenaria la columna sin aportar nada al hallazgo.
     */
    private const LIMITE_SALIDA = 60000;

    /**
     * El cifrador que declara el documento del proyecto. El acta trae el nombre que el guion
     * LEYO del archivo con "gpg --list-packets", y es ese nombre el que decide, no la casilla
     * de conforme que el acta traiga marcada: un volcado en AES-128 con la casilla puesta a
     * mano es exactamente el hallazgo que un auditor busca.
     */
    private const CIFRADOR_EXIGIDO = 'AES256';

    public function handle(): int
    {
        $registrar = $this->option('registrar');

        $origen = $this->resolverOrigen($registrar !== null);

        if ($origen === null) {
            $this->error('Origen desconocido. Use manual, programado o guion.');

            return self::INVALID;
        }

        $operador = $this->resolverOperador();

        if ($operador === false) {
            return self::INVALID;
        }

        $acta = $registrar !== null
            ? $this->leerActa((string) $registrar)
            : $this->ejecutarGuion();

        if ($acta === null) {
            return self::FAILURE;
        }

        // El instante de arranque no se sustituye por "ahora". Contra el se mide el punto de
        // recuperacion, de modo que rellenarlo con la hora del registro publicaria un RPO
        // medido contra un instante que nadie observo: un numero inventado con aspecto de dato.
        $iniciada = $this->momento($acta['iniciada_en'] ?? null);

        if ($iniciada === null) {
            $this->error('El acta no trae un "iniciada_en" legible.');
            $this->line('Ese sello es el instante contra el que se mide el punto de recuperacion. Sin el, la');
            $this->line('antiguedad del respaldo se estaria midiendo contra la hora del registro, que es otra');
            $this->line('cosa. Vuelva a ejecutar el guion en lugar de registrar un acta incompleta.');

            return self::INVALID;
        }

        $prueba = $this->registrar($acta, $iniciada, $origen, $operador);

        $this->informar($prueba);

        if ($this->option('json')) {
            $this->line((string) json_encode($prueba->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return $prueba->fueSatisfactoria() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Sin --origen explicito, registrar un acta es "guion" y ejecutarla aqui es "manual".
     * La distincion importa en la auditoria: un simulacro que alguien lanzo a mano la vispera
     * de la presentacion no demuestra lo mismo que uno que se repite solo cada mes.
     */
    private function resolverOrigen(bool $registrando): ?string
    {
        $origen = $this->option('origen');

        if ($origen === null || $origen === '') {
            return $registrando ? PruebaRestauracion::ORIGEN_GUION : PruebaRestauracion::ORIGEN_MANUAL;
        }

        return in_array($origen, PruebaRestauracion::ORIGENES, true) ? $origen : null;
    }

    /**
     * @return int|null|false false cuando se pidio una cuenta que no existe
     */
    private function resolverOperador(): int|null|false
    {
        $referencia = $this->option('usuario');

        if ($referencia === null || $referencia === '') {
            return null;
        }

        $usuario = is_numeric($referencia)
            ? User::query()->find((int) $referencia)
            : User::query()->where('email', $referencia)->first();

        if ($usuario === null) {
            $this->error("No existe ninguna cuenta identificada por \"{$referencia}\".");
            $this->line('La procedencia de una medicion no se rellena con un nombre que no se pudo comprobar.');

            return false;
        }

        return (int) $usuario->getKey();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function leerActa(string $ruta): ?array
    {
        if ($ruta === '-') {
            $contenido = file_get_contents('php://stdin');
        } elseif (is_file($ruta) && is_readable($ruta)) {
            $contenido = file_get_contents($ruta);
        } else {
            $this->error("No se puede leer el acta en {$ruta}.");

            return null;
        }

        if ($contenido === false || trim((string) $contenido) === '') {
            $this->error('El acta llego vacia. Compruebe que el guion se ejecuto con --json.');

            return null;
        }

        try {
            $acta = json_decode((string) $contenido, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            $this->error('El acta no es JSON valido: '.$error->getMessage());

            return null;
        }

        if (! is_array($acta)) {
            $this->error('El acta no es un objeto JSON.');

            return null;
        }

        if (($acta['version'] ?? null) !== 1) {
            $this->warn('El acta declara una version distinta de 1: se registra igualmente, pero revise el guion.');
        }

        return $acta;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function ejecutarGuion(): ?array
    {
        $guion = $this->resolverGuion();

        if ($guion === null) {
            $this->error('No se encontro infra/scripts/probar-restauracion.sh.');
            $this->line('Dentro del contenedor de la aplicacion el repositorio no esta montado. Ejecute el guion en el');
            $this->line('anfitrion y entreguele el acta a este comando:');
            $this->line('  bash infra/scripts/probar-restauracion.sh --json \\');
            $this->line('    | docker compose exec -T app php artisan siem:probar-restauracion --registrar=-');

            return null;
        }

        $conexion = (string) ($this->option('conexion') ?: config('database.default'));
        $ajustes = config('database.connections.'.$conexion);

        if (! is_array($ajustes)) {
            $this->error("La conexion \"{$conexion}\" no existe en config/database.php.");

            return null;
        }

        // El guion habla con mariadb-dump. Lanzarlo contra una conexion de otro motor produce
        // un acta fallida que parece un problema del respaldo cuando lo unico que pasa es que
        // se apunto a la base equivocada: mejor decirlo antes de tocar nada.
        if (! in_array($ajustes['driver'] ?? null, ['mariadb', 'mysql'], true)) {
            $this->error("La conexion \"{$conexion}\" no es de MariaDB ni de MySQL, y la prueba se hace con mariadb-dump.");
            $this->line('Use --conexion para apuntar a la conexion con la que la tienda atiende de verdad.');

            return null;
        }

        $this->info("Ejecutando {$guion} sobre la conexion {$conexion}.");

        $orden = ['bash', $guion, '--json'];

        if ($this->option('conservar')) {
            $orden[] = '--conservar';
        }

        $proceso = Process::timeout(max((int) $this->option('tiempo-maximo'), 60))
            ->env($this->entorno($conexion, $ajustes))
            ->run($orden, function (string $tipo, string $texto): void {
                // El guion escribe su bitacora por el error estandar y el acta por la salida
                // estandar. Solo la bitacora va a la consola: mezclar el JSON con ella haria
                // ilegible lo uno y lo otro.
                if ($tipo === 'err') {
                    $this->output->write($texto);
                }
            });

        $salida = trim($proceso->output());

        if ($salida === '') {
            $this->error('El guion no devolvio ningun acta.');
            $this->line(trim($proceso->errorOutput()));

            return null;
        }

        try {
            $acta = json_decode($salida, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            $this->error('El guion devolvio algo que no es JSON: '.$error->getMessage());

            return null;
        }

        return is_array($acta) ? $acta : null;
    }

    private function resolverGuion(): ?string
    {
        $candidatos = array_filter([
            $this->option('guion'),
            config('siem.continuidad.guion'),
            env('SIEM_GUION_RESTAURACION'),
            base_path('../infra/scripts/probar-restauracion.sh'),
            base_path('infra/scripts/probar-restauracion.sh'),
        ], static fn (mixed $ruta): bool => is_string($ruta) && $ruta !== '');

        foreach ($candidatos as $ruta) {
            if (is_file($ruta)) {
                return (string) realpath($ruta);
            }
        }

        return null;
    }

    /**
     * Las credenciales se toman de la conexion que usa la aplicacion, no de un archivo
     * aparte: asi la prueba demuestra que se puede restaurar LA base que atiende la tienda,
     * y no otra parecida que alguien configuro para que el simulacro saliera bien.
     *
     * @param  array<string, mixed>  $ajustes
     * @return array<string, string>
     */
    private function entorno(string $conexion, array $ajustes): array
    {
        $anfitrion = $ajustes['host'] ?? '127.0.0.1';

        if (is_array($anfitrion)) {
            $anfitrion = $anfitrion[0] ?? '127.0.0.1';
        }

        // config('siem.continuidad.*') es el punto de extension: si el integrador publica ese
        // bloque manda ese valor, y si no existe todavia se usa la variable de entorno.
        return array_filter([
            'SIEM_CONEXION' => $conexion,
            'SIEM_BD_BASE' => (string) ($ajustes['database'] ?? ''),
            'SIEM_BD_USUARIO' => (string) ($ajustes['username'] ?? ''),
            'SIEM_BD_CLAVE' => (string) ($ajustes['password'] ?? ''),
            'SIEM_BD_ANFITRION' => (string) $anfitrion,
            'SIEM_BD_PUERTO' => (string) ($ajustes['port'] ?? 3306),
            'SIEM_BD_ADMIN_USUARIO' => $this->ajuste('admin-usuario', 'admin_usuario', 'SIEM_BD_ADMIN_USUARIO'),
            'SIEM_BD_ADMIN_CLAVE' => $this->ajuste('admin-clave', 'admin_clave', 'SIEM_BD_ADMIN_CLAVE'),
            'SIEM_CONTENEDOR_BD' => $this->ajuste('contenedor', 'contenedor_bd', 'SIEM_CONTENEDOR_BD'),
            'SIEM_RUTA_RESPALDOS' => $this->ajuste('respaldos', 'ruta_respaldos', 'SIEM_RUTA_RESPALDOS'),
            'SIEM_FRASE_RESPALDO' => $this->ajuste('frase', 'frase_respaldo', 'SIEM_FRASE_RESPALDO'),
            'SIEM_DIR_TRABAJO' => $this->ajuste(null, 'dir_trabajo', 'SIEM_DIR_TRABAJO'),
        ], static fn (string $valor): bool => $valor !== '');
    }

    private function ajuste(?string $opcion, string $clave, string $variable): string
    {
        $valor = $opcion !== null ? $this->option($opcion) : null;

        if (is_string($valor) && $valor !== '') {
            return $valor;
        }

        $configurado = config('siem.continuidad.'.$clave);

        if (is_string($configurado) && $configurado !== '') {
            return $configurado;
        }

        $entorno = env($variable);

        return is_string($entorno) ? $entorno : '';
    }

    /**
     * @param  array<string, mixed>  $acta
     */
    private function registrar(
        array $acta,
        CarbonImmutable $iniciada,
        string $origen,
        ?int $operador,
    ): PruebaRestauracion {
        $respaldo = $this->momento($acta['respaldo_mas_reciente_en'] ?? null);

        // El tiempo de recuperacion NO es la suma de todas las fases. Volcar y cifrar son el
        // respaldo, y el respaldo ya estaba hecho cuando el desastre ocurre. Lo que mide el
        // RTO es lo que se tarda desde que se tiene la copia hasta que el servicio responde.
        $recuperacion = $this->sumar([
            $acta['segundos_descifrado'] ?? null,
            $acta['segundos_restauracion'] ?? null,
            $acta['segundos_verificacion'] ?? null,
        ]);

        // El punto de recuperacion es la distancia entre el respaldo mas reciente y el momento
        // del simulacro, medida contra el arranque de la prueba porque es cuando se observo el
        // directorio. Nunca contra "ahora": eso convertiria una foto en una cifra que envejece
        // sola sin que nadie haya vuelto a mirar.
        $antiguedad = $respaldo === null
            ? null
            : max(0, (int) floor($respaldo->diffInMinutes($iniciada, absolute: false)));

        $discrepancias = is_array($acta['discrepancias'] ?? null) ? $acta['discrepancias'] : [];
        $filas = (int) ($acta['filas_comparadas'] ?? 0);
        $algoritmo = $this->texto($acta['algoritmo_cifrado'] ?? null);

        // El cifrador se COMPRUEBA contra el nombre que el guion leyo del archivo; la casilla
        // "cifrado_verificado" del acta no basta por si sola. Creerla seria dejar que quien
        // ejecuta la prueba se ponga la nota en el unico punto que el documento del proyecto
        // declara por escrito.
        $cifradoConforme = ($acta['cifrado_verificado'] ?? false) === true
            && $algoritmo === self::CIFRADOR_EXIGIDO;

        // El veredicto se recalcula aqui, hecho por hecho. Una restauracion sin errores pero
        // con tablas vacias termina en exito para el sistema operativo y es justo la que da
        // falsa confianza; el reparo concreto se guarda para que la fila se explique sola.
        $reparos = [];

        if (($acta['verificacion_superada'] ?? false) !== true) {
            $reparos[] = 'el acta no declara superada la verificacion por conteo de filas';
        }

        if ($discrepancias !== []) {
            $reparos[] = 'hay tablas cuyo conteo restaurado no cae dentro del intervalo del origen';
        }

        if ($filas <= 0) {
            $reparos[] = 'la restauracion no dejo ni una fila, y un exito con tablas vacias es falsa confianza';
        }

        if ($recuperacion === null) {
            $reparos[] = 'falta el tiempo de alguna de las tres fases de recuperacion, de modo que no hay RTO que medir';
        }

        if (! $cifradoConforme) {
            $reparos[] = 'el cifrador leido del archivo fue "'.($algoritmo ?? 'ninguno').'" y el documento '
                .'del proyecto declara '.self::CIFRADOR_EXIGIDO;
        }

        $faseActa = $this->texto($acta['fase_fallida'] ?? null);
        $satisfactoria = $reparos === [] && $faseActa === null;

        $error = $this->texto($acta['error'] ?? null);

        // Los reparos solo se escriben cuando el acta NO declaraba fase fallida, es decir,
        // cuando el suspenso lo pone este comando. Si el guion ya dijo donde se cayo, anadir
        // detras "no supero la verificacion" enturbia el diagnostico con consecuencias de un
        // fallo anterior: claro que no la supero, la prueba nunca llego hasta ahi.
        if ($faseActa === null && $reparos !== []) {
            $motivo = 'Los hechos medidos no sostienen la prueba: '.implode('; ', $reparos).'.';
            $error = $error === null ? $motivo : $error.' '.$motivo;
        }

        // Si el acta no declaro fase fallida pero el veredicto se cae aqui, la fila se sella
        // con la fase a la que pertenece el reparo: "fallida" a secas no se puede investigar.
        $fase = $faseActa ?? match (true) {
            ! $cifradoConforme => 'cifrado',
            $recuperacion === null => 'medicion',
            $reparos !== [] => 'verificacion',
            default => null,
        };

        if (($acta['resultado'] ?? null) === PruebaRestauracion::RESULTADO_SATISFACTORIA && ! $satisfactoria) {
            $this->warn('El acta se declaraba satisfactoria, pero los hechos medidos no la sostienen: se registra como fallida.');

            foreach ($reparos as $reparo) {
                $this->line('  · '.$reparo);
            }
        }

        $prueba = new PruebaRestauracion;

        $prueba->fill([
            'iniciada_en' => $iniciada,
            'terminada_en' => $this->momento($acta['terminada_en'] ?? null),
            'resultado' => $satisfactoria
                ? PruebaRestauracion::RESULTADO_SATISFACTORIA
                : PruebaRestauracion::RESULTADO_FALLIDA,
            'fase_fallida' => $fase,
            'error' => $error,
            'origen' => $origen,
            'ejecutada_por' => $operador,
            'actor' => $this->texto($acta['actor'] ?? null) ?? 'desconocido',
            'anfitrion' => $this->texto($acta['anfitrion'] ?? null),
            'comando' => $this->texto($acta['comando'] ?? null),
            'modo_cliente' => $this->texto($acta['modo_cliente'] ?? null),
            'conexion' => $this->texto($acta['conexion'] ?? null) ?? (string) config('database.default'),
            'base_origen' => $this->texto($acta['base_origen'] ?? null) ?? 'desconocida',
            'base_prueba' => $this->texto($acta['base_prueba'] ?? null) ?? 'desconocida',
            'segundos_volcado' => $this->numero($acta['segundos_volcado'] ?? null),
            'segundos_cifrado' => $this->numero($acta['segundos_cifrado'] ?? null),
            'segundos_descifrado' => $this->numero($acta['segundos_descifrado'] ?? null),
            'segundos_restauracion' => $this->numero($acta['segundos_restauracion'] ?? null),
            'segundos_verificacion' => $this->numero($acta['segundos_verificacion'] ?? null),
            'segundos_recuperacion' => $recuperacion,
            'bytes_volcado' => isset($acta['bytes_volcado']) && is_numeric($acta['bytes_volcado'])
                ? (int) $acta['bytes_volcado']
                : null,
            'algoritmo_cifrado' => $algoritmo,
            'cifrado_verificado' => $cifradoConforme,
            'huella_volcado' => $this->texto($acta['huella_volcado'] ?? null),
            'verificacion_superada' => ($acta['verificacion_superada'] ?? false) === true,
            'tablas_comparadas' => (int) ($acta['tablas_comparadas'] ?? 0),
            'filas_comparadas' => $filas,
            'conteos_origen' => is_array($acta['conteos_origen'] ?? null) ? $acta['conteos_origen'] : null,
            'conteos_restaurada' => is_array($acta['conteos_restaurada'] ?? null) ? $acta['conteos_restaurada'] : null,
            'discrepancias' => $discrepancias === [] ? null : $discrepancias,
            'respaldo_mas_reciente_en' => $respaldo,
            'respaldo_mas_reciente_ruta' => $this->texto($acta['respaldo_mas_reciente_ruta'] ?? null),
            'antiguedad_respaldo_minutos' => $antiguedad,
            'respaldos_encontrados' => (int) ($acta['respaldos_encontrados'] ?? 0),
            'ruta_respaldos' => $this->texto($acta['ruta_respaldos'] ?? null),
            'salida' => mb_substr((string) ($acta['salida'] ?? ''), 0, self::LIMITE_SALIDA),
            'es_demostracion' => false,
        ]);

        $prueba->save();

        return $prueba;
    }

    private function informar(PruebaRestauracion $prueba): void
    {
        $this->newLine();

        if ($prueba->fueSatisfactoria()) {
            $this->info('Prueba de restauracion satisfactoria y registrada.');
        } else {
            $this->error('Prueba de restauracion fallida: se registra igualmente porque el intento tambien es evidencia.');

            if ($prueba->error !== null) {
                $this->line('  '.$prueba->error);
            }
        }

        $this->table(['Hecho medido', 'Valor'], [
            ['Base restaurada', $prueba->base_prueba],
            ['Volcado', PruebaRestauracion::tamanoLegible($prueba->bytes_volcado)],
            ['Cifrador leido del archivo', $prueba->algoritmo_cifrado ?? 'sin datos'],
            ['Descifrado', PruebaRestauracion::duracionLegible($prueba->segundos_descifrado)],
            ['Restauracion', PruebaRestauracion::duracionLegible($prueba->segundos_restauracion)],
            ['Verificacion', PruebaRestauracion::duracionLegible($prueba->segundos_verificacion)],
            ['Tiempo de recuperacion (RTO)', PruebaRestauracion::duracionLegible($prueba->segundos_recuperacion)],
            ['Tablas comparadas', (string) $prueba->tablas_comparadas],
            ['Filas comparadas', number_format($prueba->filas_comparadas)],
            ['Respaldo mas reciente', $prueba->respaldo_mas_reciente_en?->format('d/m/Y H:i') ?? 'sin datos'],
            ['Punto de recuperacion (RPO)', PruebaRestauracion::antiguedadLegible($prueba->antiguedad_respaldo_minutos)],
            ['Ejecutada por', $prueba->responsable()],
            ['Origen', $prueba->etiquetaOrigen()],
        ]);

        if ($prueba->antiguedad_respaldo_minutos === null) {
            $this->warn('Sin respaldos que fechar, el punto de recuperacion sigue declarandose sin datos.');
        }
    }

    /**
     * El guion sella sus marcas con el desfase horario del anfitrion ("...T17:22:07-06:00").
     * Se convierten al huso de la aplicacion antes de guardarlas porque la columna se lee
     * despues como si estuviera en ese huso: dejar el desfase puesto grabaria las 17:22 del
     * anfitrion como si fueran las 17:22 de la aplicacion y el acta envejeceria seis horas
     * de golpe. La misma conversion que hace la ingesta de estado de parches.
     */
    private function momento(mixed $valor): ?CarbonImmutable
    {
        if (! is_string($valor) || trim($valor) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($valor)->setTimezone(config('app.timezone', 'UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function texto(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $valor = trim($valor);

        return $valor === '' ? null : $valor;
    }

    private function numero(mixed $valor): ?float
    {
        return is_numeric($valor) ? round((float) $valor, 3) : null;
    }

    /**
     * @param  array<int, mixed>  $valores
     */
    private function sumar(array $valores): ?float
    {
        $total = 0.0;

        foreach ($valores as $valor) {
            if (! is_numeric($valor)) {
                // Si falta una sola fase, la suma no describe nada. Devolver un parcial seria
                // publicar un RTO mas corto que el real, que es el peor error posible aqui.
                return null;
            }

            $total += (float) $valor;
        }

        return round($total, 3);
    }
}
