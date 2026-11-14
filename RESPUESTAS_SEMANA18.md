# Respuestas — Semana 18

Guia Practica N.o 20: Arquitectura y buenas practicas — refactorizar hacia una
capa de servicios

Alumno: Hector Alonso Interiano Figueroa
Asignatura: Integracion de Sistemas — CE-ISC019
Ciclo: 02-2026
Herramienta de IA usada: **Claude (Anthropic)**

Rama de trabajo: `refactor/servicios`, como pide la guia, con ocho commits
pequenos y la fusion a `main` al final.

---

## Antes de empezar: lo que este proyecto no tenia

La guia supone que la tabla `transacciones` ya tiene las columnas `metodo` y
`comision`. Este proyecto nunca las tuvo: hasta la Semana 16 una transaccion
era comercio, cliente, monto y estado. Sin esas dos columnas, el Laboratorio 2
no tiene comision que verificar y el Laboratorio 5 no tiene reglas que escribir,
asi que la semana arranca agregandolas en una migracion
(`2026_11_14_000001_add_metodo_y_comision_to_transacciones_table.php`).

Dos decisiones de esa migracion, porque las dos vuelven a aparecer mas adelante:

- **`metodo` es `string` y no un enum de MySQL**, al contrario de `estado`. La
  lista de metodos de pago va a crecer (el reto del Laboratorio 5 agrega
  billetera), y con un enum de base de datos cada metodo nuevo seria un ALTER
  TABLE. `estado`, en cambio, son tres valores que son el negocio mismo.
- **`metodo` tiene `default('tarjeta')` y `comision` es `nullable`.** El default
  es lo que sostiene la promesa de la semana: el cliente web de la Semana 14 y
  la coleccion de Bruno no envian `metodo`, y siguen recibiendo 201. Y el
  nullable es honestidad con los datos viejos: las filas creadas antes de hoy
  no tuvieron comision, y un 0.00 diria "se cobro cero", que no es lo mismo que
  "no se sabe".

Datos sembrados, que son los que usan las capturas:

| # | Comercio | Dueno | Monto | Metodo | Comision | Estado |
|---|---|---|---|---|---|---|
| 1 | Cafe Amanecer | Ana | 45.00 | tarjeta | 1.58 | Completada |
| 2 | Cafe Amanecer | Ana | 12.50 | transferencia | 1.50 | Iniciada |
| 3 | Pupuseria Dona Marta | Ana | 8.00 | tarjeta | 0.28 | Fallida |
| 4 | Libreria La Pagina | Carlos | 23.75 | transferencia | 1.50 | Iniciada |

Las comisiones del seeder no estan escritas a mano: las calcula la misma
calculadora que usa la API, asi no puede pasar que los datos de prueba y el
codigo digan cosas distintas.

---

## Laboratorio 1 — Diagnostico de un controlador gordo

El metodo `update` del enunciado hace siete cosas. Las lineas son las del
fragmento de la guia.

| # | Responsabilidad | Lineas | Capa destino | Por que ahi |
|---|---|---|---|---|
| 1 | Revisar que venga `estado` y que sea uno de los tres valores conocidos | 3 | **Form Request** | Es una pregunta que se contesta mirando el cuerpo de la peticion, sin saber nada del negocio ni consultar la base. Y un Form Request corre antes del metodo, asi que el cuerpo sucio ni entra |
| 2 | Comprobar que la transaccion sea del usuario del token | 4-6 | **Servicio** | Es una regla de pertenencia, no de formato: hay que ir a la base a ver de quien es el comercio. Si vive en el controlador, cada accion nueva tiene que acordarse de repetirla |
| 3 | La regla "solo se puede cambiar una transaccion Iniciada" | 7-9 | **Servicio** | Depende del estado actual de la fila, que el cuerpo de la peticion no trae. Es la regla del negocio, no del dato |
| 4 | Guardar el estado anterior y escribir el nuevo | 10-11 | **Servicio** (los datos, el **Modelo**) | El servicio decide que se escribe y cuando; el modelo sabe como se escribe y que campos son escribibles |
| 5 | Dejar el evento en la bitacora | 12-15 | **Servicio** | Es parte del proceso de cambiar el estado, no un paso aparte: un estado movido sin su evento es un cambio sin historia |
| 6 | Escribir en el log | 16 | **Servicio** | Lo que hay que registrar es el hecho del negocio ("la transaccion 7 paso de Iniciada a Completada"), y quien sabe que eso ocurrio es el servicio |
| 7 | Armar el JSON de la respuesta | 17-19 | **Resource** (y el **controlador** elige el codigo) | Que campos salen y con que nombres es el contrato de la API, y vive en un solo archivo para que no haya dos formas de entregar lo mismo |

