<?php

namespace App\Services\Comisiones;

/**
 * Billetera digital: 2 % del monto.
 *
 * Esta clase es el reto del Laboratorio 5, y lo que vale no es lo que
 * dice sino lo que NO hizo falta tocar para que funcione:
 * ComisionTarjeta, ComisionTransferencia y CalculadoraComision quedaron
 * exactamente como estaban. Eso es el principio abierto/cerrado dejando
 * de ser una frase: el comportamiento nuevo entró por una clase nueva.
 *
 * Que se parezca tanto a ComisionTarjeta es a propósito y no es
 * repetición: son dos reglas de negocio distintas que hoy coinciden en
 * la forma. El día que a la billetera le pongan un mínimo de 0.25, o un
 * techo, se cambia acá y la tarjeta no se enterará. Si las dos
 * compartieran una clase "porcentaje", ese cambio obligaría a
 * separarlas primero.
 */
class ComisionBilletera implements ReglaComision
{
    public function aplica(string $metodo): bool
    {
        return $metodo === 'billetera';
    }

    public function calcular(float $monto): float
    {
        return round($monto * 0.02, 2);
    }
}
