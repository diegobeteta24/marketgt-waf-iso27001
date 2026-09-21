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
        Schema::create('pedidos', function (Blueprint $table) {
            $table->id();
            // Identificador publico del pedido (MG-2026-000042). Se muestra al cliente en lugar
            // del id autoincremental para no revelar cuantos pedidos lleva la tienda.
            $table->string('numero', 24)->unique();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();

            $table->string('nombre_cliente', 120);
            $table->string('correo_cliente', 160);
            $table->string('telefono_cliente', 30);
            $table->string('direccion_envio', 255);
            $table->string('municipio_envio', 120);
            $table->string('departamento_envio', 120);
            $table->string('referencia_envio', 255)->nullable();

            $table->decimal('subtotal', 10, 2);
            $table->decimal('envio', 10, 2)->default(0);
            $table->decimal('total', 10, 2);

            $table->enum('estado', ['pendiente', 'pagado', 'enviado', 'entregado', 'cancelado'])
                ->default('pendiente');

            // TOKENIZACION DE PAGO (Capa 6 - Datos, requisito PCI DSS del proyecto).
            // La tienda NUNCA persiste el numero de tarjeta, ni cifrado ni truncado a mas de
            // cuatro digitos, ni el codigo de verificacion, ni la fecha de vencimiento. El numero
            // se valida en memoria (algoritmo de Luhn), se sustituye por un token opaco emitido
            // por la pasarela simulada y se descarta. Los unicos rastros que quedan aqui son los
            // cuatro ultimos digitos y la marca, que son los datos minimos para que el cliente
            // reconozca su tarjeta en el comprobante. Consecuencia buscada: una filtracion
            // completa de esta tabla no expone ni un solo numero de tarjeta reutilizable.
            $table->char('ultimos_cuatro', 4);
            $table->string('marca_tarjeta', 30);
            $table->string('token_pago', 64)->unique();
            $table->timestamp('pagado_en')->nullable();

            $table->timestamps();

            $table->index(['usuario_id', 'created_at']);
            $table->index('estado');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pedidos');
    }
};