### 1. La regla "solo se puede cambiar una transaccion Iniciada", ¿es de validacion de datos o de negocio?

De negocio, y la prueba es sencilla: para contestarla hay que ir a buscar algo
que la peticion no manda. Una regla de validacion de datos se puede resolver con
el cuerpo en la mano, "¿vino el campo?", "¿es un numero?", "¿es uno de estos
tres textos?", y si el cuerpo esta bien la peticion sigue. Esta regla necesita
saber en que estado esta hoy esa transaccion, o sea una consulta a la base y el
conocimiento de que una transaccion cerrada ya movio dinero real. Lo mismo
escrito de otra manera: un cuerpo identico, `{"estado": "Completada"}`, es
perfectamente valido para la transaccion 2 e invalido para la 1, y una regla de
validacion no puede dar dos respuestas distintas al mismo dato.

### 2. ¿Que problema tiene que este metodo no use una transaccion de base de datos si actualiza una tabla y crea un registro en otra?

Que las dos escrituras pueden quedar a medias y nadie se enterara. El `update`
de la linea 11 ya esta confirmado en la base cuando corre el `create` de la
linea 12, asi que si ese segundo INSERT falla (la tabla de eventos tiene una
clave foranea y un campo obligatorio), la transaccion queda Completada sin
ningun evento que diga cuando ni desde donde cambio. Y lo peor es como se ve
desde afuera: el cliente recibe un 500, cree que el cambio no ocurrio y lo
reintenta, cuando en realidad ya se habia aplicado. Es exactamente el problema
que la Semana 15 resolvio en `store` con `DB::transaction()`, y aqui esta de
vuelta en otro metodo: dos escrituras que significan un solo hecho tienen que
confirmarse juntas o deshacerse juntas.

### 3. Si manana el reporte de auditoria pide guardar tambien quien hizo el cambio, ¿cuantos lugares habria que tocar?

Con este diseno, uno por cada lugar donde se mueve un estado, y el problema es
que son mas de los que parece. En este proyecto son dos puertas distintas: el
`moverEstado` del CRUD web de la Semana 11 y el metodo de la API, cada uno con
su propia copia del `eventos()->create(...)`. Habria que acordarse de las dos,
mantener el mismo formato del campo `detalle` en ambas, y confiar en que el
tercero que agregue una tercera puerta tambien se acuerde. El dia que una se
quede atras, el reporte de auditoria queda con filas sin autor y nada avisa.

Con el servicio es un lugar: el `cambiarEstado` del servicio es el unico que
escribe ese evento, y la web y la API lo llaman. Agregar el autor es agregar un
campo en esa linea. Esa es la ganancia del dia y no tiene que ver con tener mas
clases: se trata de que cada cambio futuro toque pocos archivos.

---

## Laboratorio 2 — La red de seguridad (paso 0)

Archivo: `tests/Feature/RegistrarTransaccionTest.php`, mas la fabrica
`database/factories/ComercioFactory.php`, que es nueva.

Siete pruebas, escritas y corridas **antes** de mover una sola linea:

| Prueba | Que fija |
|---|---|
| `registra_transaccion_con_comision_de_tarjeta` | 100 con tarjeta deja 3.50, verificado en el JSON y en la fila |
| `comision_fija_en_transferencia` | 1.50 con 100 y 1.50 con 1000: la comision no depende del monto |
| `comision_de_billetera_es_dos_por_ciento` | 100 con billetera deja 2.00 (esta es del reto y arranco en rojo) |
| `sin_metodo_usa_tarjeta_por_omision` | La promesa de compatibilidad: sin `metodo` se recibe 201 y queda tarjeta |
| `comercio_ajeno_devuelve_422` | El comercio de otro no se puede cobrar, y no se crea nada |
| `datos_invalidos_devuelven_422` | Monto cero y metodo inventado, revisando el campo del error y no solo el codigo |
| `sin_token_devuelve_401` | El 401 llega antes de la validacion: el cuerpo esta perfecto y aun asi no entra |

