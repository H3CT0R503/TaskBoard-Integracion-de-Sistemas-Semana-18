# Respuestas y ficha de uso critico de IA — Semana 16

Guia Practica N.o 17: La IA como asistente — alcances, limites y revision critica (jueves)

Alumno: Hector Alonso Interiano Figueroa
Asignatura: Integracion de Sistemas — CE-ISC019
Ciclo: 02-2026
Herramienta de IA usada: **Claude (Anthropic)**

Datos sembrados, que son los que usan las pruebas:

| Comercio | Dueño | Transacciones |
|---|---|---|
| 1 Cafe Amanecer | Ana | 1 Completada, 2 Iniciada |
| 2 Pupuseria Doña Marta | Ana | 3 Fallida |
| 3 Ferreteria El Tornillo | Carlos | — |
| 4 Libreria La Pagina | Carlos | 4 Iniciada |

---

## Laboratorio 1 — Reflexion sobre el caso analizado

Los seis problemas del filtro visto en clase: ① ruta sin proteger, ② orden de
las rutas, ③ datos de todos los usuarios, ④ inyeccion SQL, ⑤ fechas sin validar
y ⑥ sin Resource.

**1. ¿Cuales habria encontrado leyendo, antes de la clase?**

Tres, y los tres por lo mismo: porque ya los habia tenido que arreglar antes en
este proyecto. El ④ (inyeccion SQL) salta a la vista, porque ver una cadena
concatenada dentro de una consulta es exactamente lo que la Semana 15 me enseño
a buscar. El ③ (sin filtro por usuario) lo habria visto porque desde la Semana
14 cualquier consulta que arranque en `Modelo::query()` y no en
`$request->user()->...` me suena mal de entrada. Y el ⑥ (sin Resource) porque el
contrato de la Semana 13 dice que la respuesta se arma en el Resource, y un
`response()->json($coleccion)` lo rompe de frente.

Los otros tres no los habria visto, y por razones distintas entre si.

**2. ¿Cuales NO se descubren leyendo y solo aparecen al ejecutar o probar?**

El ② y el ⑤ "hasta no incluye el dia".

El ② (orden de las rutas) es invisible leyendo porque **las dos lineas son
correctas por separado**. Ninguna tiene un error de sintaxis ni de logica; lo que
esta mal es la relacion entre ellas, y esa relacion no vive en el archivo sino en
como Laravel lo recorre en tiempo de ejecucion. Leyendo se ven dos rutas; solo
pidiendo `/transacciones/filtrar` se descubre que una se come a la otra.

El ⑤ en su version de fechas pasa algo parecido: `whereBetween` con dos fechas
se lee perfectamente razonable. El error aparece cuando uno manda `hasta` = hoy y
cuenta cuantas filas volvieron. Es un problema de **datos**, no de codigo, y los
problemas de datos no se leen, se cuentan.

El ① es un caso intermedio: se ve leyendo, pero solo si uno mira el archivo
completo. En el fragmento que entrega la IA la ruta esta sola y parece bien; hay
que abrir `routes/api.php` y fijarse DONDE la pegaste. Por eso lo pongo del lado
de "se descubre ejecutando": un `route:list` lo muestra en un segundo y una
lectura distraida no.

**3. ¿Por que el problema ② no produce ningun error al escribir el codigo?**

Porque para PHP y para Laravel no hay nada que reclamar. Las dos rutas se
registran bien, el archivo compila, `route:list` las lista a las dos y el metodo
`filtrar()` existe. El conflicto es de **precedencia**, no de sintaxis: Laravel
recorre las rutas en orden y se queda con la primera que coincide, y
`/transacciones/{transaccion}` coincide con `/transacciones/filtrar` porque el
comodin acepta cualquier texto, incluida la palabra "filtrar".

Y el sintoma es peor que un error: no revienta, responde **404**. Un 404 se lee
como "esa direccion no existe", asi que el primer instinto es revisar la URL o
pensar que la ruta no se registro, cuando en realidad se registro perfecto y
el problema esta tres lineas mas arriba.

**4. Ubicacion en el semaforo**

| Problema | Semaforo |
|---|---|
| ① Ruta sin proteger | **Rojo** — es autenticacion |
| ② Orden de las rutas | **Amarillo** — es logica de rutas, se revisa a fondo pero no decide quien entra |
| ③ Datos de todos los usuarios | **Rojo** — datos de clientes |
| ④ Inyeccion SQL | **Rojo** — consulta a la base que ademas abre la puerta a leer todo |
| ⑤ Fechas sin validar | **Amarillo** — es validacion |
| ⑥ Sin Resource | **Amarillo** — es contrato de la respuesta |

**Justifico el ③, que es el que mas me interesa.** Podria discutirse que filtrar
por usuario es "logica de controlador", o sea amarillo. Lo pongo en rojo porque
la consecuencia no es que el codigo funcione mal: es que **funciona
perfectamente y entrega datos de otras personas**. Responde 200, con la
estructura correcta y los campos correctos, igual que la version buena. La unica
diferencia es de quien son las filas, y eso no lo detecta ninguna prueba que
solo mire el codigo de estado. Un problema que se ve igual que el exito no se
puede dejar en manos de nadie mas.

---

## Laboratorio 2 — Nivel 1: revisar el fragmento del resumen de cobros

Pedido original del estudiante: *"Crea un endpoint en Laravel que devuelva el
resumen de cobros de un comercio: el total cobrado y cuantas transacciones hay
por estado."*

Encontre **ocho** problemas (la guia pedia al menos seis).

