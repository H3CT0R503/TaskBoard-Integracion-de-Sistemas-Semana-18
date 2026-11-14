<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reglas del formulario de transacciones, sacadas del controlador.
 *
 * Antes esto era un validate() dentro de store(). Como clase aparte se
 * reutiliza tal cual en update(), y el controlador queda con la lógica
 * del flujo nada más.
 */
class GuardarTransaccionRequest extends FormRequest
{
    /**
     * Responde si quien envía la petición tiene permiso, que es una
     * pregunta distinta a si los datos son correctos.
     *
     * Artisan lo genera en false y eso devuelve 403. Va en true
     * porque la pregunta de quién entra ya la responde el middleware
     * auth de la ruta: sin sesión, la petición no llega hasta este
     * Form Request.
     *
     * Y la del dueño del comercio, que la semana pasada quedó
     * pendiente justo acá, se resolvió dentro de rules(): el
     * comercio_id se valida contra los comercios del usuario de la
     * sesión, que es más preciso que un true o false para toda la
     * petición y además devuelve un mensaje de validación en vez de
     * un 403 seco.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Reglas de cada campo.
     *
     * La de comercio_id es la que importa de más, y esta semana
     * cambió. Antes era exists:comercios,id, que preguntaba solo si
     * el comercio existía: con eso, cambiar el número del campo
     * oculto desde las herramientas del navegador alcanzaba para
     * colgarle una transacción al comercio de otra persona.
     *
     * Ahora el exists lleva un where user_id, así que el comercio
     * tiene que existir Y ser del usuario de la sesión. Filtrar el
     * <select> del formulario no sustituye esto: el navegador es del
     * atacante, y una opción borrada de la lista se vuelve a escribir
     * en dos clics o se manda la petición sin formulario. Lo único
     * que de verdad decide es la validación del servidor.
     *
     * $this->user() dentro de un Form Request es el usuario
     * autenticado, el mismo que devolvería Auth::user().
     *
     * En la Semana 13 esta regla tuvo que partirse en dos con un if,
     * porque el mismo Form Request empezó a validar también las
     * peticiones de la API y ahí $this->user() era null: las rutas de
     * routes/api.php no usaban sesión, así que leerle ->id reventaba
     * con un 500 antes de poder validar nada. Sin usuario la regla
     * caía a un exists simple, que comprobaba solo que el comercio
     * existiera, y por esa rama cualquiera podía colgarle una
     * transacción al comercio de otro.
     *
     * Esta semana el if se va, y eso es lo que mejor mide lo que
     * cambió: dentro de auth:sanctum SIEMPRE hay usuario, porque
     * cada petición llega firmada con un token y Sanctum lo resuelve
     * antes de que el Form Request corra. $this->user() dentro de un
     * Form Request es el usuario autenticado, venga de la sesión del
     * navegador o del token de la API, así que la regla vuelve a ser
     * una sola y la rama insegura desaparece del archivo.
     *
     * Dejar el if "por si acaso" sería peor que quitarlo: una rama
     * que ya no se puede alcanzar igual se lee como una opción
     * válida, y el día que alguien saque una ruta del grupo
     * auth:sanctum, la validación se relajaría sola y en silencio. Si
     * eso pasa, ahora reventará con un error en vez de dejar pasar
     * datos ajenos, que es el fallo correcto.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'comercio_id' => [
                'required',
                Rule::exists('comercios', 'id')
                    ->where('user_id', $this->user()->id),
            ],
            'cliente_nombre' => ['required', 'string', 'max:255'],

            // Semana 18 · el método de pago, del que sale la comisión.
            //
            // La primera regla cambia según el verbo, y la diferencia
            // importa más de lo que parece:
            //
            //   POST → 'required'. No contradice que el cliente pueda
            //          omitirlo: prepareForValidation() lo rellena con
            //          'tarjeta' antes de que corran las reglas, así
            //          que al llegar aquí el campo siempre está. Hecho
            //          de este modo, y no con 'nullable', el servicio
            //          recibe un arreglo completo y no tiene que
            //          inventar valores por omisión: el valor por
            //          omisión vive en un solo lugar, y es este.
            //
            //   PUT  → 'sometimes'. Si el cliente no manda metodo, la
            //          regla no corre, el campo no entra en
            //          validated() y el update no lo toca. Sin esto, un
            //          PUT de los que ya existen (que nunca mandó
            //          metodo, porque hasta ayer no había columna) le
            //          cambiaría el método a 'tarjeta' a una
            //          transferencia, en silencio y dejándola con una
            //          comisión que ya no le corresponde.
            'metodo' => [
                $this->isMethod('POST') ? 'required' : 'sometimes',
                Rule::in(['tarjeta', 'transferencia']),
            ],

            // Las tres reglas del monto cubren los tres bordes que la
            // bitácora de casos límite encontró abiertos:
            //
            //   min:0.01          por abajo: un cobro de cero o
            //                     negativo no es un cobro
            //   decimal:0,2       en el medio: la columna guarda dos
            //                     decimales y un tercero no se puede
            //                     representar
            //   max:999999.99     por arriba: es el valor más grande
            //                     que entra en decimal(8,2)
            //
            // El max es la corrección del error E-01. Antes de ponerlo,
            // un monto de 100000000 llegaba hasta el INSERT y MySQL
            // respondía "Numeric value out of range", o sea un 500: el
            // cliente recibía "error del servidor" cuando el problema
            // era un dato suyo. El número no sale de ningún lado:
            // decimal(8,2) son 8 dígitos en total, 2 de ellos
            // decimales, así que el tope exacto es 999999.99.
            //
            // Vale la pena anotar lo que este arreglo NO es. Agrandar
            // la columna a decimal(15,2) también haría desaparecer el
            // 500 de este caso, pero solo correría el borde más
            // arriba: con un monto de un billón volvería el mismo
            // error. La diferencia es que validar pone el límite donde
            // se puede explicar, y la base de datos solo sabe reventar.
            //
            // decimal:0,2 es la corrección de E-02, y va con dos
            // números a propósito: decimal:2 exigiría EXACTAMENTE dos
            // decimales y rechazaría 25 y 25.5, que son montos
            // perfectamente válidos. El 0,2 acepta de cero a dos.
            'monto' => [
                'required',
                'numeric',
                'decimal:0,2',
                'min:0.01',
                'max:999999.99',
            ],

            // Corrección de E-03. El estado no se envía al crear: toda
            // transacción nace 'Iniciada' por el default de la columna
            // y solo se mueve con moverEstado(), que valida el paso.
            //
            // Antes, el controlador lo descartaba con only() y
            // respondía 201 sin decir nada. Esa era la parte mala: el
            // cliente pedía crear una transacción Completada, recibía
            // "listo" y se quedaba creyendo que el cobro estaba
            // cerrado, cuando en realidad nació Iniciada. En una
            // pasarela de pagos eso es plata que alguien da por
            // cobrada. 'prohibited' convierte ese silencio en un 422
            // que explica la regla.
            'estado' => ['prohibited'],
        ];
    }

    /**
     * Mensajes escritos a mano, para que el error explique qué hacer
     * en lugar de nombrar la regla que falló.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cliente_nombre.required' => 'Debes indicar el nombre del cliente.',

            // Corrección de E-04. Esta regla existía desde la Semana 9
            // y el mensaje nunca se escribió, así que un nombre de 256
            // caracteres respondía 422 con el texto "validation.max.string",
            // que es la clave interna del archivo de idiomas sin
            // traducir (el proyecto está en APP_LOCALE=es y no trae la
            // carpeta lang/es). El código de estado estaba bien; lo
            // que llegaba roto era lo único que una persona iba a leer.
            'cliente_nombre.max' => 'El nombre del cliente no puede pasar de 255 caracteres.',

            'monto.required' => 'Debes indicar un monto.',
            'monto.numeric' => 'El monto debe ser un número.',
            'monto.min' => 'El monto debe ser mayor a cero.',
            'monto.decimal' => 'El monto admite como máximo dos decimales.',
            'monto.max' => 'El monto no puede superar 999,999.99.',
            'estado.prohibited' => 'El estado no se envía al crear: toda transacción nace Iniciada.',
            'metodo.in' => 'El método de pago debe ser tarjeta o transferencia.',
            // El exists falla igual si el comercio no existe o si es
            // de otra persona, y el mensaje no distingue los dos
            // casos a propósito: no tiene por qué confirmar que el
            // comercio 7 existe y es de alguien más.
            'comercio_id.exists' => 'Ese comercio no está disponible en tu cuenta.',
        ];
    }

    /**
     * Nombres legibles para los mensajes que dejé por defecto. Con
     * esto el error dice "comercio" y no "comercio_id".
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'cliente_nombre' => 'nombre del cliente',
            'monto' => 'monto de la transacción',
            'comercio_id' => 'comercio',
            'metodo' => 'método de pago',
        ];
    }

    /**
     * Semana 18 · el valor por omisión del método de pago.
     *
     * Corre antes de las reglas y rellena metodo cuando el cliente no
     * lo manda. Es lo que deja que el cambio de esta semana sea seguro:
     * el cliente web de la Semana 14 y la colección de Bruno nunca
     * envían metodo, y tienen que seguir recibiendo 201 como ayer.
     *
     * Solo en POST. En PUT no se rellena nada, porque ahí "no lo mandó"
     * significa "no lo quiere cambiar" y no "quiere el de siempre".
     *
     * has() y no filled(): si alguien manda metodo vacío a propósito,
     * eso es un dato mal enviado y merece su 422, no un valor
     * inventado por el servidor.
     */
    protected function prepareForValidation(): void
    {
        if ($this->isMethod('POST') && ! $this->has('metodo')) {
            $this->merge(['metodo' => 'tarjeta']);
        }
    }
}