Lo que estas pruebas **no** miran es el camino interno. A ninguna le importa si
la comision la calcula el controlador, un servicio o tres clases de reglas; por
eso sobreviven al refactor y por eso sirven para medirlo.

### Las pruebas que fallaron ANTES de empezar

La guia avisa que una prueba roja antes del refactor significa un hallazgo. Me
pasaron tres, y las tres resultaron distintas:

| Lo que fallo | Que era en realidad |
|---|---|
| `assertJsonPath('data.monto', 60.0)` esperaba 60.0 y recibio 60 | **Mi prueba estaba mal.** El Resource entrega el monto con `(float)`, y al serializar, un 60.0 se escribe `60` en JSON, asi que vuelve como entero. En JSON no existe la diferencia entre 60 y 60.0 |
| El comercio ajeno respondia 422 donde la guia esperaba 404 | **El proyecto estaba bien y la guia suponia otro diseno.** Ver abajo |
| La comision no se recalculaba al cambiar el monto | **Una regla que faltaba de verdad**, pero nueva, no un comportamiento roto. Quedo marcada aparte |

**El caso del 422 contra el 404 merece su parrafo**, porque es el que decidio
como se adapto toda la guia. En este proyecto el dueno del comercio se comprueba
en el Form Request, con
`Rule::exists('comercios', 'id')->where('user_id', $this->user()->id)`, desde la
Semana 14. Entonces un `comercio_id` ajeno es un campo invalido del cuerpo, y
eso es un 422 con su error de validacion. El 404 de la guia corresponde a un
proyecto donde la comprobacion vive en el servicio y el id llega por la URL.
Cambiarlo a 404 habria sido romper un contrato que la API sostiene desde la
Semana 15, que es justo lo que esta semana prohibe. Asi que ajuste la prueba al
proyecto y no el proyecto a la prueba.

---

## Laboratorio 3 — Refactorizar `store` paso a paso

**Paso 1 · Mapear.** El `store` de este proyecto hacia: validar (ya estaba
afuera, en `GuardarTransaccionRequest`), buscar el comercio del usuario,
calcular la comision con un `if`, abrir la transaccion de base de datos, dos
INSERT, el `refresh()`, el `Log::info` y armar la respuesta 201. De todo eso,
solo lo ultimo es trabajo de controlador.

**Paso 2 y 3 · El servicio.** `app/Services/TransaccionService.php`, con
`registrar(User $usuario, array $datos): Transaccion`. El cuerpo se movio tal
cual, sin cambiarle nada, porque la regla del dia es que el comportamiento no
cambia.

**Paso 4 · Inyectar.** `public function __construct(private TransaccionService
$servicio) {}` en el controlador. Nadie escribe `new TransaccionService()` en
ninguna parte: el contenedor ve el tipo y lo construye.

**Paso 5 · Verificar.** `php artisan test` en verde antes y despues.

### ¿Cuantas lineas tenia tu store antes y cuantas ahora?

Contando el archivo como se lee, de la firma a la llave de cierre: **52 lineas
antes, 10 ahora**. Contando solo el cuerpo, sin la firma, sin comentarios y sin
lineas en blanco: **23 lineas antes y 6 ahora**.

El numero no es el punto, pero lo que queda si: las tres cosas que hace el
metodo nuevo son recibir una peticion ya validada, pedirle el trabajo a quien
sabe hacerlo y decidir como se devuelve el resultado.

### ¿Que pieza del controlador ya no sabe nada de la base de datos?

El controlador entero. Antes aparecian ahi `findOrFail`, `DB::transaction`,
`create` en dos tablas y un `refresh()`; ahora no queda una sola llamada a
Eloquent ni al facade `DB` en los metodos de escritura, y los dos `use` de
`Illuminate\Support\Facades\DB` y `Log` se borraron porque quedaron sin uso. El
controlador conoce dos cosas: el servicio y el Resource. Para los metodos de
lectura (`index` y `filtrar`) la consulta sigue ahi, y eso fue a proposito; la
razon esta en el Laboratorio 4.

---

## Laboratorio 4 — Refactorizar por mi cuenta

