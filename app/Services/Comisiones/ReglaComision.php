<?php

namespace App\Services\Comisiones;

/**
 * El contrato de una regla de comisión.
 *
 * Dos métodos, y ninguno de más. Esa es la I de SOLID: una interfaz
 * pequeña, donde nadie implementa algo que no usa. Con tres o cuatro
 * métodos más (que el nombre del método, que el porcentaje, que si
 * aplica a un comercio en particular) cada regla nueva tendría que
 * escribir métodos vacíos para cumplir el contrato.
 *
 * El reparto es el que decide todo lo demás:
 *
 *   aplica()   ¿esta regla es la que corresponde a este método de pago?
 *   calcular() dado un monto, ¿cuánto se cobra?
 *
 * Que sea la regla la que contesta "¿aplico yo?" es lo que deja a la
 * calculadora sin ningún if de métodos de pago. La calculadora no sabe
 * que existe la tarjeta: solo sabe preguntar.
 */
interface ReglaComision
{
    public function aplica(string $metodo): bool;

    public function calcular(float $monto): float;
}
