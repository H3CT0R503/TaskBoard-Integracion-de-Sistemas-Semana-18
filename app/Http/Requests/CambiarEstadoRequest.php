<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * El cuerpo de PATCH /api/transacciones/{id}/estado.
 *
 * Es un Form Request propio y no una regla metida en
 * GuardarTransaccionRequest, porque son dos operaciones distintas con
 * dos cuerpos distintos: una manda comercio, cliente y monto, la otra
 * manda un estado y nada más. Compartir la clase habría obligado a
 * llenarla de condiciones según el verbo, que es la forma más rápida de
 * volver ilegible una validación.
 *
 * Lo que está acá es validación de datos pura: ¿es 'estado' uno de los
 * valores que este endpoint acepta? Esa pregunta se contesta leyendo el
 * cuerpo. La otra pregunta, la de si ESTA transacción puede cambiar de
 * estado, necesita saber en qué estado está hoy, así que no se puede
 * contestar acá y vive en el servicio.
 */
class CambiarEstadoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Solo los dos estados de cierre. 'Iniciada' no está en la
            // lista a propósito: este endpoint cierra cobros, no los
            // reabre, y dejar pasar un cambio de Iniciada a Iniciada
            // habría escrito un evento "Iniciada -> Iniciada" que no
            // cuenta nada.
            'estado' => ['required', Rule::in(['Completada', 'Fallida'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'estado.required' => 'Debes indicar a qué estado se mueve la transacción.',
            'estado.in' => 'Una transacción solo se puede cerrar como Completada o Fallida.',
        ];
    }
}