Esta parte la resolvi en dos mitades, porque el `update` de la guia y el de este
proyecto no son lo mismo.

### Por que el cambio de estado es una direccion nueva y no un `update` refactorizado

El `update` del enunciado cambia el estado. El `update` de este proyecto no lo
toca: desde la Semana 15, `estado` esta `prohibited` en el cuerpo del PUT, y
mover el estado no se podia hacer por la API de ninguna manera. O sea que el
metodo de la guia corresponde a una capacidad que la API no tenia.

Meterla dentro del PUT habria cambiado el contrato de una direccion que ya tiene
clientes, que es exactamente lo que esta semana no se hace. Asi que entro por su
propia puerta, **`PATCH /api/transacciones/{id}/estado`**, con su propio Form
Request, y el PUT quedo igual. Y el `update` que si existia tambien se
refactorizo, para que las tres escrituras se vean iguales.

### El esqueleto de la guia, contestado

| Pregunta del esqueleto | Como quedo |
|---|---|
| 1. ¿Como compruebas que la transaccion pertenece al usuario? | Un metodo privado `suya()`, que vuelve a buscarla desde el usuario: `$usuario->transacciones()->select('transacciones.*')->where('transacciones.id', ...)->firstOrFail()`. No se confia en el modelo que llego por parametro, porque lo resolvio el route model binding a partir de un numero de la URL, y ese numero lo escribe quien pide |
| 2. ¿Donde va la regla "solo desde Iniciada"? ¿Que devuelves si no se cumple? | En el servicio, y lanza `ValidationException::withMessages(['estado' => ...])`. Laravel la convierte en el mismo 422 con formato de error de validacion que cualquier otro, asi que el cliente no nota la diferencia y el servicio nunca tuvo que saber que es un 422 |
| 3. ¿Necesitas `DB::transaction`? | Si: se escribe en `transacciones` y en `eventos_transaccion`, y un estado movido sin su evento deja un cambio sin historia |
| 4. Log | Afuera del bloque de la transaccion, cuando el cambio ya es cierto. Un log adentro podria afirmar algo que despues se deshace con el ROLLBACK |

### Las condiciones de la guia

| Condicion | Como se cumple |
|---|---|
| El servicio NO recibe `Request` ni devuelve `response()` | Los tres metodos publicos reciben `User` y arreglos, y devuelven `Transaccion` o nada. No hay un solo `Request` en `app/Services/` |
| El controlador queda en 5 lineas o menos | `cambiarEstado` tiene **4** lineas de cuerpo, `update` **4** y `destroy` **2** |
| La validacion de `estado` vive en un Form Request | `app/Http/Requests/CambiarEstadoRequest.php` |
| Una transaccion que no esta Iniciada sigue devolviendo 422 con mensaje en JSON | Prueba `una_transaccion_ya_cerrada_devuelve_422` |
| Una transaccion de otro usuario sigue devolviendo 404 | Pruebas `la_transaccion_de_otro_devuelve_404` y `actualizar_la_de_otro_devuelve_404` |
| Al menos 3 pruebas para `update` ANTES de refactorizar | Seis: `tests/Feature/ActualizarTransaccionTest.php`. Seis pasaron antes y despues; la septima, la de la comision recalculada, es una regla nueva y esta marcada como tal en el archivo |

### Justificacion escrita (para la defensa del bloque A)

La validacion de `estado` quedo en un Form Request porque se contesta con el
cuerpo en la mano y conviene que corte antes de que el metodo arranque. La regla
"solo desde Iniciada" quedo en el servicio porque necesita el estado actual de
la fila, que la peticion no trae, y porque es cierta en la web, en la API y en
un comando de consola. La comprobacion de dueno quedo tambien en el servicio, en
un solo metodo privado, para que las tres escrituras la hagan igual y ninguna
pueda olvidarla. La escritura de la fila mas su evento quedaron juntas dentro de
`DB::transaction()` porque son un solo hecho del negocio. El armado del JSON se
quedo en el Resource y el codigo de estado en el controlador, porque eso es el
contrato de la API y no el proceso. Y las lecturas (`index` y `filtrar`) no se
movieron: son consultas sin ninguna regla de negocio, y pasarlas por un servicio
habria agregado una capa que no tendria nada adentro, que es lo que YAGNI
aconseja no hacer.

---

