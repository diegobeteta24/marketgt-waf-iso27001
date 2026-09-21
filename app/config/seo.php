<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Defensa contra ataques de posicionamiento (capa 5)
|--------------------------------------------------------------------------
|
| Este archivo existe para que la válvula del middleware DetectarCloaking se
| pueda cerrar sin editar código. El middleware ya la consultaba, pero el
| archivo no estaba, así que el valor por defecto de la constante era la única
| respuesta posible y "desactivar el bloqueo" exigía tocar una clase en
| producción, con prisa y en caliente. Una válvula que hay que recompilar no
| es una válvula.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Bloquear al rastreador falsificado
    |--------------------------------------------------------------------------
    |
    | En true se responde 403 a quien dice ser un buscador desde una dirección
    | que no supera la verificación inversa de DNS. En false el incidente se
    | registra igual y la petición continúa.
    |
    | Póngase en false si el servidor pierde la resolución de DNS o si los
    | proxies de confianza no están bien declarados: en ese estado NINGUNA
    | verificación puede completarse y el Googlebot legítimo recibiría 403 en
    | cada visita. Eso desindexa la tienda en días y cuesta más caro que el
    | ataque que se quería frenar.
    |
    */

    'bloquear_rastreador_falso' => (bool) env('SEO_BLOQUEAR_RASTREADOR_FALSO', true),

];
