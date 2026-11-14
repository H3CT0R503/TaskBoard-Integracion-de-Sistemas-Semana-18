<?php

namespace App\Services\Comisiones;

/**
 * Tarjeta: 3.5 % del monto.
 *
 * El round(..., 2) no es cosmético. 45 * 0.035 da 1.5749999999999999
 * en punto flotante, y guardar eso en una columna decimal(8,2) deja
 * que la base de datos redondee por su cuenta. Mejor decidirlo acá,
 * donde se ve, que descubrirlo cuando dos cálculos del mismo cobro no
 * coincidan en el último centavo.
 */
class ComisionTarjeta implements ReglaComision
{
    public function aplica(string $metodo): bool
    {
        return $metodo === 'tarjeta';
    }

    public function calcular(float $monto): float
    {
        return round($monto * 0.035, 2);
    }
}