## Laboratorio 5 — Comisiones abiertas a extension (OCP)

Lo que se reemplazo fue este `if`, que estaba primero en el controlador y
despues en el servicio:

```php
$comision = $datos['metodo'] === 'tarjeta'
    ? round($datos['monto'] * 0.035, 2)
    : 1.50;
```

Funcionaba y no era dificil de leer. Los problemas eran dos: cada metodo de pago
nuevo obligaba a volver a abrir un archivo ya probado, y el `else` se quedaba
con el valor de transferencia por descuido historico, asi que cualquier metodo
inventado cobraba 1.50 en silencio en lugar de quejarse.

Archivos del laboratorio:

| Archivo | Que es |
|---|---|
| `app/Services/Comisiones/ReglaComision.php` | La interfaz. Dos metodos, `aplica()` y `calcular()`, y ninguno de mas: es la I de SOLID |
| `app/Services/Comisiones/ComisionTarjeta.php` | 3.5 % con `round(..., 2)` |
| `app/Services/Comisiones/ComisionTransferencia.php` | 1.50 fijo. Es la clase que explica por que la interfaz recibe el monto aunque aqui no se use: el contrato tiene que servir para las dos formas de cobrar |
| `app/Services/CalculadoraComision.php` | Recorre las reglas, se queda con la primera que dice que si, y lanza `InvalidArgumentException` si nadie se hace cargo |
| `app/Providers/AppServiceProvider.php` | El `singleton` con la lista de reglas |

Que sea la regla la que contesta "¿aplico yo?" es lo que deja a la calculadora
sin un solo `if` de metodos de pago: no sabe que existe la tarjeta, solo sabe
preguntar.

Un detalle de la calculadora que no estaba en la guia: cuando ninguna regla
aplica, lanza una excepcion en vez de devolver 0, 1.50 o null. Llegar ahi
significa que el sistema acepto un metodo de pago para el que nadie sabe cuanto
cobrar, y eso no se arregla devolviendo un numero cualquiera. Un cero silencioso
seria un cobro sin comision que nadie notaria hasta cerrar el mes.

### Experimento · ¿Laravel sabe construir la calculadora?

Inyecte `CalculadoraComision` en el constructor del servicio y corri las pruebas
**sin** hacer el paso 4. Resultado: cinco de las seis pruebas en rojo, con un
500 y esta excepcion:

```
Illuminate\Contracts\Container\BindingResolutionException:
Unresolvable dependency resolving [Parameter #0 [ <required> array $reglas ]]
in class App\Services\CalculadoraComision
```

### ¿Por que Laravel puede inyectar `TransaccionService` automaticamente pero no `CalculadoraComision`?

Porque lo que el contenedor sabe hacer es resolver **tipos de clase**, y nada
mas. Cuando ve `TransaccionService $servicio` en el constructor del controlador,
el tipo le dice exactamente que objeto construir; va a esa clase, mira su propio
constructor, repite el procedimiento con cada parametro y asi arma toda la
cadena sin que nadie escriba un `new`. Por eso el proyecto habia llegado a la
Semana 18 sin un solo enlace propio en el contenedor.

`CalculadoraComision` rompe la cadena porque su constructor no pide una clase,
pide un `array`. Y de un `array` no hay nada que deducir: "arreglo" no dice
**cual** arreglo ni con que adentro, asi que el contenedor no tiene de donde
sacarlo y falla. El `singleton` del paso 4 es justamente la receta que le
faltaba: cuando alguien pida una `CalculadoraComision`, armala con estas reglas.

Vale la pena notar como se ve el error: la prueba dice "esperaba 201 y recibio
500". El codigo compila, el archivo esta bien escrito y el fallo solo aparece al
ejecutar, igual que el problema del orden de las rutas de la Semana 16. Hay
errores que no se encuentran leyendo.

### Reto · Billetera digital (2 %)

