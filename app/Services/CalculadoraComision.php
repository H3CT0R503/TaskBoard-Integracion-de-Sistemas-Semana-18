<?php

namespace App\Services;

use App\Services\Comisiones\ReglaComision;
use InvalidArgumentException;

/**
 * Elige la regla que corresponde y le pide el número.
 *
 * Esta clase es el principio abierto/cerrado del Laboratorio 5. Lo que
 * se reemplazó fue un if de dos ramas dentro del servicio:
 *
 *     $comision = $metodo === 'tarjeta'
 *         ? round($monto * 0.035, 2)
 *         : 1.50;
 *
 * Ese if funcionaba, y no era difícil de leer. El problema era otro:
 * cada método de pago nuevo obligaba a volver a abrir un archivo
 * probado y agregarle una rama, y el "else" se quedaba con el valor de
 * transferencia por descuido histórico, así que un método inventado
 * cobraba 1.50 en silencio en lugar de quejarse.
 *
 * Ahora no hay nada que modificar. Una regla nueva es una clase nueva
 * que implementa ReglaComision, y esta clase sigue igual, porque no
 * conoce ningún método de pago: solo sabe recorrer las reglas que le
 * dieron y preguntarle a cada una si le toca.
 *
 * El foreach se queda con la PRIMERA que dice que sí, así que el orden
 * de las reglas es parte de la configuración. Con reglas que no se
 * pisan (una por método) el orden no cambia nada; el día que haya una
 * regla más específica (por ejemplo, tarjeta de un comercio grande),
 * tendrá que ir antes que la general.
 */
class CalculadoraComision
{
    /**
     * @param  ReglaComision[]  $reglas
     */
    public function __construct(private array $reglas)
    {
    }

    public function calcular(string $metodo, float $monto): float
    {
        foreach ($this->reglas as $regla) {
            if ($regla->aplica($metodo)) {
                return $regla->calcular($monto);
            }
        }

        // Excepción y no un 0, ni un 1.50, ni un null.
        //
        // Llegar hasta acá significa que el sistema aceptó un método de
        // pago para el que nadie sabe cuánto cobrar, y eso no se arregla
        // devolviendo un número cualquiera: se arregla parando. Un cero
        // silencioso sería un cobro sin comisión que nadie notaría hasta
        // cerrar el mes.
        //
        // En una petición HTTP esto no debería pasar nunca, porque el
        // Form Request rechaza los métodos que la calculadora no
        // soporta. Si pasa, es que alguien agregó un método a la
        // validación y se olvidó de la regla, y entonces quiero el
        // error, no el silencio.
        throw new InvalidArgumentException("Método de pago no soportado: {$metodo}");
    }

    /**
     * ¿Hay alguna regla para este método de pago?
     *
     * Existe para que la validación pueda preguntarle a la calculadora
     * en vez de llevar su propia lista de métodos escrita a mano. Sin
     * esto, agregar un método de pago tocaba dos lugares: el contenedor
     * y el Form Request, y la lista del Form Request podía quedarse
     * atrás sin que nada avisara.
     *
     * Recorre las reglas igual que calcular(), pero sin pedirles el
     * número: la pregunta es si alguien se hace cargo, no cuánto cobra.
     */
    public function soporta(string $metodo): bool
    {
        foreach ($this->reglas as $regla) {
            if ($regla->aplica($metodo)) {
                return true;
            }
        }

        return false;
    }
}