| # | Linea o parte | Problema | Paso que lo detecta | Riesgo | Correccion propuesta |
|---|---|---|---|---|---|
| 1 | `DB::select("... WHERE comercio_id = $id")` | **Inyeccion SQL.** El `$id` viene de la URL y se pega dentro de la consulta. Con `/comercios/1 OR 1=1/resumen` la condicion se vuelve siempre verdadera | 1 · Leer (y confirmado en el experimento de tinker) | **Critico.** Suma los cobros de todos los comercios del sistema, y la misma puerta sirve para leer otras tablas | `$comercio->transacciones()->sum('monto')` con Eloquent, que manda el dato por separado |
| 2 | `Comercio::find($id)` | **Sin verificar el dueño.** Cualquiera con token puede pedir el resumen de un comercio ajeno: el `id` viene de la URL y nadie compara contra el usuario | 3 · Seguridad / 5 · Probar con dos identidades | **Critico.** Ana ve cuanto factura el negocio de Carlos | `$request->user()->comercios()->findOrFail($id)` |
| 3 | `SUM(monto)` sin filtrar por estado | **El "total cobrado" esta mal calculado.** Suma las Iniciadas y las Fallidas junto con las Completadas, o sea plata que nunca entro | 1 · Leer, pero solo si se conoce el negocio | **Alto.** El comercio cree que cobro mas de lo que cobro. Es un error que nadie nota porque el numero "se ve bien" | `->where('estado', 'Completada')->sum('monto')` |
| 4 | `Comercio::find($id)` sin `OrFail` | **Un id inexistente devuelve null**, y la linea siguiente le pide `->transacciones` a null: *Attempt to read property on null*, o sea **500** | 4 · Ejecutar | **Medio.** Un 500 echa la culpa al servidor cuando el dato lo mando el cliente | `findOrFail()`, que lanza el 404 que corresponde |
| 5 | `'comercio' => $comercio` | **Devuelve el modelo crudo.** Publica `user_id`, `telefono`, `created_at` y `updated_at`, que la Semana 13 saco a proposito de la respuesta | 1 · Leer / 3 · Seguridad | **Medio.** `user_id` permite agrupar comercios por dueño desde afuera | `new ComercioResource($comercio)` |
| 6 | `foreach ($comercio->transacciones as $t)` | **Cuenta en PHP lo que la base puede contar sola.** Trae todas las filas a memoria para incrementar un contador. Con un comercio de 50 000 cobros, carga 50 000 objetos para devolver tres numeros | 5 · Probar (rendimiento) | **Medio.** No falla con los datos del seeder: falla en produccion | `->selectRaw('estado, count(*) as n')->groupBy('estado')` o `->groupBy('estado')->map->count()` sobre la coleccion |
| 7 | `$total` cuando no hay transacciones | **Devuelve `null` en vez de `0`.** `SUM()` sobre cero filas es NULL en SQL, y el cliente recibe `"total": null` | 5 · Probar (caso limite: comercio sin cobros) | **Bajo.** Rompe a cualquier cliente que intente sumar o formatear ese valor | `(float) ($total ?? 0)`, o `sum()` de Eloquent, que ya devuelve 0 |
| 8 | Las dos consultas no coinciden entre si | **El total y el conteo miran conjuntos distintos.** Una usa SQL crudo con `comercio_id`, la otra la relacion de Eloquent. Si mañana la relacion agrega una condicion, los dos numeros dejan de cuadrar sin que nadie lo note | 1 · Leer | **Bajo**, pero es deuda segura | Que las dos salgan de la misma consulta base |

**Para pensar: ¿cual seria el mas dificil de detectar en una revision rapida, y
que prueba en Bruno lo habria puesto en evidencia?**

El **#3**, el del total mal calculado. Todos los demas se delatan de alguna
forma: el SQL crudo se ve, el 500 revienta, el modelo crudo trae campos de mas
que saltan en la respuesta. El #3 devuelve **200, con la estructura correcta y un
numero perfectamente creible**. Nadie sabe de memoria cuanto deberia dar la suma
de un comercio, asi que el numero equivocado pasa igual que el correcto.

La prueba que lo habria puesto en evidencia no es una que mire el codigo de
estado, sino una que **afirme el valor**: pedir el resumen del comercio 1 y
comprobar que el total es `45`, que es lo unico Completado del seeder, y no `57.5`
que es la suma de las tres transacciones. En Bruno:

```js
test("el total cuenta solo lo Completado", function () {
  expect(res.getBody().total).to.equal(45);
});
```

Es la misma leccion del error D de la Semana 14 y del E-02 de la Semana 15: los
problemas que no cambian el codigo de estado solo los atrapa una prueba que
afirme **cuanto** y **de quien**, no solo que respondio.

---

## Laboratorio 3 — Nivel 2: pedido corto contra pedido completo

Le hice los dos pedidos a **Claude**, tal como estan escritos en la guia.