| Pregunta | Respuesta |
|---|---|
| ¿Que archivos creaste? | Uno: `app/Services/Comisiones/ComisionBilletera.php`. Y despues, por lo que cuento abajo, un segundo: `app/Rules/MetodoDePagoSoportado.php` |
| ¿Que archivos modificaste y por que era inevitable? | **`AppServiceProvider.php`**, una linea, `new ComisionBilletera()`. Era inevitable: alguien tiene que saber que reglas existen, y ese alguien es el contenedor; si la regla no se registra, nadie la va a encontrar sola. **`GuardarTransaccionRequest.php`**: tambien hubo que abrirlo, y resulto que **no** era inevitable. Ver abajo |
| ¿Que prueba nueva escribiste? | `test_comision_de_billetera_es_dos_por_ciento`, en `RegistrarTransaccionTest`. La escribi primero y fallo con 422 por `metodo`, que era la respuesta correcta en ese momento: 'billetera' no era un metodo que esta API conociera |

`ComisionTarjeta`, `ComisionTransferencia` y `CalculadoraComision` quedaron sin
tocar, que era la condicion del reto. Y se parece mucho a `ComisionTarjeta`, lo
cual es a proposito y no es repeticion: son dos reglas distintas que hoy
coinciden en la forma, y el dia que a la billetera le pongan un minimo, se
cambia ahi y la tarjeta no se entera.

### Lo que el reto dejo al descubierto

Agregar la billetera tendria que haber sido una clase nueva y una linea. Fueron
dos archivos, porque en `GuardarTransaccionRequest` vivia un
`Rule::in(['tarjeta', 'transferencia'])` con la lista de metodos escrita a mano.
O sea que la lista estaba en dos lugares y el principio abierto/cerrado se
cumplia solo del lado del calculo.

Lo peor no era tocar dos archivos, sino lo que pasa si se olvida uno, y los dos
olvidos fallan distinto:

- Regla nueva sin tocar la validacion: el metodo existe para la calculadora pero
  la API lo rechaza con 422. Molesto y visible.
- Validacion nueva sin la regla: la API acepta el metodo, el calculo no
  encuentra regla y lanza `InvalidArgumentException`, que es un **500**. Un
  cobro que revienta en produccion porque dos listas no coincidian.

Lo cerre con `app/Rules/MetodoDePagoSoportado.php`, una regla de validacion que
le pregunta a la calculadora (`soporta()`) en vez de llevar su propia lista.
Ahora la lista esta en un solo lugar, el contenedor, y agregar un metodo de pago
vuelve a ser una clase nueva y una linea.

El precio esta a la vista y lo acepto: el mensaje de error ya no puede nombrar
los metodos validos, porque la calculadora sabe contestar "¿soportas esto?" pero
no "¿que soportas?". Decia "El metodo de pago debe ser tarjeta o transferencia"
y ahora dice "Ese metodo de pago no esta disponible". Preferible a dos listas
que pueden discrepar, y quedo anotado en `CAMBIOS.md`.

---

## Extra — Adios a los textos magicos con un Enum

`app/Enums/EstadoTransaccion.php`, con el cast en el modelo:
`'estado' => EstadoTransaccion::class`.

Antes de este archivo, 'Iniciada', 'Completada' y 'Fallida' estaban escritos a
mano en nueve lugares. Esta es la busqueda completa:

| Archivo | Linea | Antes | Despues |
|---|---|---|---|
| `app/Models/Transaccion.php` | casts | (no habia cast) | `'estado' => EstadoTransaccion::class` |
| `app/Http/Resources/TransaccionResource.php` | 72 | `'estado' => $this->estado` | `'estado' => $this->estado->value` |
| `app/Services/TransaccionService.php` | 181 | `if ($transaccion->estado !== 'Iniciada')` | `if ($transaccion->estado !== EstadoTransaccion::Iniciada)` |
| `app/Services/TransaccionService.php` | 187 | `$anterior = $transaccion->estado;` | `$anterior = $transaccion->estado->value;` |
| `app/Http/Requests/CambiarEstadoRequest.php` | 46 | `Rule::in(['Completada', 'Fallida'])` | `Rule::enum(EstadoTransaccion::class)->only(EstadoTransaccion::deCierre())` |
| `app/Http/Controllers/Api/TransaccionApiController.php` | 145 y 210 | `Rule::in(['Iniciada', 'Completada', 'Fallida'])` | `Rule::enum(EstadoTransaccion::class)` |
| `app/Http/Controllers/TransaccionController.php` | 163 | `=== 'Completada'` | `=== EstadoTransaccion::Completada` |
| `app/Http/Controllers/TransaccionController.php` | 199-209 | `$validos = [...]` y el mapa `$transiciones = [...]` | `EstadoTransaccion::tryFrom($estado)` y `$transaccion->estado->puedeMoverseA($destino)` |
| `resources/views/components/badge-estado.blade.php` | 7 y 9 | `$estado === 'Completada'` | `$estado === \App\Enums\EstadoTransaccion::Completada` |
| `resources/views/comercios/show.blade.php` | 45 y 59 | `$transaccion->estado === 'Iniciada'` | `=== \App\Enums\EstadoTransaccion::Iniciada` |
| `database/seeders/ComercioSeeder.php` | 4 filas | `'estado' => 'Completada'` | `'estado' => EstadoTransaccion::Completada` |

