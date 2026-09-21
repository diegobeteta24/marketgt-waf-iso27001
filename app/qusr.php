<?php
$u = App\Models\User::query()->get();
echo "USUARIOS (" . $u->count() . "):\n";
foreach ($u as $x) {
    $roles = method_exists($x, 'roles') ? $x->roles()->pluck('nombre')->implode(', ') : '(sin metodo roles)';
    echo sprintf("  %-32s | %-22s | %s\n", $x->email, $x->name, $roles ?: '(ninguno)');
}
echo "\nROLES:\n";
foreach (DB::table('roles')->get() as $r) {
    echo "  " . ($r->nombre ?? '?') . " - " . ($r->descripcion ?? '') . "\n";
}
