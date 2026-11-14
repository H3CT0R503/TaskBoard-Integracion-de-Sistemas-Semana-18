<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Semana 18 · dos columnas nuevas para la comisión del cobro.
     *
     * La guía supone que transacciones ya tiene metodo y comision. Este
     * proyecto nunca las tuvo: hasta la Semana 16 una transacción era
     * monto, cliente y estado, y nada más. Así que la semana empieza
     * agregándolas, porque sin ellas los Laboratorios 2 y 5 no tienen
     * de qué hablar.
     *
     * metodo es string y no enum, al contrario de estado. La decisión es
     * a propósito y el Laboratorio 5 la explica: la lista de métodos de
     * pago va a crecer (hoy tarjeta y transferencia, mañana billetera),
     * y un enum de MySQL obliga a un ALTER TABLE por cada método nuevo.
     * estado, en cambio, son tres valores que son el negocio mismo y no
     * se espera que cambien, así que ahí el enum sí paga.
     *
     * default('tarjeta') es lo que mantiene la promesa de la semana: el
     * cliente web y la colección de Bruno que ya existen no envían
     * metodo, y tienen que seguir funcionando igual que ayer.
     *
     * comision queda nullable y no default(0). Las cuatro filas que
     * sembró el seeder antes de hoy no tuvieron comisión: nadie se la
     * cobró a nadie. Un 0.00 ahí sería una afirmación falsa, "se cobró
     * cero", cuando lo cierto es "no se sabe". NULL dice eso y no más.
     *
     * decimal(8,2) por el mismo motivo que monto: el dinero no se guarda
     * en float.
     */
    public function up(): void
    {
        Schema::table('transacciones', function (Blueprint $table) {
            $table->string('metodo', 20)
                ->default('tarjeta')
                ->after('monto');

            $table->decimal('comision', 8, 2)
                ->nullable()
                ->after('metodo');
        });
    }

    public function down(): void
    {
        Schema::table('transacciones', function (Blueprint $table) {
            $table->dropColumn(['metodo', 'comision']);
        });
    }
};