Tres lugares se dejaron **a proposito** con el texto, y conviene decir por que:

| Lugar | Por que se queda |
|---|---|
| `database/migrations/..._create_transacciones_table.php` | Es el `enum` de la columna en MySQL. Una migracion ya corrida no se edita, y ademas ahi el texto es el dato: la base de datos no sabe que es un enum de PHP |
| `public/cliente.html` | Es JavaScript del navegador armando `?estado=Completada`. Del otro lado del cable no hay PHP; ahi el estado es, y tiene que ser, texto |
| `resources/views/practica/formulario_demo.blade.php` | Es el `<option value="Iniciada">` de un formulario de practica. Lo que viaja en un POST es texto |

### Dos cosas que el enum trajo de regalo

**El mapa de transiciones se mudo al enum.** Estaba en el controlador web, que
es un lugar raro para una regla que no depende de quien pregunte: desde una
Completada no se puede ir a ninguna parte, y eso es cierto en la web, en la API
y en un comando. Ahora es `siguientes()`, un `match` sobre el propio estado. Y
`match` sobre un enum es exhaustivo: si manana se agrega un cuarto estado y
nadie dice a donde puede ir, PHP lanza `UnhandledMatchError` en lugar de
contestar en silencio que no puede ir a ningun lado.

**Aparecio un agujero en las pruebas.** El cast cambia lo que devuelve
`$transaccion->estado` en todo el proyecto, no solo en la API, y el CRUD web de
las Semanas 9 a 12 comparaba ese valor contra textos. Esas comparaciones pasan a
ser falsas sin avisar: la pagina carga igual, responde 200 y se queda sin los
botones de mover estado, porque comparar un enum con una cadena no es un error
de PHP, es simplemente falso. Ninguna de las 33 pruebas que tenia lo habria
notado, porque todas hablan de la API. Lo comprobe volviendo a poner una
comparacion vieja a proposito, y despues escribi
`tests/Feature/PanelWebTest.php`, que es la prueba que debi tener antes de
empezar.

### Experimento en tinker

```
> App\Models\Transaccion::first()->estado
= enum(App\Enums\EstadoTransaccion::Completada)

> get_debug_type(...)             App\Enums\EstadoTransaccion
> ...->value                      Completada
> ...->name                       Completada
> ... === 'Completada'            false
> ... === EstadoTransaccion::Completada   true
```

**¿Que tipo de valor devuelve ahora?** Un objeto, el caso del enum, no la cadena
'Completada'. La linea que lo dice todo es la penultima: `=== 'Completada'`
devuelve **false**, y ahi esta el riesgo del cast.

**¿Que cambia en el Resource?** Hay que escribir `$this->estado->value`. El
detalle curioso es que el JSON sale igual con las dos formas, porque un enum con
respaldo de cadena se serializa solo a su valor: `json_encode` del Resource da
`"estado":"Completada"` en ambos casos. O sea que esta vez el `->value` no
arregla nada roto. Lo escribi igual, por dos razones: un contrato de API
conviene que sea explicito sobre lo que viaja, y donde el `->value` si es
obligatorio es en la interpolacion de cadenas, como el `detalle` del evento
(`"{$anterior} -> {$nuevo}"`), que con el enum directo lanza un Error.

---

## Extra — Refactorizar con ayuda de IA (bajo control)

Prompt usado, el de la guia:

```
Refactoriza este metodo moviendo la logica de negocio a una clase de servicio.
NO cambies el comportamiento, las reglas de validacion, los nombres de campos
ni los codigos de estado. No uses metodos estaticos. El servicio no debe
recibir Request. Muestra solo los archivos modificados.
```

