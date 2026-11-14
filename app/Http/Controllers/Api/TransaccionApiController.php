<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarTransaccionRequest;
use App\Http\Resources\TransaccionResource;
use App\Models\Transaccion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * La misma información que el controlador web, por la otra puerta.
 *
 * Ningún método devuelve una vista: todos devuelven un Resource, y
 * Laravel lo convierte en JSON. Tampoco hay redirects, porque del
 * otro lado no hay navegador que siga una redirección; lo que hay es
 * un programa que espera un código de estado y un cuerpo.
 *
 * Los códigos que usa este controlador:
 *   200  la consulta salió bien
 *   201  se creó un recurso (store)
 *   204  se hizo lo pedido y no hay nada que devolver (destroy)
 *   401  no sé quién eres (lo lanza auth:sanctum, antes de entrar acá)
 *   404  ese id no existe, o no existe PARA TI
 *   422  los datos no pasaron la validación (lo lanza el Form Request)
 *
 * Lo que cambia esta semana es el 404. La semana pasada significaba
 * una sola cosa, "ese id no está en la tabla", y lo lanzaba solo el
 * route model binding. Ahora significa dos, porque el controlador
 * también lo lanza cuando la fila existe pero es de otra persona.
 * Juntar los dos casos en el mismo código es deliberado: un 403
 * confirmaría que la transacción 4 existe y es de alguien más, y eso
 * ya es un dato que no se le debe a quien pregunta.
 *
 * El dueño de una transacción no está en la tabla transacciones: es
 * el dueño de su comercio. Por eso toda la autorización de este
 * archivo pasa por la relación comercio.
 */
class TransaccionApiController extends Controller
{
    /**
     * La verificación de propiedad, en un solo lugar.
     *
     * La escribo como método privado y no copiada en cada acción por
     * la razón de siempre: tres copias de la misma regla son tres
     * oportunidades de corregir solo dos. Si mañana la regla cambia
     * (por ejemplo, que un comercio pueda tener varios usuarios
     * autorizados), cambia acá y las tres acciones quedan al día.
     *
     * abort_unless() corta la ejecución y lanza la excepción HTTP:
     * si la condición es falsa, el resto del método nunca corre. Eso
     * es importante en update() y destroy(), donde seguir adelante
     * significaría escribir o borrar la fila de otra persona.
     *
     * auth()->id() dentro de auth:sanctum devuelve el dueño del
     * token, no el de una sesión: es el mismo valor que
     * $request->user()->id, nada más que sin tener que recibir el
     * Request en un método que no lo necesita para otra cosa.
     */
    private function autorizar(Transaccion $transaccion): void
    {
        abort_unless($transaccion->comercio->user_id === auth()->id(), 404);
    }

