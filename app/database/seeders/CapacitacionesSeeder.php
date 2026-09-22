<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Capacitacion;
use Illuminate\Database\Seeder;

/**
 * Siembra EL PLAN de concienciacion y capacitacion, y nada mas.
 *
 * Las cinco actividades de abajo no son invencion de este archivo: son las cinco filas de la
 * tabla de periodicidad de POL-006 seccion 3, con el temario de la seccion 4 y el material
 * que esa misma seccion cita. Por eso cada fila guarda en "fuente" el documento y el
 * apartado exactos de donde sale.
 *
 * LO QUE ESTE SEMILLERO NO HACE, Y ES LO IMPORTANTE: no escribe ni una sola asistencia. Una
 * asistencia afirma que una persona estuvo en una sala y respondio una evaluacion; eso lo
 * registra quien lo presencio, cuando ocurre. Sembrarlas daria un cien por cien de personal
 * capacitado el dia del despliegue, que es exactamente el hallazgo que el anexo del proyecto
 * reprocha: declarar como resuelto lo que solo esta escrito.
 *
 * Mientras no haya asistencias, la metrica se declara sin datos y el panel dice que falta.
 */
class CapacitacionesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->plan() as $sesion) {
            $existente = Capacitacion::query()->where('codigo', $sesion['codigo'])->first();

            if ($existente instanceof Capacitacion) {
                // Se actualiza la DESCRIPCION del plan y jamas su ejecucion. El semillero se
                // vuelve a correr en cada despliegue, y un updateOrCreate completo devolveria
                // a "planificada" una sesion que ya se impartio, borrando el unico hecho que
                // esta tabla aporta a la metrica.
                $existente->fill($sesion)->save();

                continue;
            }

