<?php
use App\Models\EventoSeguridad as E;
use App\Models\AlertaSeguridad as A;
$d = now()->subDay();
echo "eventos totales:          " . E::count() . "\n";
echo "eventos ultimas 24h:      " . E::where('marca_tiempo','>=',$d)->count() . "\n";
echo "bloqueados ultimas 24h:   " . E::where('marca_tiempo','>=',$d)->where('fue_bloqueado',true)->count() . "\n";
echo "bloqueados en total:      " . E::where('fue_bloqueado',true)->count() . "\n";
echo "reales (no demostracion): " . E::where('es_demostracion',false)->count() . "\n";
echo "alertas abiertas:         " . A::whereIn('estado',['nueva','en_triaje'])->count() . "\n";