    /**
     * GET /api/transacciones — listado, con filtro opcional por estado.
     *
     * El reto de la guía. El parámetro llega por la URL
     * (?estado=Completada) y when() aplica el where solo si trae
     * algo, así que sin el parámetro la consulta queda exactamente
     * como estaba y el listado sigue devolviendo todo.
     *
     * Un estado que no existe, o uno válido sin filas, devuelve una
     * lista vacía con 200 y no un 404. El 404 significa "esa
     * dirección no corresponde a ningún recurso", y /api/transacciones
     * sí existe: la consulta se entendió y se resolvió bien. Que el
     * resultado tenga cero elementos es una respuesta correcta, no un
     * error. Devolver 404 obligaría al cliente a tratar como fallo
     * algo que simplemente es una lista sin elementos.
     *
     * latest() ordena por created_at descendente: lo más reciente
     * primero, que es lo que espera quien consulta movimientos.
     *
     * El cambio de esta semana es de dónde arranca la consulta. Antes
     * era Transaccion::query(), o sea la tabla completa, y con eso
     * Ana veía los cobros de Carlos. Ahora arranca en
     * $request->user()->transacciones(), la relación hasManyThrough
     * que recorre user -> comercios -> transacciones, así que el
     * filtro por dueño va en el JOIN y no en un where que se pueda
     * olvidar.
     *
     * El latest() lleva la columna escrita con su tabla
     * ('transacciones.created_at') justamente por ese JOIN: comercios
     * también tiene created_at, y un orderBy a secas deja MySQL con
     * dos columnas del mismo nombre y un error de columna ambigua.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        // MT-013 · correctivo.
        //
        // La tienda en línea reportó que ?estado=Pagada respondía 200
        // con una lista vacía, y tenía razón en quejarse: esa respuesta
        // le dice "no hay cobros pagados" cuando la verdad es "ese
        // estado no existe". El cliente no tiene forma de distinguir un
        // filtro que no encontró nada de un filtro escrito mal, así que
        // un error de tipeo suyo se le presenta como un dato del
        // negocio.
        //
        // El arreglo es el cambio mínimo que pedía la solicitud: validar
        // el parámetro contra la lista de estados reales. Lo que NO se
        // tocó es el resto del método, aunque el filtro de MT-014 use un
        // patrón parecido: una solicitud, un cambio.
        //
        // Es amarillo en el semáforo, no verde: una petición que antes
        // devolvía 200 ahora puede devolver 422. No rompe a nadie que
        // esté usando la API bien, pero sí cambia la respuesta para
        // quien venía mandando estados mal escritos, y por eso va
        // anotado con ⚠ en CAMBIOS.md.
        $datos = $request->validate([
            'estado' => ['nullable', Rule::in(['Iniciada', 'Completada', 'Fallida'])],
        ], [
            'estado.in' => 'El estado debe ser Iniciada, Completada o Fallida.',
        ]);

        $estado = $datos['estado'] ?? null;

        $transacciones = $request->user()->transacciones()
            ->when($estado, fn ($q) => $q->where('estado', $estado))
            ->latest('transacciones.created_at')
            ->get();

        return TransaccionResource::collection($transacciones);
    }

    /**
     * GET /api/transacciones/filtrar — filtra por estado y rango de fechas.
     *
     * Este método entra esta semana y no lo escribí de cero: salió de un
     * pedido a un asistente de IA y pasó por los seis pasos del método
     * antes de llegar acá. Lo que la IA entregó tenía seis problemas, y
     * cada línea de abajo es la corrección de uno de ellos. Queda
     * anotado porque ese es el punto de la práctica: el código asistido
     * no es malo ni bueno por venir de una IA, es responsabilidad de
     * quien lo firma.
     *
     * ① Fuera de auth:sanctum. La ruta venía suelta al final del
     *    archivo, así que cualquiera sin token veía los cobros de todos.
     *    Se corrige en routes/api.php, no acá.
     *
     * ② Orden de las rutas. La IA la puso DESPUÉS de apiResource, y eso
     *    no da ningún error al escribirlo: simplemente la palabra
     *    "filtrar" cae en el comodín {transaccion} de show(), que la
     *    busca como si fuera un id y responde 404. También se corrige en
     *    el archivo de rutas.
     *
     * ③ Sin filtro por usuario. Arrancaba en Transaccion::query(), o sea
     *    la tabla entera. Acá arranca en $request->user()->transacciones(),
     *    la misma relación que usa index() desde la Semana 14.
     *
     * ④ SQL crudo. Venía con whereRaw("estado = '$estado'"), que
     *    concatena texto del cliente dentro de la consulta. Con
     *    ?estado=x' OR '1'='1 la condición se vuelve siempre verdadera y
     *    devuelve TODO. where('estado', $valor) manda el dato por
     *    separado y el motor nunca lo lee como SQL.
     *
     * ⑤ Fechas sin validar. Aceptaba cualquier texto, y un ?desde=hola
     *    llegaba hasta la consulta. Ahora hay reglas date, y además
     *    after_or_equal:desde, que es la regla de negocio que la IA no
     *    podía adivinar: un rango al revés no es un rango.
     *
     * ⑥ Sin Resource. Devolvía response()->json($query->get()), que
     *    publica las columnas crudas de la tabla y rompe el contrato de
     *    la Semana 13. Se devuelve TransaccionResource::collection().
     */
    public function filtrar(Request $request): AnonymousResourceCollection
    {
        // Los tres parámetros son opcionales: sin ninguno, el endpoint
        // devuelve todas las transacciones del usuario. 'nullable' es lo
        // que permite eso sin tener que repetir la validación.
        //
        // Rule::in es lo que convierte el caso F-5 en un 422 antes de
        // tocar la base: un estado inventado, o una inyección, no está
        // en la lista y la petición muere en la validación.
        $datos = $request->validate([
            'estado' => ['nullable', Rule::in(['Iniciada', 'Completada', 'Fallida'])],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],

            // MT-014 · evolutivo. Dos parámetros nuevos para que la
            // tienda pueda revisar los cobros grandes.
            //
            // Los dos son OPCIONALES, y eso es lo que hace que este
            // cambio sea verde en el semáforo: quien ya usaba el
            // endpoint sin ellos sigue recibiendo exactamente lo mismo.
            //
            // La condición del monto_max es la trampa del día. La IA
            // propuso 'gte:monto_min' a secas, y eso hace fallar
            // ?monto_max=100 cuando no viene monto_min: la regla
            // compara contra un campo que no existe y revienta. Con
            // Rule::when la comparación solo se aplica cuando los dos
            // parámetros vinieron, que es lo que pide el criterio de
            // aceptación de MT-014: "monto_max >= monto_min SOLO
            // cuando vienen los dos".
            'monto_min' => ['nullable', 'numeric', 'min:0'],
            'monto_max' => ['nullable', 'numeric', 'min:0',
                Rule::when($request->filled('monto_min'), ['gte:monto_min'])],
        ], [
            'estado.in' => 'El estado debe ser Iniciada, Completada o Fallida.',
            'desde.date' => 'La fecha "desde" no tiene un formato válido.',
            'hasta.date' => 'La fecha "hasta" no tiene un formato válido.',
            'hasta.after_or_equal' => 'La fecha "hasta" no puede ser anterior a "desde".',
            'monto_min.numeric' => 'El monto mínimo debe ser un número.',
            'monto_min.min' => 'El monto mínimo no puede ser negativo.',
            'monto_max.numeric' => 'El monto máximo debe ser un número.',
            'monto_max.min' => 'El monto máximo no puede ser negativo.',
            'monto_max.gte' => 'El monto máximo no puede ser menor que el mínimo.',
        ]);

