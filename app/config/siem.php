<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Configuración del panel de monitoreo (vértice de detección)
|--------------------------------------------------------------------------
|
| Este archivo existe por una razón concreta: las rutas de los registros que
| alimentan el panel las fija el conjunto de contenedores, no la aplicación.
| Dejarlas escritas dentro del comando de ingesta hacía que un cambio en la
| configuración del cortafuegos rompiera la ingesta en silencio, sin error
| visible y con el panel apareciendo simplemente vacío.
|
| La ruta del registro del cortafuegos debe coincidir EXACTAMENTE con la
| variable MODSEC_AUDIT_LOG de infra/docker/docker-compose.yml.
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | Orígenes de eventos
    |----------------------------------------------------------------------
    |
    | El registro del cortafuegos se escribe dentro del contenedor del WAF,
    | en el único directorio que su imagen deja escribible para el usuario
    | sin privilegios. El contenedor de la aplicación lo monta en modo de
    | solo lectura: la aplicación consume eventos, nunca los altera, que es
    | lo que exige el control A.8.15 sobre integridad de los registros.
    |
    */

    'rutas' => [
        'waf'        => env('SIEM_RUTA_WAF', '/var/log/modsecurity/audit/audit.json'),
        'aplicacion' => env('SIEM_RUTA_APLICACION', storage_path('logs/seguridad.log')),
        'sistema'    => env('SIEM_RUTA_SISTEMA', '/var/log/marketgt/sistema.log'),
    ],

    /*
    |----------------------------------------------------------------------
    | Ingesta
    |----------------------------------------------------------------------
    |
    | La ingesta es incremental: recuerda el desplazamiento donde se quedó
    | para no reprocesar el archivo completo en cada pasada. Si el archivo
    | encoge respecto al desplazamiento guardado, se asume que fue rotado y
    | se vuelve a leer desde el principio.
    |
    */

    'ingesta' => [
        // Cota por pasada. Evita que una ráfaga de ataques agote la memoria
        // del proceso durante la demostración en vivo.
        'lineas_por_pasada' => (int) env('SIEM_LINEAS_POR_PASADA', 2000),

        // Una línea corrupta no debe detener la ingesta: se descarta y se
        // contabiliza. Un registro truncado por rotación es normal.
        'tolerar_lineas_invalidas' => true,

        // Segundos entre pasadas cuando el comando corre en modo continuo.
        'intervalo_segundos' => (int) env('SIEM_INTERVALO', 5),
    ],

    /*
    |----------------------------------------------------------------------
    | Retención de eventos
    |----------------------------------------------------------------------
    |
    | Conforme a la política de retención del proyecto y al control A.8.15:
    | noventa días accesibles en línea y doce meses en almacenamiento frío.
    | La purga mueve a almacenamiento frío antes de borrar; nunca elimina
    | sin haber archivado.
    |
    */

    'retencion' => [
        'dias_en_linea'     => (int) env('SIEM_RETENCION_DIAS', 90),
        'meses_en_frio'     => 12,
        'ruta_archivo_frio' => storage_path('app/archivo-eventos'),
    ],

    /*
    |----------------------------------------------------------------------
    | Umbrales de las métricas del triángulo
    |----------------------------------------------------------------------
    |
    | Son las metas declaradas en el anexo del proyecto. El panel compara el
    | valor calculado contra estas metas y señala si se cumplen. Cuando una
    | métrica no puede calcularse con los datos disponibles, el panel debe
    | mostrarla como «sin datos» en lugar de suponer un valor: una métrica
    | inventada invalidaría la auditoría completa.
    |
    */

    'metas' => [
        'cobertura_parcheo_critico_horas'   => 72,
        'personal_capacitado_porcentaje'    => 100,
        'tiempo_medio_deteccion_minutos'    => 30,
        'tasa_falsos_positivos_porcentaje'  => 2.0,
        'tiempo_medio_contencion_horas'     => 2,
        'objetivo_tiempo_recuperacion_horas' => 4,
        'objetivo_punto_recuperacion_horas'  => 24,
    ],

];