Y le pegue el metodo `store` tal como estaba, **sin el `.env` ni ningun token**,
como pide la Semana 16.

### Revision de la respuesta

| Pregunta | Si / No | Detalle |
|---|---|---|
| ¿Cambio algun nombre de campo o ruta? | **No** | Los campos quedaron `comercio_id`, `cliente_nombre`, `monto`, `metodo`; la ruta, igual |
| ¿Agrego o quito validaciones? | **No** | Propuso dejar el Form Request intacto, que era lo correcto |
| ¿Uso metodos estaticos o recibe `Request` en el servicio? | **No** | La firma quedo `registrar(User $usuario, array $datos)` |
| ¿Invento clases o metodos que no existen en Laravel? | **No** | `DB::transaction`, `findOrFail` y `Log::info` existen y se usaron bien |
| ¿Las pruebas siguen en verde con su codigo? | **Si** | Las seis del Laboratorio 2 |

Tres cosas que si tuve que corregir o decidir yo, y que ninguna prueba habria
atrapado:

1. Propuso `$comercio = Comercio::findOrFail($datos['comercio_id'])`. Eso busca
   en toda la tabla y no desde el usuario. En este proyecto no abre un agujero,
   porque el Form Request ya rechazo el comercio ajeno, pero deja un servicio
   que no se puede llamar desde ningun otro lado sin riesgo. Quedo
   `$usuario->comercios()->findOrFail(...)`.
2. Movio el `Log::info` adentro del `DB::transaction()`. Desde ahi puede afirmar
   un registro que despues se deshace con el ROLLBACK. Quedo afuera.
3. No dijo nada del `refresh()`, y sin esa linea la respuesta 201 sale con
   `"estado": null`, porque el estado lo pone el default de la base y `create()`
   devuelve el modelo con los campos que yo le pase. Es la correccion de la
   Semana 13 y la IA no tenia como saberlo: no estaba en el fragmento que le
   pegue.

El patron de los tres es el mismo y vale anotarlo: la IA movio el codigo bien y
falto justamente en lo que depende de la historia de este proyecto. El metodo de
la Semana 16 sigue aplicando, y el paso que lo resolvio fue el 1, leer.

---

## Lista de verificacion y evidencia para el Laboratorio III

| Punto de la guia | Estado |
|---|---|
| Al menos 5 pruebas de `store`, que pasan antes y despues | **7**, en `RegistrarTransaccionTest` |
| El controlador `store` tiene una sola responsabilidad: coordinar | Si: 7 lineas de cuerpo, sin Eloquent ni `DB` |
| `TransaccionService` no recibe `Request` ni devuelve `response()` | Si: `User` y arreglos entran, `Transaccion` sale |
| Refactorice `update` por mi cuenta y escribi su justificacion | Si, mas el `PATCH /estado` nuevo y 13 pruebas entre los dos archivos |
| Las comisiones usan reglas (OCP) y resolvi el reto de la billetera | Si, y de paso cerre la lista duplicada del Form Request |
| Registre la refactorizacion en `CAMBIOS.md` (seccion «Cambiado») | Si, entrada del 2026-11-19 |
| Commits pequenos con mensajes claros (`refactor: …`) | Ocho, en la rama `refactor/servicios` |
| Quien defienda el bloque A puede explicar por que cada pieza quedo donde quedo | La justificacion del Laboratorio 4 esta escrita para eso |

Suite completa: **35 passed (100 assertions)**.

| Archivo de pruebas | Pruebas | De que semana |
|---|---|---|
| `CrearTransaccionTest.php` | 11 | Semana 15, casos limite |
| `RegistrarTransaccionTest.php` | 7 | Semana 18, Laboratorio 2 |
| `ActualizarTransaccionTest.php` | 7 | Semana 18, Laboratorio 4 |
| `CambiarEstadoTransaccionTest.php` | 6 | Semana 18, Laboratorio 4 |
| `PanelWebTest.php` | 2 | Semana 18, el agujero que dejo ver el enum |
| `ExampleTest.php` (Feature y Unit) | 2 | Semanas anteriores |

---

## Declaracion

Declaro que revise, entendi y probe todo el codigo generado con IA incluido en
mi proyecto, que rechace los cambios no solicitados y que puedo explicar cada
cambio realizado.

Firma: ______________________________
