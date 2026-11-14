<?php

namespace App\Enums;

/**
 * Los tres estados de una transacción, en un solo lugar.
 *
 * Antes de este archivo, 'Iniciada', 'Completada' y 'Fallida' estaban
 * escritos a mano en nueve lugares: la migración, el seeder, dos
 * validaciones de la API, el Form Request del cierre, el servicio, el
 * controlador web, la vista del panel y el componente del badge. Nueve
 * copias de la misma lista, y ninguna manera de que el día que aparezca
 * un estado nuevo alguien se enterara de las otras ocho.
 *
 * Un enum con respaldo de cadena (: string) es la forma correcta acá
 * porque el valor tiene que viajar: se guarda en una columna, sale en
 * el JSON y llega en una petición. El respaldo es ese texto; lo que
 * desaparece es tener que escribirlo.
 *
 * Y una vez que los estados son objetos y no cadenas, caben acá cosas
 * que antes no tenían dónde vivir. El mapa de transiciones estaba en el
 * controlador web, que es un lugar raro para una regla que no depende
 * de quién pregunte: desde una Completada no se puede ir a ninguna
 * parte, y eso es cierto en la web, en la API y en un comando de
 * consola. Ahora es un método del estado mismo.
 */
enum EstadoTransaccion: string
{
    case Iniciada = 'Iniciada';
    case Completada = 'Completada';
    case Fallida = 'Fallida';

    /**
     * Los estados con los que se cierra un cobro.
     *
     * Es la lista que acepta PATCH /api/transacciones/{id}/estado: ese
     * endpoint cierra transacciones, no las reabre.
     *
     * Devuelve los casos y no sus cadenas, porque quien la usa es
     * Rule::enum(...)->only(...), que compara casos.
     *
     * @return self[]
     */
    public static function deCierre(): array
    {
        return [self::Completada, self::Fallida];
    }

    /**
     * A qué estados se puede mover desde este.
     *
     * Una Iniciada se cierra bien o mal. Una Fallida se puede
     * reintentar. Una Completada ya movió dinero real y no se mueve
     * más: por eso devuelve un arreglo vacío y no hay que acordarse de
     * tratarla aparte.
     *
     * match y no un array literal, porque match sobre un enum es
     * exhaustivo: si mañana se agrega un cuarto estado y nadie dice a
     * dónde puede ir, PHP lanza UnhandledMatchError en vez de contestar
     * en silencio que no puede ir a ninguna parte.
     *
     * @return self[]
     */
    public function siguientes(): array
    {
        return match ($this) {
            self::Iniciada => [self::Completada, self::Fallida],
            self::Completada => [],
            self::Fallida => [self::Iniciada],
        };
    }

    public function puedeMoverseA(self $destino): bool
    {
        return in_array($destino, $this->siguientes(), true);
    }
}