**Con el pedido corto** (*"Crea un endpoint en Laravel para filtrar
transacciones por estado y rango de fechas"*) la respuesta fue un metodo que
funciona: toma `estado`, `desde` y `hasta` de la query, arma la consulta con
`when()` y devuelve el resultado. Codigo limpio, con `whereBetween` para las
fechas y sin SQL crudo. Pero no sabia nada de este proyecto, asi que no filtro
por usuario, devolvio `response()->json($query->get())`, puso la ruta al final
del archivo sin decir nada de `auth:sanctum` ni del orden, y no valido los
parametros.

**Con el pedido completo** la diferencia fue grande y se nota sobre todo en lo
que yo le di: filtro por `$request->user()`, `TransaccionResource::collection()`,
la ruta dentro del grupo y antes del `apiResource`, y validacion con `Rule::in`.

| Problema | ¿Con el pedido corto? | ¿Con el completo? |
|---|---|---|
| ① Fuera de `auth:sanctum` | **Si.** La ruta quedo suelta al final del archivo | No. Se lo dije en las restricciones |
| ② Orden de las rutas | **Si**, y ni lo menciono | No. Tambien se lo dije |
| ③ Sin filtro por usuario | **Si.** Arranco en `Transaccion::query()` | No. Uso `$request->user()->transacciones()` |
| ④ SQL crudo | No, uso Eloquent en los dos casos | No |
| ⑤ Fechas sin validar / "hasta" excluye el dia | **Si las dos veces.** Sin validar en el corto; y aunque en el completo valido, uso `whereBetween`, que deja fuera las de hoy | **Parcialmente si** |
| ⑥ Sin Resource | **Si.** `response()->json(...)` | No |
| **Otro problema nuevo** | En los dos: ninguna version puso `after_or_equal:desde`, asi que un rango al reves respondia 200 con lista vacia | **Si, en los dos** |

**Conclusion: ¿el pedido completo elimino todos los problemas?**

No, y eso es lo mas util que saque del laboratorio. Elimino **cinco de seis**, y
los cinco que elimino son exactamente los que yo le di escritos en las
restricciones. Lo que le pedi, lo hizo. Lo que no se me ocurrio pedirle, no
aparecio.

Quedaron dos cosas, y las dos son del mismo tipo. La primera es el
`whereBetween` contra `hasta`: no es un descuido, es una decision razonable que
solo esta mal **en este dominio**, porque `created_at` guarda hora y los cobros
de hoy quedan afuera. La segunda es el rango invertido: `desde` y `hasta` son
dos fechas validas por separado, y que una no pueda ser menor que la otra es una
regla del negocio que no esta escrita en ningun lado del codigo.

O sea que el pedido completo **no reemplaza la revision, la mueve de lugar**.
Con el pedido corto hay que revisar todo, incluida la seguridad. Con el completo
ya no hay que revisar lo que uno especifico, pero queda lo que uno no sabia que
habia que especificar, que es justo lo que uno no puede poner en un pedido. Y
ahi esta el limite real: la IA puede saber mas Laravel que yo, pero no puede
saber que en una pasarela de pagos un rango al reves es un error del cliente y
no una lista vacia. Eso no es conocimiento tecnico, es conocimiento del negocio,
y es lo unico que no se delega.

---

## Laboratorio 4 — Nivel 3: integrar y probar el filtro

La ruta quedo dentro de `auth:sanctum` y **antes** del `apiResource`:

```php
Route::get('/transacciones/filtrar', [TransaccionApiController::class, 'filtrar']);

Route::apiResource('transacciones', TransaccionApiController::class)
    ->parameters(['transacciones' => 'transaccion']);
```

Comprobado con `php artisan route:list --path=api/transacciones`:

```text
GET|HEAD   api/transacciones                 transacciones.index
POST       api/transacciones                 transacciones.store
GET|HEAD   api/transacciones/filtrar         TransaccionApiController@filtrar
GET|HEAD   api/transacciones/{transaccion}   transacciones.show
```

`filtrar` aparece **arriba** de `{transaccion}`, que es lo que importa.

### Los ocho casos en Bruno (carpeta `4. Filtro`)

| # | Identidad | Peticion | Esperado | Obtenido |
|---|---|---|---|---|
| F-1 | Ninguna | `/transacciones/filtrar` | 401 | **401** `{"message":"Unauthenticated."}` |
| F-2 | Ana | `?estado=Completada` | 200 · solo las de Ana | **200** · 1 transaccion, del comercio 1 |
| F-3 | Carlos | `?estado=Completada` | 200 · solo las de Carlos | **200** · 0 transacciones (Carlos no tiene Completadas) |
| F-4 | Ana | `?estado=Pagada` | 422 | **422** "El estado debe ser Iniciada, Completada o Fallida." |
| F-5 | Ana | `?estado=x' OR '1'='1` | 422 | **422**, mismo mensaje |
| F-6 | Ana | `?desde=hola` | 422 | **422** "La fecha desde no tiene un formato valido." |
| F-7 | Ana | `?desde=2026-11-10&hasta=2026-11-01` | 422 | **422** "La fecha hasta no puede ser anterior a desde." |
| F-8 | Ana | `?hasta=`(hoy) | 200 · incluye las de hoy | **200** · 3 transacciones |
| F-9 | Ana | sin parametros | 200 · todas las suyas | **200** · 3 transacciones (caso propio) |

Sobre **F-3**, que a primera vista parece fallado: Carlos no tiene ninguna
transaccion Completada en el seeder, asi que su lista viene vacia. Un 200 con
`data: []` significa "la consulta se entendio y no hay coincidencias", que es
distinto de un 404. Y puesto al lado de F-2 es la prueba del aislamiento: misma
URL, mismo parametro, otro token, otra respuesta.

Agregue **F-9** por mi cuenta porque los tres parametros son opcionales. Si
alguien le pusiera `required` a `estado` "para que no falle", este caso se caeria
y el endpoint dejaria de servir para listar.

### Regresion

Corri tambien la carpeta de casos limite de la Semana 15 con el filtro ya
integrado: **todo sigue en verde**. Era lo esperado, porque el filtro solo agrega
un metodo y una ruta nueva, pero el orden de rutas es exactamente el tipo de
cambio que puede romper algo que funcionaba sin tocarlo.

- [x] `route:list` muestra la ruta antes de `{transaccion}` y dentro de `auth:sanctum`.
- [x] Los 8 casos de la tabla responden lo esperado (y el noveno propio tambien).
- [x] La carpeta de casos limite de la Semana 15 sigue en verde.

---

## Experimento — Ver la inyeccion SQL con mis propios ojos

Corrido en tinker, con `$ataque = "x' OR '1'='1"`:

| Consulta | `count()` | ¿Por que? |
|---|---|---|
| `whereRaw("estado = '" . $ataque . "'")` | **4** | Devolvio **toda la tabla**. El texto se concateno dentro del SQL y el `OR '1'='1'` quedo como parte de la condicion |
| `where('estado', $ataque)` | **0** | Busco literalmente un estado llamado `x' OR '1'='1`, que no existe. Correcto |

**¿Que muestra `toSql()`?**

```sql
-- whereRaw
select * from `transacciones` where estado = 'x' OR '1'='1'

-- where
select * from `transacciones` where `estado` = ?
```

La diferencia esta a la vista. En la primera, el texto del atacante **ya es
parte de la consulta**: las comillas que el mando cerraron la cadena `'x'` y lo
que seguia (`OR '1'='1'`) dejo de ser un dato y paso a ser una condicion. El
motor recibe una sola frase donde no hay forma de distinguir lo que escribio el
programador de lo que escribio el atacante, y hace lo que esa frase dice:
`'1'='1'` siempre es verdadero, asi que la condicion entera siempre se cumple y
devuelve las cuatro filas.

En la segunda no hay texto: hay un **hueco**, el `?`. La consulta viaja al motor
con esa forma y el valor viaja aparte, en otro paquete. El motor arma el plan de
ejecucion *antes* de recibir el dato, asi que para cuando llega el
`x' OR '1'='1` ya no hay nada que interpretar: ese hueco solo admite un valor
para comparar, y lo compara entero, comillas incluidas. Por eso devuelve 0: no
existe ninguna transaccion cuyo estado sea literalmente ese texto raro.

Dicho corto: **`whereRaw` mezcla instrucciones con datos y `where()` los
mantiene separados**. Y lo que me quedo es que no es que Eloquent "limpie" el
texto ni le quite las comillas — no lo toca. Lo que hace es no darle nunca la
oportunidad de ser codigo.

---

## Extra — La IA como revisora

Le pase a Claude el metodo `filtrar()` ya corregido, con el pedido de la guia.

| Sugerencia de la IA | Decision | Justificacion |
|---|---|---|
| Cambiar `whereDate()` por `whereBetween()` "porque usa el indice y es mas rapido" | **Rechazada** | Tiene razon en el rendimiento: `whereDate` aplica una funcion sobre la columna y por eso el indice de `created_at` no se usa. Pero `whereBetween` con `'2026-11-14'` lo lee como las 00:00:00 y deja afuera todos los cobros de hoy, que es justo el caso F-8. Si algun dia el volumen lo justifica, la forma correcta no es `whereBetween` a secas sino `< hasta + 1 dia`. Por ahora prefiero correcto y lento a rapido y equivocado |
| Agregar paginacion con `->paginate(15)` en vez de `->get()` | **Para evaluar** | Es una buena idea y tarde o temprano hace falta: hoy el seeder tiene 4 filas, pero un comercio real puede tener miles y `get()` las trae todas a memoria. No la aplico ahora porque **cambia el contrato**: la respuesta pasaria a traer `links` y `meta`, y eso rompe a cualquier cliente que ya consuma el endpoint, incluido `cliente.html`. Es un cambio que se decide y se avisa, no que se cuela en una revision |
| Quitar la validacion de `estado` "porque Eloquent ya protege contra inyeccion" | **Rechazada** | Es cierto que `where()` protege contra inyeccion, pero la validacion no esta ahi solo por eso. Sin `Rule::in`, un `?estado=Pagada` responderia 200 con una lista vacia, y el cliente entenderia "no hay cobros pagados" cuando la verdad es "ese estado no existe". Es la pista que da la guia, y es el tipo de sugerencia que suena razonable y quita una defensa |
| Mover la lista de estados a una constante o un enum del modelo | **Aceptada** (anotada como mejora) | Esta bien visto: los tres estados estan hoy repetidos en la migracion, en `moverEstado()` y ahora en esta validacion. Una sola fuente evitaria que se desincronicen. No la aplique en esta entrega para no tocar codigo de semanas anteriores en una practica sobre revision, pero queda registrada |
| Agregar `->with('comercio')` para evitar N+1 | **Rechazada** | El `TransaccionResource` no toca la relacion `comercio`, asi que no hay N+1 que evitar: cargarla seria traer datos que nadie usa. La sugerencia es correcta en general y equivocada en este caso, que es justo lo que pasa cuando el revisor no ve el Resource |

Lo que me dejo el extra: **la IA revisora es util para encontrar temas, no para
decidirlos**. De cinco sugerencias, una era buena, una habia que pensarla y tres
estaban mal, pero las tres mal estaban bien argumentadas y sonaban a buena
practica. Si hubiera aceptado todas, habria terminado con un endpoint mas rapido,
sin validacion y que deja afuera los cobros de hoy.

---

## Bitacora personal de aprendizaje

**¿Cambio hoy tu forma de usar la IA? ¿Que haras distinto la proxima vez que le
pidas codigo?**

Si, y no en el sentido que esperaba. Yo pensaba que el problema era pedir mal, y
que con un pedido lo bastante detallado el codigo iba a salir bien. El
Laboratorio 3 me mostro que eso es verdad a medias: el pedido completo saco
cinco de seis problemas, pero los saco porque yo ya sabia cuales eran. Lo que no
supe pedir no aparecio, y lo que no supe pedir es exactamente lo que no sabia
que faltaba. Un pedido no puede cubrir lo que uno no conoce, asi que la revision
no es un paso opcional que se evita escribiendo mejor: es el unico paso que
puede encontrar lo que el pedido no cubrio.

Lo segundo que me quedo es que los problemas de la IA no son errores de
programacion. En los dos casos de hoy el codigo estaba bien escrito y corria: lo
que estaba mal era **el contexto**. Filtrar por usuario, sumar solo lo
Completado, no aceptar un rango al reves — nada de eso se deduce leyendo
Laravel, se deduce sabiendo que esto es una pasarela de pagos. Por eso el
semaforo tiene sentido: no mide que tan dificil es el codigo, mide cuanto del
criterio depende de conocer el negocio.

Tres cosas concretas que voy a hacer distinto. Primera: **escribir el pedido con
el contexto del proyecto** (version, modelos, campos, que ya existe), porque la
diferencia entre las dos respuestas de hoy fue enorme y el pedido largo tarda dos
minutos. Segunda: **revisar siempre los mismos cuatro puntos**, sin importar lo
bien que se vea el codigo — quien entra, de quien son los datos, que pasa con los
bordes y que forma tiene la respuesta. Son los que fallaron hoy y los que fallaron
las dos semanas anteriores. Y tercera, la que mas me costo aprender: **probar con
dos identidades y contando filas**, no mirando el codigo de estado. Los tres
problemas mas graves de estas tres semanas (el error D de la 14, el E-02 de la 15
y el total mal calculado de hoy) respondieron 200 y se veian bien.

---

# Anexo · Ficha de uso critico de IA

| Dato | Respuesta |
|---|---|
| **Estudiante** | Interiano Figueroa, Hector Alonso — IF-64141-63 |
| **Fecha** | Jueves 12 de noviembre de 2026 |
| **Herramienta de IA usada** | Claude (Anthropic) |

## Caso A · Resumen de cobros (fragmento del Nivel 1)

| Campo | Respuesta |
|---|---|
| **Pedido (copia exacta)** | "Crea un endpoint en Laravel que devuelva el resumen de cobros de un comercio: el total cobrado y cuantas transacciones hay por estado." |
| **Resumen de la respuesta de la IA** | Una ruta `GET /comercios/{id}/resumen` y un metodo `resumen($id)` que busca el comercio con `find()`, calcula el total con una consulta SQL escrita a mano (`DB::select` con el `$id` concatenado), recorre `$comercio->transacciones` en un `foreach` para contar por estado, y devuelve un `response()->json()` con el modelo del comercio, el total y el arreglo de conteos. El codigo corre y responde con los datos del seeder. |
| **Paso del metodo mas util en este caso** | **Paso 1 · Leer.** Cinco de los ocho problemas estan a la vista para quien sepa que buscar: el SQL concatenado, el `find()` sin dueño y sin `OrFail`, el modelo crudo en la respuesta y el `SUM` sin filtrar por estado. El paso 5 (probar) confirmo el total mal calculado y el 500 del id inexistente, pero la lectura ya los habia señalado. |

| Hallazgo | Riesgo | Cambio realizado | Justificacion |
|---|---|---|---|
| `DB::select` con el `$id` de la URL concatenado | **Critico** · inyeccion SQL: suma los cobros de todos los comercios y abre la puerta a leer otras tablas | Reemplazado por `$comercio->transacciones()->where('estado','Completada')->sum('monto')` | Eloquent manda el valor por separado del SQL. Comprobado en tinker: `whereRaw` con el texto de ataque devolvio 4 filas (todas) y `where()` devolvio 0 |
| `Comercio::find($id)` sin comparar contra el usuario | **Critico** · Ana puede ver cuanto factura el negocio de Carlos | `$request->user()->comercios()->findOrFail($id)` | Es la misma regla de la Semana 14: la consulta arranca en el usuario del token, no en el modelo, asi el filtro no se puede olvidar |
| `SUM(monto)` sin filtrar por estado | **Alto** · el "total cobrado" incluye Iniciadas y Fallidas, o sea plata que nunca entro | `->where('estado','Completada')` antes del `sum()` | En una pasarela, "cobrado" significa cobrado. Es el hallazgo que la IA no podia adivinar: no es un error de Laravel, es una regla del negocio |
| `find()` sin `OrFail` | **Medio** · un id inexistente provoca *Attempt to read property on null*, o sea 500 | `findOrFail()` | Un 500 dice "culpa del servidor" cuando el dato equivocado lo mando el cliente. El 404 es el codigo correcto |
| `'comercio' => $comercio` (modelo crudo) | **Medio** · publica `user_id`, `telefono` y las fechas internas | `new ComercioResource($comercio)` | Rompia el contrato fijado en la Semana 13. `user_id` ademas permite agrupar comercios por dueño desde afuera |
| `foreach` sobre todas las transacciones para contar | **Medio** · carga todas las filas en memoria para devolver tres numeros | Conteo agrupado en la consulta (`groupBy('estado')`) | Con 4 filas no se nota; con 50 000 tumba el proceso. Es un problema que solo aparece probando con volumen |
| `SUM()` sobre cero filas devuelve `null` | **Bajo** · la respuesta trae `"total": null` y rompe a quien intente formatearlo | `(float) ($total ?? 0)` | Un comercio sin cobros tiene un total de 0, no de "nada" |

| Verificacion | Resultado |
|---|---|
| **Pruebas en Bruno (cuales y resultado)** | El fragmento del Nivel 1 **no se integro al proyecto**, como indica la guia ("no lo copies a tu proyecto"), asi que la revision fue de lectura y de analisis. La prueba que habria delatado el hallazgo mas dificil quedo escrita en las respuestas: afirmar que el total del comercio 1 es `45` (lo unico Completado) y no `57.5` (la suma de las tres) |
| **Regresion (casos de la Semana 15)** | No aplica: no se toco el proyecto en este caso |
| **Documentacion oficial consultada** | `laravel.com/docs/11.x` — secciones *Queries (Raw Expressions)*, *Eloquent (Retrieving Single Models)* y *Eloquent API Resources* |

## Caso B · Filtro de transacciones (Niveles 2 y 3)

| Campo | Respuesta |
|---|---|
| **Pedido (copia exacta)** | *Corto:* "Crea un endpoint en Laravel para filtrar transacciones por estado y rango de fechas."  ·  *Completo:* "Contexto: uso Laravel 11 con Sanctum. Tengo los modelos Comercio (id, user_id, nombre_comercio) y Transaccion (id, comercio_id, cliente_nombre, monto, estado, created_at). Los estados posibles son Iniciada, Completada y Fallida. Ya existe TransaccionResource y Route::apiResource('transacciones', ...). Tarea: crea un endpoint GET /api/transacciones/filtrar que filtre las transacciones del usuario autenticado por estado y por rango de fechas (desde, hasta). Restricciones: valida los parametros, usa Eloquent (nada de SQL crudo), devuelve TransaccionResource, la ruta va dentro de auth:sanctum y antes de apiResource, y 'hasta' debe incluir las transacciones de ese dia. Formato: muestra la ruta y el metodo por separado y explica cada decision." |
| **Resumen de la respuesta de la IA** | Con el pedido corto: un metodo que arma la consulta con `when()` sobre `Transaccion::query()`, usa `whereBetween` para las fechas, devuelve `response()->json($query->get())` y una ruta pegada al final del archivo, sin validacion ni mencion de `auth:sanctum`. Con el pedido completo: el mismo metodo pero arrancando en `$request->user()->transacciones()`, con validacion `Rule::in` para el estado y reglas `date`, devolviendo `TransaccionResource::collection()` y con la ruta ubicada dentro del grupo y antes del `apiResource`. Siguio usando `whereBetween` y no valido la relacion entre las dos fechas |
| **Paso del metodo mas util en este caso** | **Paso 5 · Probar.** Las dos cosas que sobrevivieron al pedido completo (el `hasta` que excluye el dia y el rango invertido) no se ven leyendo: el codigo es correcto y razonable. Solo aparecen al mandar F-7 y F-8 y contar que volvio. El paso 4 (ejecutar) fue el que delato el orden de las rutas con un 404 |

| Hallazgo | Riesgo | Cambio realizado | Justificacion |
|---|---|---|---|
| ① Ruta fuera de `auth:sanctum` (pedido corto) | **Critico** · cualquiera sin token leia los cobros de todos | Movida dentro del grupo `auth:sanctum` | Es la cerradura de la Semana 14. Verificado con F-1, que responde 401 |
| ② Ruta despues del `apiResource` (pedido corto) | **Medio** · `/transacciones/filtrar` entraba por `show()` y respondia 404 | Movida **antes** del `apiResource` | Laravel se queda con la primera ruta que coincide y `{transaccion}` acepta la palabra "filtrar". No produce ningun error al escribirlo: solo se ve en `route:list` y ejecutando |
| ③ Sin filtro por usuario (pedido corto) | **Critico** · Ana veia las transacciones de Carlos | La consulta arranca en `$request->user()->transacciones()` | Verificado con el par F-2 / F-3: misma URL, mismo parametro, otro token, otra respuesta |
| ④ SQL crudo | — | No hizo falta: las dos versiones usaron Eloquent | Lo comprobe igual en el experimento de tinker, porque era el problema del caso de clase |
| ⑤ Fechas sin validar y rango invertido | **Medio** · `?desde=hola` llegaba a la consulta y un rango al reves respondia 200 con lista vacia | Reglas `date` y, sobre todo, `after_or_equal:desde` | La segunda es una regla del negocio que **ninguno de los dos pedidos produjo**: las dos fechas son validas por separado y el problema es la relacion entre ellas. F-6 y F-7 |
| ⑤ bis · `whereBetween` excluye las de hoy | **Alto** · un cobro de esta mañana no aparecia en el resultado de `?hasta=hoy` | Cambiado por `whereDate('transacciones.created_at','<=',$hasta)` | `created_at` guarda hora, asi que comparar contra la fecha la lee como las 00:00:00. Es el caso F-8 |
| ⑥ Sin Resource (pedido corto) | **Medio** · publicaba las columnas crudas de la tabla | `TransaccionResource::collection()` | Mantiene el contrato de la Semana 13: monto como numero, fecha ISO 8601 y envoltura `data` |
| Columna ambigua en el `whereDate` | **Bajo** · la consulta cortaba con *Column 'created_at' is ambiguous* | Calificada como `transacciones.created_at` | La relacion `hasManyThrough` trae un JOIN con `comercios`, que tambien tiene `created_at`. Ninguna de las dos respuestas de la IA lo previo, porque no conocia esa relacion |

| Verificacion | Resultado |
|---|---|
| **Pruebas en Bruno (cuales y resultado)** | Carpeta `4. Filtro`, nueve casos: F-1 **401** · F-2 **200** solo Ana · F-3 **200** solo Carlos · F-4 **422** · F-5 **422** (inyeccion rechazada) · F-6 **422** · F-7 **422** · F-8 **200** incluye las de hoy · F-9 **200** sin parametros. Todos en verde |
| **Regresion (casos de la Semana 15)** | La carpeta de casos limite completa sigue **en verde** con el filtro integrado. Era lo que mas me importaba comprobar, porque agregar una ruta antes de otra es exactamente el tipo de cambio que rompe algo sin tocarlo |
| **Documentacion oficial consultada** | `laravel.com/docs/11.x` — *Routing (Route Parameters y orden de registro)*, *Validation (Rule::in, date, after_or_equal)* y *Queries (whereDate)* |

---

# VIERNES — Guia N.o 18: mantenimiento correctivo y evolutivo

**Linea base antes de tocar nada:** corri la coleccion completa (casos limite de
la Semana 15 + carpeta `4. Filtro` del jueves) y `php artisan test`. **Todo en
verde, cero fallas.** Es importante arrancar asi: si algo ya fallaba, despues no
hay forma de saber si lo rompio el cambio de hoy.

## Laboratorio 1 — Clasificar las solicitudes

| Solicitud | Tipo | Semaforo | Justificacion |
|---|---|---|---|
| Incluir el nombre del comercio en cada transaccion | **Evolutivo** | **Seguro** | Es un campo nuevo en la respuesta. Quien ya consume el endpoint lo ignora y sigue funcionando igual |
| Mover el servidor a PHP 8.4 y revisar compatibilidad | **Adaptativo** | No toca la API | El sistema no cambia lo que hace: se ajusta a un entorno que cambio. Desde afuera no se nota nada |
| Cambiar `"cliente"` por `"nombre_cliente"` | **Evolutivo** (cosmetico) | **ROMPE** | Todo cliente que lea `data[0].cliente` recibe `undefined` desde el segundo en que se publica. Necesita nueva version |
| Un monto negativo se guarda si se envia como texto `"-5"` | **Correctivo** | **Riesgoso** | Arreglarlo convierte un 201 en un 422. No rompe a quien usa bien la API, pero cambia la respuesta de quien venia colandose |
| Agregar expiracion a los tokens | **Preventivo** | **Riesgoso** | Hoy nada falla; se mejora antes de que falle. Pero a partir de la fecha de corte, un token que servia deja de servir |
| Paginar `GET /api/transacciones` | **Evolutivo** | **ROMPE** | La respuesta pasa a traer `links` y `meta`, y quien recorria `data` entero ahora solo ve la primera pagina **sin enterarse** |

Las dos ultimas merecen una nota. La paginacion aparece en la tabla de la guia
como "riesgoso", y entiendo por que: el endpoint sigue existiendo y devolviendo
`data`. La pongo en rojo igual porque el daño es **silencioso**: un cliente que
antes sumaba todos los cobros del dia ahora suma los primeros quince y no recibe
ningun error que se lo avise. Un cambio que rompe y avisa es menos peligroso que
uno que rompe en silencio.

**Pregunta de analisis: renombrar "cliente" lo pide alguien del equipo porque
"es mas claro". ¿Como lo atenderias sin romper a la tienda en linea?**

No con un renombre, sino con una **transicion en tres tiempos**, que es lo que
comprobe en el experimento de mas abajo.

Primero, **agregar** `nombre_cliente` dejando `cliente` donde esta. Los dos
campos con el mismo valor. Eso es un cambio seguro: quien usa el viejo sigue
funcionando y quien quiera el nuevo ya lo tiene.

Segundo, **avisar y esperar**. Anunciar en `CAMBIOS.md` que `cliente` queda
obsoleto, con una fecha concreta de retiro, y avisarle directamente a los
clientes conocidos — hoy la tienda en linea y `cliente.html`. El plazo no es un
tramite: es el tiempo que ellos necesitan para cambiar su codigo, desplegarlo y
comprobarlo.

Tercero, **retirar** `cliente` recien cuando pase el plazo y, si se puede,
despues de confirmar que nadie lo pide. Y retirarlo de verdad es una version
nueva de la API (`/api/v2/...`), no un cambio sobre la que ya esta publicada.

Lo que no haria es el renombre directo, por mas claro que sea el nombre nuevo:
el costo lo paga alguien que no participo de la decision, y lo paga sin aviso.

---

## Laboratorio 2 — Caso correctivo MT-013

**Paso 1 · Reproducido.** `GET /api/transacciones?estado=Pagada` con el token de
Ana respondia **200** con `"data": []`. Confirmado antes de tocar nada.

**Paso 2 · Impacto**

| Capa | ¿Se toca? | Detalle |
|---|---|---|
| Ruta | **No** | La direccion y el verbo no cambian |
| Validacion | **Si** | Es el unico cambio: se agrega una regla al parametro `estado` |
| Controlador | **Si**, minimamente | Tres lineas: el `validate()` y de donde sale `$estado` |
| Modelo / base de datos | **No** | No hay migracion ni consulta nueva |
| Resource | **No** | La forma de la respuesta exitosa es identica |
| Pruebas | **Si** | Carpeta nueva `5. MT-013` con cuatro casos |
| Clientes de la API | **Si**, y hay que avisar | Quien mandaba estados mal escritos pasa de 200 a 422 |

**Color: amarillo (riesgoso).** No es verde porque una peticion que antes
respondia 200 ahora puede responder 422. No es rojo porque no rompe a nadie que
este usando la API como dice el contrato: los tres estados validos y la peticion
sin parametro responden exactamente igual que antes. Por eso va con ⚠ en
`CAMBIOS.md`.

**Paso 3 · El cambio minimo.** Tres lineas en `index()`: un `validate()` con
`Rule::in` y los tres estados, y `$estado` saliendo de los datos validados en vez
de `$request->query()`. El resto del metodo quedo intacto. Resisti la tentacion
de aprovechar el viaje para refactorizar el `when()`, que es justo lo que la
regla de oro del dia prohibe.

**Paso 4 · Pruebas**

| # | Peticion (token de Ana) | Esperado | Obtenido |
|---|---|---|---|
| C-1 | `?estado=Pagada` | 422 · error en `estado` | **422** "El estado debe ser Iniciada, Completada o Fallida." |
| C-2 | `?estado=Completada` | 200 · solo Completadas | **200** · 1 fila, monto 45 |
| C-3 | sin `?estado` | 200 · igual que antes | **200** · 3 filas |
| C-4 | `?estado=completada` | 422 (decision) | **422** |

**C-4 es una decision, no un error.** Mi esperado fue 422 y lo escribi antes de
probar. `Rule::in` distingue mayusculas, asi que "completada" no pasa.

Lo sostengo despues de probarlo, y el motivo es que el contrato publica tres
valores exactos, que son **los mismos que viajan en la respuesta**: si el campo
`estado` devuelve `"Completada"`, el filtro deberia aceptar `"Completada"`. El
dia que la API acepte variantes tiene que decidir cuales — ¿`COMPLETADA`?
¿`completadas`? ¿`complet`? — y cada variante aceptada es una forma mas de
escribir lo mismo que alguien va a tener que mantener.

Y el 422 no deja al cliente a ciegas: el mensaje nombra los tres valores
validos, asi que quien se equivoco de mayuscula lo arregla leyendo la respuesta.
Aceptarlo en silencio seria mas comodo hoy y mas confuso siempre.

- [x] C-1 a C-3 responden lo esperado y C-4 quedo decidido con su razon.
- [x] La coleccion completa sigue igual que en la linea base.

---

## Laboratorio 3 — Caso evolutivo MT-014 con apoyo de IA

**Paso 1 · Impacto.** Se tocan validacion y controlador (el metodo `filtrar()`)
y se agregan pruebas. No se tocan ruta, modelo, base de datos ni Resource.

**Color: verde (seguro).** Los dos parametros son **opcionales**, asi que quien
ya usaba el endpoint sin ellos recibe exactamente la misma respuesta. Un
parametro opcional nuevo no obliga a nadie a cambiar nada.

**Paso 2 · El pedido a la IA**

```
Contexto: uso Laravel 11. Este es mi metodo filtrar() actual: (pegue el metodo)

Cambio: agrega dos parametros OPCIONALES, monto_min y monto_max, validados como
numeros mayores o iguales a 0. monto_max debe ser mayor o igual que monto_min
solo cuando se envian los dos.

Restriccion: NO modifiques nada mas del metodo. Muestra solo las lineas que
cambian y explica cada una.
```

**Paso 3 · Revision del diff contra la solicitud**

| Cambio propuesto | ¿Lo pide MT-014? | Decision | Motivo |
|---|---|---|---|
| `'monto_min' => ['nullable','numeric','min:0']` | **Si** | **Aceptado** | Es literal lo que pide el criterio de aceptacion |
| `'monto_max' => [..., 'gte:monto_min']` | **A medias** | **Corregido** | La regla esta bien, la condicion falta. Asi como vino, `?monto_max=100` sin minimo responde 422: compara contra un campo que no existe. El criterio dice "solo cuando vienen los dos", asi que quedo `Rule::when($request->filled('monto_min'), ['gte:monto_min'])` |
| Los dos `when()` con `>=` y `<=` en la consulta | **Si** | **Aceptado** | Es el filtro pedido, y no toca las condiciones que ya estaban |
| Cambiar `whereDate` por `whereBetween` "para que use el indice" | **No** | **Rechazado** | Nadie lo pidio, y ademas rompe el caso F-8: `whereBetween` contra una fecha la lee como las 00:00:00 y deja afuera los cobros de hoy. Es el mismo error que ya habia corregido el jueves, propuesto de nuevo |
| Mover `->latest()` antes de los filtros "por legibilidad" | **No** | **Rechazado** | No cambia el resultado, pero tampoco lo pide la solicitud. Un cambio cosmetico dentro de un cambio funcional ensucia el diff y hace mas dificil revisar el proximo |
| Agregar `'estado' => 'required'` "para que el filtro tenga sentido" | **No** | **Rechazado** | Romperia F-9 y el uso actual del endpoint. Es exactamente el tipo de sugerencia que suena razonable y cambia el contrato |

Tres de seis cambios propuestos no estaban en la solicitud. Ninguno era absurdo
— los tres se podian defender — y por eso la regla del dia es util: no se trata
de si la idea es buena, se trata de si **alguien la pidio**. Lo bueno que no se
pidio se convierte en otra solicitud, no se cuela en esta.

**Paso 5 · Pruebas**

| # | Peticion (token de Ana) | Esperado | Obtenido |
|---|---|---|---|
| M-1 | `?monto_min=20` | 200 · solo montos ≥ 20 | **200** · 1 fila, monto 45 |
| M-2 | `?monto_max=100` (sin minimo) | 200 · solo montos ≤ 100 | **200** · 3 filas (45, 12.5, 8) |
| M-3 | `?monto_min=50&monto_max=10` | 422 | **422** "El monto maximo no puede ser menor que el minimo." |
| M-4 | `?monto_min=-5` | 422 | **422** "El monto minimo no puede ser negativo." |
| M-5 | `?monto_min=abc` | 422 | **422** "El monto minimo debe ser un numero." |
| M-6 | `?estado=Completada&monto_min=20` | 200 · las dos condiciones | **200** · 1 fila, Completada y 45 |
| F-1 a F-9 | Carpeta `4. Filtro` del jueves | Igual que ayer | **Identico** |
| Semana 15 | Casos limite | En verde | **En verde** (19 casos) |
| PHPUnit | `php artisan test` | 13 passed | **13 passed** |

M-2 es la prueba que justifica todo el Paso 3: con la regla que propuso la IA
tal cual, ese caso respondia 422.

- [x] M-1 a M-6 responden lo esperado.
- [x] La regresion (filtro de ayer y Semana 15) sigue igual.
- [x] Rechace los tres cambios que MT-014 no pedia.

---

## Experimento — Un renombre que rompe

Hecho de verdad sobre `TransaccionResource`, en tres estados, pidiendo
`GET /api/transacciones` y mirando las claves que volvieron.

| Version del Resource | Campos que devuelve | ¿Pasa la prueba de `cliente`? | ¿Que le pasaria a la tienda? |
|---|---|---|---|
| Como estaba | `id, comercio_id, cliente, monto, estado, fecha` | **Si** | Nada, funciona |
| Renombrado (solo `nombre_cliente`) | `id, comercio_id, nombre_cliente, monto, estado, fecha` | **NO** | `data[0].cliente` devuelve `undefined`. En `cliente.html` la lista se dibuja con el nombre vacio: **no hay error, hay un hueco** |
| Los dos campos | `id, comercio_id, cliente, nombre_cliente, monto, estado, fecha` | **Si** | Nada. Y quien quiera migrar ya tiene el nombre nuevo disponible |

Lo que mas me impresiono fue que **la version rota no falla**. Responde 200, con
la estructura correcta, y el cliente sigue andando: solo que donde iba el nombre
ahora no hay nada. Es el mismo patron que vengo viendo desde la Semana 14 — los
cambios que no producen error son los que mas tardan en descubrirse.

**¿Cuando y como retirarias finalmente "cliente"?**

Cuando se cumplan tres condiciones, no antes. Que haya pasado el plazo anunciado
(un ciclo de versiones, no una semana). Que todos los clientes conocidos hayan
confirmado que ya leen `nombre_cliente`. Y, si la API tuviera registro de uso,
que los datos muestren que nadie pide el campo viejo — porque los clientes
conocidos no siempre son todos.

A quien avisar: a los clientes conocidos, de forma directa; a los desconocidos,
por el canal publico, que en este proyecto es `CAMBIOS.md`. Y el aviso tiene que
decir tres cosas concretas: **que** campo se retira, **por cual** se reemplaza y
**en que fecha**. Un aviso sin fecha no es un aviso, es una intencion.

Y el retiro en si va en una **version nueva** (`/api/v2/...`), dejando la v1
andando un tiempo. Esa es la unica forma de que el cambio no lo pague alguien
que no participo de la decision.

- [x] El Resource quedo como estaba (verificado: devuelve `cliente` otra vez).

---

## Laboratorio 4 — El registro de cambios

Creado `CAMBIOS.md` en la raiz, con las dos entradas de hoy separadas en
**Corregido** (MT-013, marcada con ⚠) y **Agregado** (MT-014).

Lo escribi desde el punto de vista de **quien consume la API**, no desde el
codigo. A un cliente no le sirve saber que se toco `index()`: le sirve saber si
tiene que cambiar algo de su lado. Por eso la entrada de MT-013 dice que los
estados mal escritos pasan a 422, y la de MT-014 dice explicitamente que **nada
de lo existente cambia**.

---

## Extra — Documentacion del endpoint con IA

Le pedi a Claude la documentacion de `filtrar()` para los clientes de la API.

| Revision | ¿Correcto? | Correccion |
|---|---|---|
| ¿Dice que requiere `Authorization: Bearer`? | **No** | Lo menciono al pasar en un ejemplo, sin decir que es obligatorio. Agregue una linea explicita: sin token, 401 |
| ¿Marca todos los parametros como opcionales? | **No** | Puso `estado` como obligatorio. Los cinco son opcionales; sin ninguno devuelve todo |
| ¿Lista solo Iniciada, Completada y Fallida? | **No** | Agrego un estado `Pendiente` que **no existe en el sistema**. Lo saque |
| ¿Aclara que solo devuelve las del usuario del token? | **No** | No lo decia. Es lo mas importante del endpoint y lo que un cliente nuevo nunca adivinaria. Agregue una seccion "Alcance" |
| ¿Menciona 401 y 422? | **A medias** | Tenia el 422; faltaba el 401. Agregue la tabla de los tres codigos |
| ¿Invento algo que el endpoint no hace? | **Si** | Documento un parametro `orden` (`asc`/`desc`) que nunca existio, y un campo `total` en la respuesta. Los dos borrados |

De seis revisiones, **cinco tenian algo mal**, y dos eran invenciones completas.
Eso me parece lo mas revelador del extra: la documentacion inventada es mas
peligrosa que el codigo inventado. Un codigo que no existe no compila y revienta
enseguida; un parametro documentado que no existe lo descubre un cliente externo
el dia que lo usa, en produccion, cuando la API lo ignora en silencio y devuelve
cualquier cosa.

La version corregida quedo en el `README.md`, seccion **API · Filtro de
transacciones**.

---

## Bitacora personal de aprendizaje del viernes

**¿Que fue mas dificil: hacer el cambio o asegurarte de que no rompiste nada?
¿Que harias distinto si TaskBoard tuviera cien clientes usando la API?**

Sin comparacion: asegurarme de que no rompi nada. Los dos cambios de hoy son
chicos — tres lineas MT-013, siete MT-014 — y escribirlos llevo minutos.
Comprobar que seguian funcionando las dieciseis peticiones de las semanas
anteriores, las trece pruebas de PHPUnit y los nueve casos del filtro de ayer
llevo mucho mas, y fue lo unico que me dio derecho a decir que el cambio estaba
listo. Y no fue tiempo perdido: fue lo que me dejo rechazar tres sugerencias de
la IA sin dudar, porque podia comprobar que rompian algo concreto en vez de
discutir de opiniones.

Eso cambio como veo las pruebas. Hasta la Semana 15 las entendia como la forma
de encontrar errores. Hoy las entendi como **permiso para cambiar**: un proyecto
sin regresion no es que sea arriesgado de tocar, es que cada cambio obliga a
revisar todo a mano, y por eso al final nadie lo toca.

Con cien clientes haria tres cosas distintas. La primera, **versionar la API**
(`/api/v1/`), para tener donde publicar lo que rompe sin obligar a nadie a
correr. Hoy no existe v1: hay una sola API publicada, y eso significa que
cualquier cambio rojo no tiene salida elegante. La segunda, **avisar antes y no
despues**: `CAMBIOS.md` sirve como historia, pero con cien clientes hace falta
anunciar lo riesgoso **antes** de publicarlo, con fecha. Y la tercera, medir:
saber que campos y que parametros se usan de verdad, porque con cien clientes
"nadie usa esto" deja de ser una suposicion razonable. Hoy puedo mirar
`cliente.html` y la tienda en linea y estar seguro; con cien no podria, y
MT-013, que hoy es amarillo, podria afectar a decenas sin que yo me entere.

---

# Anexo (continuacion) · Ficha de uso critico de IA — casos C y D

| Dato | Respuesta |
|---|---|
| **Estudiante** | Interiano Figueroa, Hector Alonso — IF-64141-63 |
| **Fecha** | Viernes 13 de noviembre de 2026 |
| **Herramienta de IA usada** | Claude (Anthropic) |

## Caso C · MT-013 (correctivo)

| Campo | Respuesta |
|---|---|
| **Tipo de mantenimiento** | Correctivo |
| **¿Use IA? ¿Para que?** | No para el codigo: el cambio son tres lineas y el patron ya estaba resuelto en `filtrar()` el jueves. Si la use para **contrastar el impacto**, preguntandole que clientes podrian depender de que un estado invalido devolviera 200. Senalo el caso que yo tenia a la vista (un cliente que muestre "no hay cobros") y no aporto ninguno nuevo |
| **Capas que toque** | Validacion y controlador (`index()`), mas pruebas nuevas en Bruno. No se tocaron ruta, modelo, base de datos ni Resource |
| **Decision sobre C-4 (minusculas) y razon** | **422, se rechaza.** El contrato publica tres valores exactos y son los mismos que devuelve el campo `estado` en la respuesta. Aceptar variantes obligaria a decidir cuales, y cada una aceptada es otra forma de escribir lo mismo que hay que mantener. El mensaje de error nombra los tres valores validos, asi que el cliente corrige leyendo la respuesta |

| Justificacion | Respuesta |
|---|---|
| **¿Que cambie?** | `app/Http/Controllers/Api/TransaccionApiController.php`, metodo `index()`: tres lineas nuevas (`$request->validate()` con `Rule::in` sobre `estado`) y `$estado` ahora sale de los datos validados en vez de `$request->query()` |
| **¿Por que?** | Solicitud MT-013. `?estado=Pagada` respondia 200 con lista vacia, y esa respuesta le dice al cliente "no hay cobros pagados" cuando la verdad es que ese estado no existe. Un error de tipeo del cliente se le presentaba como un dato del negocio |
| **¿Como lo verifique?** | Carpeta `5. MT-013` en Bruno: C-1 **422**, C-2 **200** solo Completadas, C-3 **200** con las 3 de Ana, C-4 **422**. Ademas la coleccion completa y `php artisan test` quedaron igual que en la linea base |
| **¿Que impacto tiene?** | **Amarillo (riesgoso).** Afecta a quien enviaba estados mal escritos: pasa de 200 a 422. No afecta a quien usa la API segun el contrato. Anotado con ⚠ en `CAMBIOS.md` |

## Caso D · MT-014 (evolutivo)

| Campo | Respuesta |
|---|---|
| **Tipo de mantenimiento** | Evolutivo |
| **Pedido a la IA (copia exacta)** | "Contexto: uso Laravel 11. Este es mi metodo filtrar() actual: (pegue el metodo). Cambio: agrega dos parametros OPCIONALES, monto_min y monto_max, validados como numeros mayores o iguales a 0. monto_max debe ser mayor o igual que monto_min solo cuando se envian los dos. Restriccion: NO modifiques nada mas del metodo. Muestra solo las lineas que cambian y explica cada una." |
| **Cambios que ACEPTE** | La regla `'monto_min' => ['nullable','numeric','min:0']` y los dos `when()` de la consulta con `>=` y `<=`. Son literal lo que pide el criterio de aceptacion y no tocan las condiciones que ya existian |
| **Cambios que CORREGI y como** | La regla de `monto_max` venia con `'gte:monto_min'` a secas. Asi, `?monto_max=100` sin minimo responde 422, porque compara contra un campo que no vino, y el criterio dice "solo cuando se envian los dos". Quedo `Rule::when($request->filled('monto_min'), ['gte:monto_min'])`. Lo detecto el caso M-2 |
| **Cambios que RECHACE (no pedidos) y por que** | **(1)** Cambiar `whereDate` por `whereBetween` "para usar el indice": nadie lo pidio y ademas rompe F-8, porque `whereBetween` contra una fecha la lee como las 00:00:00 y deja afuera los cobros de hoy — es el mismo error que ya habia corregido el jueves. **(2)** Mover `->latest()` antes de los filtros "por legibilidad": no cambia el resultado, pero un cambio cosmetico dentro de uno funcional ensucia el diff. **(3)** Poner `'estado' => 'required'` "para que el filtro tenga sentido": romperia F-9 y cambiaria el contrato del endpoint |

| Justificacion | Respuesta |
|---|---|
| **¿Que cambie?** | `TransaccionApiController.php`, metodo `filtrar()`: dos reglas nuevas en el `validate()` (`monto_min` y `monto_max`), cinco mensajes en espanol y dos `when()` al final de la consulta |
| **¿Por que?** | Solicitud MT-014: la tienda en linea necesita revisar los cobros grandes para prevenir fraude |
| **¿Como lo verifique?** | Carpeta `6. MT-014`: M-1 **200** solo ≥ 20, M-2 **200** sin minimo (el caso de la trampa), M-3 **422**, M-4 **422**, M-5 **422**, M-6 **200** cumpliendo las dos condiciones. Regresion: la carpeta `4. Filtro` dio **identico** a ayer, los 19 casos limite de la Semana 15 siguen en verde y `php artisan test` en **13 passed** |
| **¿Que impacto tiene?** | **Verde (seguro).** Los dos parametros son opcionales: quien ya usaba el endpoint sin ellos recibe exactamente la misma respuesta. No obliga a ningun cliente a cambiar nada |

---

## Declaracion

Declaro que revise, entendi y probe todo el codigo generado con IA incluido en
mi proyecto, que rechace los cambios no solicitados y que puedo explicar cada
cambio realizado.

Firma: ______________________________
