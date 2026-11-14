<?php

namespace App\Services;

use App\Models\Transaccion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

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

        $comision = $this->comision($datos['metodo'], $datos['monto']);

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

    /**
     * Corrige los datos de un cobro.
     *
     * Lo que antes hacía el controlador en dos líneas (autorizar y
     * update) se mudó acá, y en el camino apareció una regla que no
     * estaba escrita en ninguna parte: si cambia el monto o el método,
     * la comisión que quedó guardada ya no es la que corresponde. Una
     * fila con monto 200 y comisión 3.50 de tarjeta no es un dato
     * viejo, es un dato falso.
     *
     * Esa regla es del negocio y no de la validación: no se puede
     * comprobar mirando el cuerpo de la petición, hay que saber cómo se
     * calcula una comisión. Por eso vive en el servicio y no en el Form
     * Request, y por eso el controlador ni se enteró de que existe.
     *
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(User $usuario, Transaccion $transaccion, array $datos): Transaccion
    {
        $transaccion = $this->suya($usuario, $transaccion);

        // Si la actualización mueve la transacción a otro comercio, el
        // comercio nuevo también tiene que ser de quien pide. En HTTP
        // lo cuida el Form Request; acá es la misma defensa de fondo
        // que en registrar().
        if (isset($datos['comercio_id'])) {
            $usuario->comercios()->findOrFail($datos['comercio_id']);
        }

        // ?? y no isset a secas: lo que no viene en la petición no se
        // cambia, así que el valor que cuenta es el que ya tiene la
        // fila.
        $datos['comision'] = $this->comision(
            $datos['metodo'] ?? $transaccion->metodo,
            $datos['monto'] ?? $transaccion->monto
        );

        $transaccion->update($datos);

        Log::info("Transacción {$transaccion->id} actualizada");

        return $transaccion;
    }

    /**
     * Cierra un cobro: lo mueve de Iniciada a Completada o Fallida.
     *
     * Aquí están las cuatro preguntas del esqueleto del Laboratorio 4,
     * contestadas en el orden en que importan:
     *
     *   1. La propiedad se comprueba volviendo a buscar la transacción
     *      desde el usuario, en suya(). No se confía en el modelo que
     *      llegó por parámetro, porque quien lo resolvió fue el route
     *      model binding a partir de un número de la URL, y ese número
     *      lo escribe quien pide.
     *
     *   2. La regla «solo desde Iniciada» va acá y no en el Form
     *      Request, y la diferencia es de qué se puede saber dónde. El
     *      Form Request ve el cuerpo de la petición; esta regla depende
     *      del estado actual de la fila, que está en la base de datos.
     *      Se responde con ValidationException::withMessages, que
     *      Laravel convierte en el mismo 422 de siempre.
     *
     *   3. Sí hace falta DB::transaction: se escribe en dos tablas, y
     *      un estado movido sin su evento deja un cambio sin historia.
     *
     *   4. El Log va afuera de la transacción, cuando ya es cierto. Un
     *      log dentro del bloque podría afirmar un cambio que después
     *      se deshace con el ROLLBACK.
     */
    public function cambiarEstado(User $usuario, Transaccion $transaccion, string $nuevo): Transaccion
    {
        $transaccion = $this->suya($usuario, $transaccion);

        if ($transaccion->estado !== 'Iniciada') {
            throw ValidationException::withMessages([
                'estado' => "Esta transacción ya está {$transaccion->estado} y su estado no se puede volver a cambiar.",
            ]);
        }

        $anterior = $transaccion->estado;

        DB::transaction(function () use ($transaccion, $nuevo, $anterior) {
            $transaccion->update(['estado' => $nuevo]);

            $transaccion->eventos()->create([
                'tipo_evento' => 'cambio_estado',
                'detalle' => "{$anterior} -> {$nuevo}",
            ]);
        });

        Log::info("Transacción {$transaccion->id}: {$anterior} -> {$nuevo}");

        return $transaccion;
    }

    /**
     * Borra un cobro propio.
     *
     * Dos líneas, y aun así vino al servicio. No por la lógica, que no
     * tiene, sino para que la comprobación de propiedad de las tres
     * escrituras sea una sola y esté en un solo lugar. Si se hubiera
     * quedado en el controlador, mañana habría dos maneras de
     * responder la misma pregunta y una de las dos envejecería.
     */
    public function eliminar(User $usuario, Transaccion $transaccion): void
    {
        $transaccion = $this->suya($usuario, $transaccion);

        $transaccion->delete();

        Log::info("Transacción {$transaccion->id} eliminada");
    }

    /**
     * La transacción, buscada desde su dueño.
     *
     * La consulta arranca en $usuario->transacciones(), que es la
     * relación hasManyThrough de la Semana 14: pasa por comercios y
     * trae solo lo que cuelga de este usuario. Si el id no es suyo, no
     * aparece, y firstOrFail lanza ModelNotFoundException, que Laravel
     * responde como 404.
     *
     * Es 404 y no 403 a propósito, y es la misma decisión de la Semana
     * 14: un 403 confirmaría que esa transacción existe en la cuenta de
     * alguien más.
     *
     * select('transacciones.*') limpia la columna extra que
     * hasManyThrough agrega para saber por dónde pasó (la llave del
     * comercio). Sin eso, el modelo vuelve con un atributo que no es
     * suyo.
     */
    private function suya(User $usuario, Transaccion $transaccion): Transaccion
    {
        return $usuario->transacciones()
            ->select('transacciones.*')
            ->where('transacciones.id', $transaccion->getKey())
            ->firstOrFail();
    }

    /**
     * La comisión del cobro.
     *
     * Este if es el que el Laboratorio 5 reemplaza por clases. Queda
     * aquí, en un método privado y nombrado, por una razón intermedia:
     * registrar() y actualizar() lo necesitan los dos, y tenerlo
     * escrito dos veces habría sido la peor versión de todas.
     */
    private function comision(string $metodo, float|string $monto): float
    {
        return $metodo === 'tarjeta'
            ? round($monto * 0.035, 2)
            : 1.50;
    }
}