            Capacitacion::query()->create($sesion + [
                'estado' => Capacitacion::ESTADO_PLANIFICADA,
                'origen' => Capacitacion::ORIGEN_PLAN,
                'registrada_en' => now(),
                'actor' => 'CapacitacionesSeeder (copia literal de POL-006 v1.0)',
            ]);
        }

        $this->command?->info('Plan de capacitacion sembrado: '.Capacitacion::query()->count().' sesiones previstas.');
        $this->command?->warn('Sin asistencias: las registra una persona cuando la sesion ocurre, desde /siem/capacitacion.');
    }

    /**
     * Las cinco actividades declaradas en POL-006. Ni una mas.
     *
     * @return array<int, array<string, mixed>>
     */
    private function plan(): array
    {
        $documento = 'docs/03-politicas/plan-de-concienciacion-y-capacitacion.md (POL-006 v1.0, 21/09/2026)';

        return [
            [
                // El formato del identificador es el que fija POL-006 seccion 6: CAP-AAAA-SN.
                'codigo' => 'CAP-2026-S2',
                'tipo' => Capacitacion::TIPO_FORMAL,
                'tema' => 'Capacitacion formal completa · segundo semestre de 2026',
                'descripcion' => 'Los seis modulos del programa, con evaluacion de veinte preguntas y ejercicio '
                    .'practico ejecutado sobre el sistema real.',
                'periodicidad' => 'semestral',
                'periodo' => '2026-S2',
                'modulos' => [
                    '1 · Los riesgos que enfrenta esta plataforma',
                    '2 · Autenticacion, segundo factor y suplantacion de sitio',
                    '3 · Codificacion segura',
                    '4 · Clasificacion y manejo de la informacion',
                    '5 · Que hacer cuando algo pasa',
                    '6 · Novedades del semestre',
                ],
                'material' => [
                    'OWASP Top 10:2025',
                    'OWASP Cheat Sheet Series (XSS, inyeccion SQL, autenticacion multifactor)',
                    'OWASP Proactive Controls',
                    'NIST SP 800-63B-4, apartados de resistencia a la suplantacion',
                    'POL-001 seccion 6 y POL-005 seccion 5',
                    'POL-002 y POL-003, con el resumen de una pagina de esta ultima',
                ],
                'destinatarios' => ['Nivel estrategico', 'Nivel tactico', 'Nivel operativo'],
                'instructor_funcion' => 'Arquitecto de Seguridad',
                'duracion_minutos' => 240,
                'vigencia_meses' => 6,
                'umbral_aprobacion' => 80,
                'cuenta_para_vigencia' => true,
                // "No existen personas exentas" (POL-006 seccion 2): la ausencia de cualquiera
                // es una asistencia que falta, no un caso pendiente.
                'exige_a_todo_el_equipo' => true,
                // Sin fecha a proposito: el documento programa la primera edicion "para la
                // semana previa a la presentacion" y no fija el dia. Escribir aqui un martes
                // cualquiera convertiria una imprecision del plan en un dato falso del panel.
                'programada_para' => null,
                'nota_programacion' => 'POL-006 seccion 8: primera edicion programada para la semana previa a la '
                    .'presentacion del proyecto (26/09/2026). El documento no fija el dia exacto.',
                'fuente' => $documento.', secciones 3, 4 y 7',
            ],
            [
                'codigo' => 'SIM-2026-S2',
                'tipo' => Capacitacion::TIPO_SIMULACRO,
                'tema' => 'Simulacro de mesa de respuesta a incidentes · segundo semestre de 2026',
                'descripcion' => 'Recorrido verbal de un escenario completo con las funciones repartidas y '
                    .'cronometraje de las decisiones. El primero corresponde a un cifrado malicioso de datos.',
                'periodicidad' => 'semestral',
                'periodo' => '2026-S2',
                'modulos' => null,
                'material' => ['POL-003 · Plan de respuesta a incidentes', 'POL-002 · Matriz de escalamiento'],
                'destinatarios' => ['Nivel estrategico', 'Nivel tactico', 'Nivel operativo'],
                'instructor_funcion' => 'Coordinador de Respuesta',
                'duracion_minutos' => 120,
                'vigencia_meses' => 6,
                'umbral_aprobacion' => 80,
                // POL-006 seccion 5, literal: "El simulacro no forma parte de la capacitacion
                // formal: es el ejercicio que verifica si la capacitacion sirvio". Si acreditara
                // vigencia, bastaria con simular mucho para declarar al equipo capacitado.
                'cuenta_para_vigencia' => false,
                'exige_a_todo_el_equipo' => true,
                'programada_para' => null,
                'nota_programacion' => 'POL-006 seccion 5: obligatorio antes de la puesta en produccion, junto con '
                    .'la primera capacitacion formal. Produce acta con tiempos alcanzados y deficiencias detectadas.',
                'fuente' => $documento.', seccion 5',
            ],
            [
                'codigo' => 'IND-CONTINUA',
                'tipo' => Capacitacion::TIPO_INDUCCION,
                'tema' => 'Induccion para nueva incorporacion',
                'descripcion' => 'Se imparte ANTES de otorgar cualquier acceso. La regla no admite excepcion: '
                    .'entregar credenciales a quien todavia no sabe que esta prohibido hacer con ellas invierte '
                    .'el orden de los controles.',
                // Sin calendario: la dispara un hecho —que alguien se incorpore—, y ponerle un
                // semestre le inventaria una fecha que el plan no tiene.
                'periodicidad' => 'por_incorporacion',
                'periodo' => null,
                // El documento no detalla el temario de la induccion. Se deja vacio en lugar de
                // copiar el de la capacitacion formal, que seria suponer.
                'modulos' => null,
                'material' => null,
                'destinatarios' => ['Nueva incorporacion'],
                'instructor_funcion' => 'Arquitecto de Seguridad',
                'duracion_minutos' => 120,
                'vigencia_meses' => 6,
                'umbral_aprobacion' => 80,
                'cuenta_para_vigencia' => true,
                // Solo alcanza a quien se incorpora: contarla como exigible a todo el equipo
                // inflaria el recuento de asistencias que faltan con personas que ya estaban.
                'exige_a_todo_el_equipo' => false,
                'programada_para' => null,
                'nota_programacion' => 'POL-006 seccion 3: antes de otorgar cualquier acceso a quien se incorpora.',
                'fuente' => $documento.', seccion 3',
            ],
            [
                'codigo' => 'ACT-INCIDENTE',
                'tipo' => Capacitacion::TIPO_ACTUALIZACION,
                'tema' => 'Actualizacion tras un incidente de severidad 1 o 2',
                'descripcion' => 'Lecciones aprendidas del incidente y cambios de procedimiento que deja, dentro '
                    .'de los diez dias habiles siguientes a su cierre.',
                'periodicidad' => 'por_incidente',
                'periodo' => null,
                'modulos' => null,
                'material' => ['Informe de cierre del incidente', 'POL-003 · Plan de respuesta a incidentes'],
                'destinatarios' => ['Nivel tactico', 'Nivel operativo'],
                'instructor_funcion' => 'Coordinador de Respuesta',
                'duracion_minutos' => 30,
                'vigencia_meses' => 6,
                'umbral_aprobacion' => 80,
                // Media hora sobre un incidente concreto no renueva el semestre entero: es un
                // refuerzo puntual, y contarlo como capacitacion completa falsearia la vigencia.
                'cuenta_para_vigencia' => false,
                'exige_a_todo_el_equipo' => false,
                'programada_para' => null,
                'nota_programacion' => 'POL-006 seccion 3: dentro de los 10 dias habiles del cierre del incidente.',
                'fuente' => $documento.', seccion 3',
            ],
            [
                'codigo' => 'REC-MENSUAL',
                'tipo' => Capacitacion::TIPO_RECORDATORIO,
                'tema' => 'Recordatorio breve de una practica concreta',
                'descripcion' => 'Diez minutos sobre una sola practica, a cargo del Analista de Turno saliente.',
                'periodicidad' => 'mensual',
                // El plan declara la periodicidad mensual pero no enumera los meses ni sus temas.
                // Sembrar doce sesiones con fecha seria fabricar un calendario que nadie aprobo.
                'periodo' => null,
                'modulos' => null,
                'material' => null,
                'destinatarios' => ['Nivel tactico', 'Nivel operativo'],
                'instructor_funcion' => 'Analista de Turno saliente',
                'duracion_minutos' => 10,
                'vigencia_meses' => 6,
                'umbral_aprobacion' => 80,
                'cuenta_para_vigencia' => false,
                'exige_a_todo_el_equipo' => false,
                'programada_para' => null,
                'nota_programacion' => 'POL-006 seccion 3: mensual. El plan no enumera los meses ni los temas, '
                    .'de modo que aqui consta la actividad y no un calendario inventado.',
                'fuente' => $documento.', seccion 3',
            ],
        ];
    }
}