        $transacciones = $request->user()->transacciones()
            ->when($datos['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))

            // whereDate y no whereBetween, y esta es la parte que más me
            // costó ver. created_at guarda fecha Y hora, así que
            // comparar contra '2026-11-12' lo lee como las 00:00:00 de
            // ese día y deja fuera todo lo que pasó después. whereDate
            // compara solo la parte de fecha, así que ?hasta=hoy sí
            // incluye las de hoy, que es el caso F-8.
            //
            // El nombre de la tabla va escrito porque la relación
            // hasManyThrough trae un JOIN con comercios, que también
            // tiene created_at: sin calificar, MySQL corta con "column
            // is ambiguous".
            ->when($datos['desde'] ?? null,
                fn ($q, $desde) => $q->whereDate('transacciones.created_at', '>=', $desde))
            ->when($datos['hasta'] ?? null,
                fn ($q, $hasta) => $q->whereDate('transacciones.created_at', '<=', $hasta))

            // MT-014. Las dos condiciones se agregan al final y no
            // tocan ninguna de las de arriba, que es lo que pedía la
            // restricción: "nada de lo existente cambia".
            //
            // monto_min usa >= y monto_max usa <=. Parece obvio
            // escrito, y es exactamente el error que la guía avisa:
            // invertirlos devuelve resultados que se ven razonables
            // (una lista de transacciones, con 200) pero son los de
            // enfrente.
            ->when($datos['monto_min'] ?? null,
                fn ($q, $monto) => $q->where('monto', '>=', $monto))
            ->when($datos['monto_max'] ?? null,
                fn ($q, $monto) => $q->where('monto', '<=', $monto))

            ->latest('transacciones.created_at')
            ->get();

        return TransaccionResource::collection($transacciones);
    }

    /**
     * POST /api/transacciones — crea una transacción.
     *
     * Reutiliza el Form Request del CRUD web a propósito: las reglas
     * de un monto o de un nombre de cliente no cambian porque la
     * petición venga de un programa en vez de un formulario. Si algo
     * falla, la ejecución nunca llega a la primera línea del método y
     * Laravel responde 422 con los errores en JSON, siempre que la
     * petición lleve Accept: application/json.
     *
     * El 201 se pone a mano porque el valor por defecto de un Resource
     * es 200, y 200 significa "acá está lo que pediste" mientras que
     * 201 significa "se creó algo nuevo". Para un cliente la
     * diferencia importa: con 201 sabe que hay un recurso que antes
     * no existía, sin tener que deducirlo del cuerpo.
     *
     * El campo estado no se acepta aunque venga en la petición. Hasta
     * la semana pasada eso lo resolvía el only() de abajo, que lo
     * dejaba fuera sin decir nada; desde esta semana lo resuelve la
     * regla 'estado' => ['prohibited'] del Form Request, que responde
     * 422 y explica la regla. El only() se queda igual: es la segunda
     * cerradura de la misma puerta, y si mañana alguien afloja la
     * regla, la columna sigue sin poder llegar desde la petición.
     *
     * Las dos escrituras van dentro de DB::transaction(), y esa es la
     * parte nueva del día. Crear la transacción y registrar su evento
     * de creación son dos INSERT distintos en dos tablas distintas, y
     * entre uno y otro puede fallar cualquier cosa: la base se cae, el
     * disco se llena, el proceso se muere. Sin la transacción, lo que
     * queda guardado es el primer INSERT y nada más: un cobro sin su
     * evento de creación, o sea una fila que la bitácora de la
     * aplicación no puede explicar, con un cliente que recibió un 500
     * y cree que no se guardó nada.
     *
     * DB::transaction() hace que las dos escrituras sean una sola
     * cosa: si el closure termina bien, se confirman las dos (COMMIT);
     * si lanza una excepción, se deshacen las dos (ROLLBACK). No hay
     * estado intermedio que pueda quedar guardado. Es todo o nada, y
     * en una pasarela de pagos "a medias" es peor que "nada", porque
     * nada se puede reintentar y a medias hay que ir a averiguar qué
     * pasó.
     *
     * El throw de prueba del experimento de integridad iba
     * exactamente entre los dos create() de abajo. Con el código del
     * Paso A quedaba la transacción sin evento; con el Paso B, que es
     * este, no quedaba nada.
     *
     * Este método es el único de los cinco que NO necesitó una línea
     * nueva esta semana, y vale la pena entender por qué. La regla de
     * que el comercio tiene que ser del usuario ya estaba escrita
     * desde la Semana 12, dentro de GuardarTransaccionRequest, en el
     * exists con where user_id. Lo que faltaba no era la regla sino
     * el usuario: por la API $this->user() era null y la regla caía a
     * un exists simple. Ahora el token trae identidad en cada
     * petición, así que la misma regla de siempre volvió a tener
     * contra quién comparar, y si Ana intenta crear una transacción
     * en un comercio de Carlos recibe 422 sin que yo haya tocado una
     * sola línea del Form Request.
     */
    public function store(GuardarTransaccionRequest $request): JsonResponse
    {
        $transaccion = DB::transaction(function () use ($request) {
            $transaccion = Transaccion::create($request->only([
                'comercio_id', 'cliente_nombre', 'monto',
            ]));

            // refresh() relee la fila recién insertada.
            //
            // Hace falta porque 'estado' no viaja en la petición: lo
            // pone la base de datos con el default de la migración.
            // create() devuelve el modelo con los campos que YO le
            // pasé, y como ese no era uno de ellos, el objeto en
            // memoria lo tiene en null aunque la fila ya diga
            // 'Iniciada'. Sin esta línea la respuesta 201 sale con
            // "estado": null y el cliente cree que la transacción
            // nació sin estado.
            $transaccion->refresh();

            // El segundo INSERT, en otra tabla. create() desde la
            // relación llena transaccion_id solo.
            $transaccion->eventos()->create([
                'tipo_evento' => 'creacion',
                'detalle' => 'Transacción iniciada desde la API',
            ]);

            return $transaccion;
        });

        return (new TransaccionResource($transaccion))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/transacciones/{transaccion} — una sola transacción.
     *
     * El modelo entra resuelto desde la ruta, y si el id no existe el
     * 404 se lanza antes de ejecutar el método. Con el encabezado
     * Accept: application/json ese 404 llega como JSON y no como la
     * página HTML de error.
     *
     * El route model binding resuelve por id y nada más: encuentra la
     * fila sin preguntar de quién es. Por eso la autorización va en
     * la primera línea, antes de construir el Resource.
     */
    public function show(Transaccion $transaccion): TransaccionResource
    {
        $this->autorizar($transaccion);

        return new TransaccionResource($transaccion);
    }

    /**
     * PUT/PATCH /api/transacciones/{transaccion} — actualiza.
     *
     * apiResource declara esta ruta, así que el método se implementa
     * para no dejar un 501 colgando. Usa el mismo Form Request, por
     * la misma razón que store().
     *
     * Acá hacen falta las DOS verificaciones, y son distintas:
     *
     *   autorizar()    la transacción que se va a modificar es mía
     *   Form Request   el comercio_id que llega en el cuerpo es mío
     *
     * Sin la primera, Ana podría editar una transacción de Carlos.
     * Sin la segunda, Ana podría tomar una transacción suya y
     * mudársela al comercio de Carlos, que es la misma fuga en el
     * otro sentido.
     *
     * El orden en que se ven importa al momento de probar: el Form
     * Request corre ANTES del cuerpo del método, así que un PUT sobre
     * una transacción ajena con un comercio_id también ajeno
     * responde 422 y no 404. Para ver el 404 hay que mandar un
     * comercio_id propio y un id de transacción ajeno, que es lo que
     * hace la petición de la colección.
     */
    public function update(
        GuardarTransaccionRequest $request,
        Transaccion $transaccion
    ): TransaccionResource {
        $this->autorizar($transaccion);

        $transaccion->update($request->validated());

        return new TransaccionResource($transaccion);
    }

    /**
     * DELETE /api/transacciones/{transaccion} — elimina.
     *
     * Devuelve 204: se hizo lo que se pidió y no hay cuerpo que
     * mandar. Devolver la transacción borrada sería raro, porque ya
     * no existe; devolver un mensaje de éxito obligaría al cliente a
     * leer el cuerpo para enterarse de algo que el código ya dice.
     *
     * Un segundo DELETE al mismo id responde 404, porque el route
     * model binding ya no encuentra la fila. Eso es correcto, y es
     * justo lo que la tabla de verificación pide comprobar.
     *
     * Nota de la semana pasada: este destroy no repetía la regla del
     * CRUD web, que se niega a borrar una transacción Completada, y
     * la deuda quedó anotada acá porque la API no tenía a quién
     * rendirle cuentas. Esta semana la primera mitad de esa deuda se
     * paga: ya no se puede borrar lo ajeno, porque autorizar() corta
     * con 404 antes del delete.
     *
     * La segunda mitad sigue abierta a propósito, y es la regla de
     * negocio: por la API, el dueño de una transacción Completada
     * todavía la puede borrar. No es un olvido ni un problema de
     * identidad, es una decisión de diseño que no me toca tomar sola:
     * una pasarela de pagos real no borra un cobro cerrado, lo
     * anula. Queda escrito acá para que se vea que la diferencia
     * entre las dos puertas es conocida y no accidental.
     */
    public function destroy(Transaccion $transaccion): Response
    {
        $this->autorizar($transaccion);

        $transaccion->delete();

        return response()->noContent();
    }
}
