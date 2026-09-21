<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Las tres columnas son TEXT y no VARCHAR aunque el dato original sea
            // corto: lo que se guarda no es el dato sino su sobre cifrado por
            // Laravel (AES-256-CBC en base64 con su firma HMAC), que multiplica
            // varias veces la longitud original. Un VARCHAR(50) para un teléfono
            // de ocho dígitos reventaría en la primera inserción.
            //
            // Consecuencia aceptada a cambio: sobre estas columnas no se puede
            // buscar ni ordenar en SQL, porque dos cifrados del mismo texto son
            // distintos. Para los datos personales de un cliente de la tienda no
            // hace falta: se leen de uno en uno, ya con el usuario cargado.
            $table->text('direccion')->nullable()->after('email_verified_at');
            $table->text('telefono')->nullable()->after('direccion');
            $table->text('documento_identidad')->nullable()->after('telefono');

            // Rastro mínimo del último acceso correcto. Se muestra al propio
            // usuario en la pantalla de sesiones: quien ve un acceso que no hizo
            // detecta el robo de su cuenta antes que cualquier control automático.
            $table->timestamp('ultimo_acceso_en')->nullable()->after('documento_identidad');
            $table->string('ultima_ip_acceso', 45)->nullable()->after('ultimo_acceso_en');

            // Desactivar sin borrar: borrar un usuario destruye la trazabilidad de
            // sus pedidos y de sus registros de auditoría, que es justo lo que una
            // auditoría necesita conservar.
            $table->boolean('activo')->default(true)->after('ultima_ip_acceso');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'direccion',
                'telefono',
                'documento_identidad',
                'ultimo_acceso_en',
                'ultima_ip_acceso',
                'activo',
            ]);
        });
    }
};
