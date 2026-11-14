<?php

namespace App\Services\Comisiones;

/**
 * Transferencia: 1.50 fijo, sin importar el monto.
 *
 * Esta clase es la que explica por qué la interfaz recibe el monto
 * aunque acá no se use: el contrato tiene que servir para las dos
 * formas de cobrar, la que depende del monto y la que no. Una interfaz
 * hecha a la medida de la tarjeta (por ejemplo, un porcentaje() en vez
 * de un calcular()) no habría podido representar esta regla.
 *
 * Es también la L de SOLID funcionando: cualquier regla puede ocupar el
 * lugar de otra, porque todas prometen lo mismo, "dame un monto y te
 * digo la comisión", sin que a quien llama le importe cómo sale.
 */
class ComisionTransferencia implements ReglaComision
{
    public function aplica(string $metodo): bool
    {
        return $metodo === 'transferencia';
    }

    public function calcular(float $monto): float
    {
        return 1.50;
    }
}
