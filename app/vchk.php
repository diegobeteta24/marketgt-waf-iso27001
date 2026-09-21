<?php
$vistas = ['dashboard', 'layouts.app.sidebar'];
foreach ($vistas as $v) {
    try {
        $ruta = view()->getFinder()->find($v);
        $php  = app('blade.compiler')->compileString(file_get_contents($ruta));
        $tmp  = sys_get_temp_dir() . '/chk_' . md5($v) . '.php';
        file_put_contents($tmp, $php);
        $out = shell_exec('php -l ' . escapeshellarg($tmp) . ' 2>&1');
        echo str_pad($v, 26) . (str_contains($out, 'No syntax errors') ? 'COMPILA OK' : 'ERROR: ' . trim($out)) . "\n";
        @unlink($tmp);
    } catch (\Throwable $e) {
        echo str_pad($v, 26) . 'FALLO: ' . $e->getMessage() . "\n";
    }
}
