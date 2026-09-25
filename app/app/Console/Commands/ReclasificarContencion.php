<?php

namespace App\Console\Commands;

use App\Models\AlertaSeguridad;
use App\Models\EventoSeguridad;
use App\Models\RegistroAuditoria;
use App\Models\ReglaCorrelacion;
use App\Models\User;
use App\Services\Siem\TriajeAsistido;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reclasifica como "cerrada sin impacto" una alerta que una persona marcó contenida cuando
 * no había ninguna amenaza activa que contener. Y lo deshace, con --revertir.
 *
 * EL CASO QUE LO ORIGINÓ. Ruido —sondeos que el WAF registró en paranoia 2— revisado en
 * lote a "En triaje" y marcado "Contenida" a mano unas cuarenta horas después. El tiempo
 * medio de contención lo contaba como respuestas de cuarenta horas. El error estaba en el
 * registro: para ruido revisado, lo correcto es cerrar, no contener.
 *
 * LO QUE IMPIDE, porque una herramienta así puede usarse para esconder una respuesta lenta
 * de verdad:
 *   - Solo alertas que siguen en "contenida": las que ya siguieron su ciclo no se tocan.
 *   - Nunca las de credenciales (fuerza bruta, segundo factor): ahí una contención es real
 *     por definición.
 *   - Si algún evento del WAF pasó sin bloqueo, o hay eventos de la aplicación o del
 *     sistema, exige --revisados-logs: quien decide atestigua haber revisado los registros
 *     de aplicación y base de datos. Queda escrito que lo atestiguó.
 *   - Exige --responsable: el correo de un administrador o auditor. Es quien firma en la
 *     alerta y en el asiento de auditoría, no el usuario del contenedor.
 *   - Nunca elige alertas por sí mismo ni por la cifra que estropean: sin identificadores,
 *     lista las candidatas con su evidencia y no cambia nada.
 *
 * LO QUE CONSERVA: todo. Las marcas de confirmación y contención quedan intactas, y lo que
 * se sobrescribe —estado, firma, nota y evidencia del triaje anterior— se guarda completo
 * en la propia alerta y en el asiento de auditoría. Por eso se puede revertir.
 */
