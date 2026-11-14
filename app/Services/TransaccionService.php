<?php

namespace App\Services;

use App\Models\Transaccion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * El proceso de cobro, fuera del controlador.
 *
 * Esta clase es el Laboratorio 3 y 4 de la Semana 18. Lo que vive aquí
 * es una sola cosa: lo que significa cobrar y lo que significa cerrar
 * un cobro en este negocio. No sabe de HTTP, y esa ignorancia es el
 * punto, no un descuido:
 *
 *   - No recibe Request. Recibe el usuario y un arreglo de datos ya
 *     validados. Si recibiera Request, el servicio solo podría
 *     llamarse desde una petición web, y la mitad de la ganancia se
 *     iría: un comando de consola, un job en cola o un seeder no
 *     tienen Request que pasarle.
 *
 *   - No devuelve response(). Devuelve el modelo. Quién lo convierte en
 *     JSON, con qué campos y con qué código de estado es decisión del
 *     Resource y del controlador, que para eso están.
 *
 *   - Para las reglas de negocio que no se cumplen lanza excepciones.
 *     Una ValidationException Laravel la convierte en 422 con el mismo
 *     formato que cualquier otro error de validación, así que el
 *     cliente no nota la diferencia, y el servicio no tuvo que saber
 *     qué es un 422.
 *
 * Los métodos públicos son tres y cada uno es una operación completa
 * del negocio: registrar un cobro, corregir sus datos y cerrarlo.
 */
class TransaccionService
{
    /**
     * Registra un cobro nuevo.
     *
     * Antes esto era el cuerpo de store(). Se movió tal cual, sin
     * cambiarle nada, porque la regla del día es que el comportamiento
     * no cambia: las seis pruebas del Laboratorio 2 estaban en verde
     * antes de mover la primera línea y siguieron en verde después.
     *
     * $usuario->comercios()->findOrFail(...) hace dos cosas de una:
     * trae el comercio y comprueba que sea de quien cobra, porque la
     * consulta arranca en el usuario y no en Comercio::find(). Es la
     * misma idea de la Semana 14, y acá además es defensa de fondo: en
     * una petición HTTP esta línea nunca falla, porque el Form Request
     * ya rechazó el comercio ajeno con 422 antes de llegar. Existe para
     * cuando el servicio se llame desde otro lado, donde no hay Form
     * Request que cuide la puerta.
     *
     * @param  array<string, mixed>  $datos
     */
    public function registrar(User $usuario, array $datos): Transaccion
    {
        $comercio = $usuario->comercios()->findOrFail($datos['comercio_id']);

        $comision = $datos['metodo'] === 'tarjeta'
            ? round($datos['monto'] * 0.035, 2)
            : 1.50;

        // Los dos INSERT van juntos o no van: una transacción sin su
        // evento de creación es una fila sin historia, y la bitácora
        // de la Semana 15 existe justamente para que eso no pase.
        $transaccion = DB::transaction(function () use ($comercio, $datos, $comision) {
            $transaccion = $comercio->transacciones()->create([
                'cliente_nombre' => $datos['cliente_nombre'],
                'monto' => $datos['monto'],
                'metodo' => $datos['metodo'],
                'comision' => $comision,
            ]);

            // El estado no viaja en la petición: lo pone la base de
            // datos con el default de la migración. refresh() relee la
            // fila para que el modelo lo traiga, o la respuesta saldría
            // con "estado": null.
            $transaccion->refresh();

            $transaccion->eventos()->create([
                'tipo_evento' => 'creacion',
                'detalle' => 'Transacción iniciada desde la API',
            ]);

            return $transaccion;
        });

        Log::info("Transacción {$transaccion->id} registrada");

        return $transaccion;
    }
}
