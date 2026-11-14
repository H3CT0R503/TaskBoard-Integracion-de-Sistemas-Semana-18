<?php

namespace App\Rules;

use App\Services\CalculadoraComision;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * ¿Hay una regla de comisión para este método de pago?
 *
 * Esta clase salió del reto del Laboratorio 5, y no estaba planeada.
 *
 * Agregar la billetera, con las reglas en clases, tendría que haber
 * sido una clase nueva y una línea en el contenedor. Fueron dos
 * archivos: también hubo que abrir GuardarTransaccionRequest, porque
 * ahí vivía un Rule::in(['tarjeta', 'transferencia']) con la lista de
 * métodos escrita a mano. O sea que la lista estaba en dos lugares, y
 * el principio abierto/cerrado se cumplía solo del lado del cálculo.
 *
 * Lo peor no era tocar dos archivos sino lo que pasaba si se olvidaba
 * uno, y los dos olvidos fallan distinto:
 *
 *   - Regla nueva sin tocar la validación: el método existe para la
 *     calculadora pero la API lo rechaza con 422. Molesto y visible.
 *
 *   - Validación nueva sin la regla: la API acepta el método, el
 *     cálculo llega a la calculadora, no encuentra regla y lanza
 *     InvalidArgumentException, que es un 500. Un cobro que revienta
 *     en producción porque dos listas no coincidían.
 *
 * Con esta regla la lista queda en un solo lugar, el contenedor, y la
 * validación la consulta en vez de repetirla. Agregar un método de pago
 * vuelve a ser una clase nueva y una línea.
 *
 * El precio está a la vista y lo acepto: el mensaje de error ya no
 * puede nombrar los métodos válidos, porque la calculadora sabe
 * contestar "¿soportás esto?" pero no "¿qué soportás?". Preferible a
 * dos listas que pueden discrepar.
 */
class MetodoDePagoSoportado implements ValidationRule
{
    public function __construct(private CalculadoraComision $calculadora)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->calculadora->soporta($value)) {
            $fail('Ese método de pago no está disponible.');
        }
    }
}