class ReclasificarContencion extends Command
{
    protected $signature = 'siem:reclasificar-contencion
        {ids?* : Identificadores de las alertas (se admite el # delante)}
        {--motivo= : Por qué no había amenaza que contener, o por qué se revierte}
        {--responsable= : Correo del administrador o auditor que decide}
        {--revisados-logs : Atestigua que se revisaron los registros de aplicación y base de datos}
        {--revertir : Deshace una reclasificación y restaura lo que había}';

    protected $description = 'Reclasifica como cerrada sin impacto una contención marcada por error, con responsable, motivo y auditoría';

    private const MINIMO_MOTIVO = 20;

    /** Reglas en las que una contención es real por definición: hubo credencial en juego. */
    private const REGLAS_DE_CREDENCIALES = [
        ReglaCorrelacion::FUERZA_BRUTA_SESION,
        ReglaCorrelacion::SEGUNDO_FACTOR_FALLIDO,
    ];

    public function handle(TriajeAsistido $triaje): int
    {
        if (! $triaje->procedenciaRegistrable()) {
            $this->error('Faltan las columnas de procedencia de triaje. Ejecute las migraciones.');

            return self::FAILURE;
        }

        $tokens = array_map('strval', (array) $this->argument('ids'));
        $invalidos = array_values(array_filter($tokens, static fn (string $t): bool => preg_match('/^#?\d+$/', $t) !== 1));

        if ($invalidos !== []) {
            $this->error('Identificadores no válidos: '.implode(', ', $invalidos).'. Escriba números separados por espacios.');

            return self::FAILURE;
        }

        $ids = array_values(array_unique(array_map(static fn (string $t): int => (int) ltrim($t, '#'), $tokens)));

        if ($ids === []) {
            if ($this->option('motivo') !== null || $this->option('revertir')) {
                $this->error('No se indicó ninguna alerta. Sin identificadores el comando solo lista candidatas.');

                return self::FAILURE;
            }

            return $this->listar();
        }

        $motivo = trim((string) $this->option('motivo'));

        if (mb_strlen($motivo) < self::MINIMO_MOTIVO) {
            $this->error('Hace falta --motivo con al menos '.self::MINIMO_MOTIVO.' caracteres. Quien lea esto mañana no estará en la sala.');

            return self::FAILURE;
        }

        $responsable = $this->responsable();

        if (! $responsable instanceof User) {
            return self::FAILURE;
        }

        return $this->option('revertir')
            ? $this->revertir($ids, $motivo, $responsable)
            : $this->reclasificar($ids, $motivo, $responsable);
    }

    private function responsable(): ?User
    {
        $correo = mb_strtolower(trim((string) $this->option('responsable')));

        if ($correo === '') {
            $this->error('Hace falta --responsable=<correo> de un administrador o auditor: es quien firma la decisión.');

            return null;
        }

        $usuario = User::query()->where('email', $correo)->first();

        if (! $usuario instanceof User || ! $usuario->tieneRol('admin', 'auditor')) {
            $this->error("{$correo} no es una cuenta de administrador o auditor.");

            return null;
        }

        return $usuario;
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function reclasificar(array $ids, string $motivo, User $responsable): int
    {
        $ahora = CarbonImmutable::now();
        $revisadosLogs = (bool) $this->option('revisados-logs');
        $hechas = 0;

        foreach ($ids as $id) {
            $alerta = AlertaSeguridad::query()->with('eventos')->find($id);
            $evidencia = $alerta instanceof AlertaSeguridad ? $this->evidencia($alerta) : null;

            $rechazo = match (true) {
                ! $alerta instanceof AlertaSeguridad => 'no existe',
                $alerta->contenida_en === null => 'no está contenida',
                $alerta->estado !== AlertaSeguridad::ESTADO_CONTENIDA => "ya siguió su ciclo (estado {$alerta->estado}); no se reclasifica",
                $alerta->getAttribute('triaje_regla') === TriajeAsistido::REGLA_BLOQUEO_CRITICO => 'la contuvo el cortafuegos y ya no cuenta en la métrica',
                $alerta->getAttribute('triaje_regla') === TriajeAsistido::REGLA_RECLASIFICADA_SIN_IMPACTO => 'ya estaba reclasificada',
                in_array($alerta->clave_regla, self::REGLAS_DE_CREDENCIALES, true) => 'es de credenciales: una contención ahí es real por definición',
                $evidencia['exige_revision'] && ! $revisadosLogs => 'hay tráfico que pasó el WAF o eventos de la aplicación. Revise los registros de '
                    .'aplicación y base de datos (transacciones: '.($evidencia['transacciones'] ?: 'sin id').') y repita con --revisados-logs',
                default => null,
            };

            if ($rechazo !== null) {
                $this->warn("  #{$id}: {$rechazo}. Se deja como está.");

                continue;
            }

            $anterior = $this->instantanea($alerta);
            $mismaPersona = (int) $alerta->atendida_por === (int) $responsable->getKey();
            $firma = $responsable->name.' (cuenta #'.$responsable->getKey().') desde consola';

            DB::transaction(function () use ($alerta, $anterior, $evidencia, $motivo, $responsable, $firma, $ahora, $revisadosLogs, $mismaPersona): void {
                AlertaSeguridad::query()->whereKey($alerta->getKey())->update([
                    'estado' => AlertaSeguridad::ESTADO_CERRADA,
                    'cerrada_en' => $alerta->cerrada_en ?? $ahora,
                    'procedencia_triaje' => TriajeAsistido::PROCEDENCIA_HUMANA,
                    'triaje_regla' => TriajeAsistido::REGLA_RECLASIFICADA_SIN_IMPACTO,
                    'triaje_criterio' => $motivo,
                    'triaje_evidencia' => json_encode([
                        'antes' => $anterior,
                        'evidencia' => $evidencia,
                        'revisados_logs' => $revisadosLogs,
                    ], JSON_UNESCAPED_UNICODE),
                    'triado_en' => $ahora,
                    'triado_por' => mb_substr($firma, 0, 190),
                    'updated_at' => $ahora,
                ]);

                RegistroAuditoria::query()->create([
                    'accion' => 'alerta.contencion_reclasificada',
                    'tipo_recurso' => 'alerta_seguridad',
                    'identificador_recurso' => (string) $alerta->getKey(),
                    'usuario_id' => $responsable->getKey(),
                    'correo' => $responsable->email,
                    'metodo' => 'CLI',
                    'ruta' => 'artisan siem:reclasificar-contencion',
                    'resultado' => RegistroAuditoria::EXITO,
                    'detalle' => [
                        'motivo' => $motivo,
                        'antes' => $anterior,
                        'evidencia' => $evidencia,
                        'revisados_logs' => $revisadosLogs,
                        'misma_persona_que_contuvo' => $mismaPersona,
                    ],
                ]);
            });

            $this->line("  #{$id}: reclasificada como cerrada sin impacto.".($mismaPersona ? ' (La reclasifica la misma persona que la contuvo; queda anotado.)' : ''));
            $hechas++;
        }

        $this->info("{$hechas} reclasificadas. La métrica de contención las excluye y las enumera. Se deshace con --revertir.");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function revertir(array $ids, string $motivo, User $responsable): int
    {
        $hechas = 0;

        foreach ($ids as $id) {
            $alerta = AlertaSeguridad::query()->find($id);

            if (! $alerta instanceof AlertaSeguridad
                || $alerta->getAttribute('triaje_regla') !== TriajeAsistido::REGLA_RECLASIFICADA_SIN_IMPACTO) {
                $this->warn("  #{$id}: no está reclasificada. Se deja como está.");

                continue;
            }

            $guardado = json_decode((string) $alerta->getAttribute('triaje_evidencia'), true);
            $antes = is_array($guardado['antes'] ?? null) ? $guardado['antes'] : null;

            if ($antes === null) {
                $this->warn("  #{$id}: no se encuentra el estado anterior guardado. No se revierte a ciegas.");

                continue;
            }

            DB::transaction(function () use ($alerta, $antes, $motivo, $responsable): void {
                AlertaSeguridad::query()->whereKey($alerta->getKey())->update([
                    'estado' => $antes['estado'],
                    'cerrada_en' => $antes['cerrada_en'],
                    'atendida_por' => $antes['atendida_por'],
                    'notas_triaje' => $antes['notas_triaje'],
                    'procedencia_triaje' => $antes['procedencia_triaje'],
                    'triaje_regla' => $antes['triaje_regla'],
                    'triaje_criterio' => $antes['triaje_criterio'],
                    'triaje_evidencia' => $antes['triaje_evidencia'] === null
                        ? null
                        : json_encode($antes['triaje_evidencia'], JSON_UNESCAPED_UNICODE),
                    'triado_en' => $antes['triado_en'],
                    'triado_por' => $antes['triado_por'],
                    'updated_at' => CarbonImmutable::now(),
                ]);

                RegistroAuditoria::query()->create([
                    'accion' => 'alerta.reclasificacion_revertida',
                    'tipo_recurso' => 'alerta_seguridad',
                    'identificador_recurso' => (string) $alerta->getKey(),
                    'usuario_id' => $responsable->getKey(),
                    'correo' => $responsable->email,
                    'metodo' => 'CLI',
                    'ruta' => 'artisan siem:reclasificar-contencion --revertir',
                    'resultado' => RegistroAuditoria::EXITO,
                    'detalle' => ['motivo' => $motivo, 'restaurado' => $antes],
                ]);
            });

            $this->line("  #{$id}: reclasificación revertida; vuelve a contar en la métrica.");
            $hechas++;
        }

        $this->info("{$hechas} revertidas.");

        return self::SUCCESS;
    }

    /**
     * Todo lo que el UPDATE pisa, para que la decisión original no se pierda.
     *
     * @return array<string, mixed>
     */
    private function instantanea(AlertaSeguridad $alerta): array
    {
        $evidenciaPrevia = $alerta->getAttribute('triaje_evidencia');

        return [
            'estado' => $alerta->estado,
            'confirmada_en' => $alerta->confirmada_en?->toDateTimeString(),
            'contenida_en' => $alerta->contenida_en?->toDateTimeString(),
            'cerrada_en' => $alerta->cerrada_en?->toDateTimeString(),
            'atendida_por' => $alerta->atendida_por,
            'notas_triaje' => $alerta->notas_triaje,
            'procedencia_triaje' => $alerta->getAttribute('procedencia_triaje'),
            'triaje_regla' => $alerta->getAttribute('triaje_regla'),
            'triaje_criterio' => $alerta->getAttribute('triaje_criterio'),
            'triaje_evidencia' => is_string($evidenciaPrevia) ? json_decode($evidenciaPrevia, true) : $evidenciaPrevia,
            'triado_en' => $alerta->getAttribute('triado_en') !== null ? (string) $alerta->getAttribute('triado_en') : null,
            'triado_por' => $alerta->getAttribute('triado_por'),
        ];
    }

    /**
     * Lo que hace falta para decidir si había amenaza activa, alerta por alerta.
     *
     * @return array<string, mixed>
     */
    private function evidencia(AlertaSeguridad $alerta): array
    {
        /** @var Collection<int, EventoSeguridad> $eventos */
        $eventos = $alerta->eventos;
        $waf = $eventos->where('fuente', EventoSeguridad::FUENTE_WAF);
        $pasaron = $waf->where('fue_bloqueado', false);
        $otros = $eventos->where('fuente', '!=', EventoSeguridad::FUENTE_WAF);

        return [
            'eventos_por_fuente' => $eventos->countBy('fuente')->all(),
            'waf_bloqueados' => $waf->where('fue_bloqueado', true)->count(),
            'waf_total' => $waf->count(),
            'codigos' => $waf->pluck('codigo_respuesta')->filter()->unique()->values()->all(),
            'reglas' => $waf->flatMap(fn (EventoSeguridad $e) => $e->identificadores_regla ?? [])->unique()->take(5)->values()->all(),
            'puntuacion_maxima' => (int) $waf->max('puntuacion_anomalia'),
            'transacciones' => $pasaron->pluck('identificador_transaccion')->filter()->take(3)->implode(', '),
            // Algo atravesó el WAF, o hay eventos de otra fuente, o no hay eventos con los
            // que juzgar: en los tres casos alguien tiene que mirar los registros.
            'exige_revision' => $pasaron->isNotEmpty() || $otros->isNotEmpty() || $eventos->isEmpty(),
        ];
    }

    /**
     * Candidatas: contenciones humanas de tráfico real que siguen en "contenida", en orden de
     * detección —no de lentitud—, con la evidencia para decidir si había algo que contener.
     */
    private function listar(): int
    {
        $candidatas = AlertaSeguridad::query()
            ->where('es_demostracion', false)
            ->where('estado', AlertaSeguridad::ESTADO_CONTENIDA)
            ->whereNotNull('confirmada_en')
            ->whereNotNull('contenida_en')
            ->where('detectada_en', '>=', CarbonImmutable::now()->subDays(30))
            ->where(fn ($q) => $q->whereNull('triaje_regla')->orWhereNotIn('triaje_regla', [
                TriajeAsistido::REGLA_BLOQUEO_CRITICO,
                TriajeAsistido::REGLA_RECLASIFICADA_SIN_IMPACTO,
            ]))
            ->with('eventos')
            ->orderBy('detectada_en')
            ->get();

        if ($candidatas->isEmpty()) {
            $this->info('No hay contenciones humanas de tráfico real pendientes de revisar en los últimos 30 días.');

            return self::SUCCESS;
        }

        $this->line('Contenciones humanas de tráfico real que siguen en "contenida", por orden de detección.');
        $this->line('Reclasifique SOLO las que no tenían amenaza activa que contener.');

        foreach ($candidatas as $a) {
            $e = $this->evidencia($a);
            $fuentes = collect($e['eventos_por_fuente'])->map(fn ($n, $f) => "{$f} {$n}")->implode(', ') ?: 'sin eventos';

            $this->newLine();
            $this->line(sprintf('#%d  %s (%s) · detectada %s · IP %s',
                $a->getKey(), $a->clave_regla, $a->severidad, $a->detectada_en->format('d/m H:i'), $a->direccion_ip ?? '-'));
            $this->line(sprintf('     contenida %.1f h después de confirmar · %s',
                $a->confirmada_en->diffInSeconds($a->contenida_en, false) / 3600,
                $a->getAttribute('triado_por') ?: 'sin firma de triaje'));
            if ($a->notas_triaje) {
                $this->line('     nota: '.mb_substr((string) $a->notas_triaje, 0, 90));
            }
            $this->line(sprintf('     eventos: %s · WAF %d de %d bloqueados · códigos %s · reglas %s · puntuación máx %d',
                $fuentes, $e['waf_bloqueados'], $e['waf_total'], implode(',', $e['codigos']) ?: '-',
                implode(',', $e['reglas']) ?: '-', $e['puntuacion_maxima']));
            if (in_array($a->clave_regla, self::REGLAS_DE_CREDENCIALES, true)) {
                $this->line('     -> DE CREDENCIALES: no se puede reclasificar.');
            } elseif ($e['exige_revision']) {
                $this->line('     -> REVISAR REGISTROS DE APLICACIÓN Y BASE DE DATOS'
                    .($e['transacciones'] ? ' (transacciones '.$e['transacciones'].')' : '').' antes de reclasificar.');
            }
        }

        $this->newLine();
        $this->line('Para reclasificar las que NO tenían amenaza activa:');
        $this->line('  php artisan siem:reclasificar-contencion <id> <id> ... --responsable=<correo> --motivo="qué se comprobó"');
        $this->line('  (añada --revisados-logs solo si revisó los registros de aplicación y base de datos)');

        return self::SUCCESS;
    }
}
