# 🚀 Guías de Prácticas Laravel - TaskBoard: Pasarela de Pagos

![Laravel](https://img.shields.io/badge/Laravel-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![Bruno](https://img.shields.io/badge/Bruno-F4AA41?style=for-the-badge&logo=bruno&logoColor=white)
![Postman](https://img.shields.io/badge/Postman-FF6C37?style=for-the-badge&logo=postman&logoColor=white)
![PHPUnit](https://img.shields.io/badge/PHPUnit-366488?style=for-the-badge&logo=php&logoColor=white)
![Git](https://img.shields.io/badge/Git-F05032?style=for-the-badge&logo=git&logoColor=white)

---

<p align="center">
  <img src="https://res.cloudinary.com/bcwlyire/image/upload/v1785118882/NUEVO-LOGO-UPED-A-COLOR-scaled_szoqws.jpg" alt="Logo UPED" width="300"/>
</p>

## 🏛️ Información Académica

- **Institución:** Universidad Pedagógica de El Salvador *Luis Alonso Aparicio*
- **Facultad:** Ingeniería
- **Asignatura:** Integración de Sistemas
- **Ciclo:** 02-2026
- **Docente:** Ing. Oscar Contreras
- **Proyecto Integrador:** TaskBoard - Pasarela de Pagos

---

## 📌 Descripción del Proyecto

Este repositorio contiene el trabajo de la **Semana 18** del proyecto **TaskBoard**, una plataforma de **Pasarela de Pagos** desarrollada en el framework **Laravel 12**. Abajo queda documentado también lo de las Semanas 15 y 16, que son la base sobre la que se trabajó.

## 🏗️ Semana 18 — Guía N.° 20: refactorizar hacia una capa de servicios

La consigna de la semana es la más incómoda de todas: **cambiar la estructura sin cambiar el comportamiento**. Nada de lo que un cliente de la API ve se mueve, y aun así casi todo el código de escritura cambia de lugar.

El trabajo entero se hizo en la rama `refactor/servicios`, con ocho commits pequeños, y se midió con pruebas que tenían que pasar **antes y después** de cada paso. Sin eso, "no cambió nada" sería una opinión.

### Lo que se movió

| Antes | Ahora |
|---|---|
| `TransaccionApiController::store` calculaba la comisión, buscaba el comercio, abría la transacción de base de datos y escribía en dos tablas (23 líneas de cuerpo) | Llama al servicio y arma la respuesta (6 líneas) |
| `update` y `destroy` comprobaban el dueño con un helper del propio controlador | `TransaccionService::actualizar` y `::eliminar`, con una sola comprobación de dueño para las tres escrituras |
| Un `if` decidía la comisión según el método de pago | Una clase por método, en `app/Services/Comisiones/`, elegida por `CalculadoraComision` |
| `'Iniciada'`, `'Completada'` y `'Fallida'` escritos a mano en nueve archivos | `app/Enums/EstadoTransaccion.php`, con cast en el modelo |

`TransaccionService` **no recibe `Request` y no devuelve `response()`**: recibe el usuario y arreglos, devuelve modelos, y para las reglas de negocio que no se cumplen lanza una `ValidationException`, que Laravel convierte en el 422 de siempre. Por eso el mismo servicio lo usa el seeder, sin ninguna petición HTTP de por medio.

### Lo que se agregó (y está en `CAMBIOS.md`)

- **`metodo` y `comision`** en las transacciones. `metodo` acepta `tarjeta` (3.5 %), `transferencia` (1.50 fijo) y `billetera` (2 %), y es **opcional**: sin él, el cobro queda como `tarjeta` y la respuesta sigue siendo 201, igual que antes de esta semana.
- **`PATCH /api/transacciones/{id}/estado`**, para cerrar un cobro. Es una dirección nueva y no un cambio en el `PUT`, que sigue prohibiendo `estado` en el cuerpo desde la Semana 15.

### El principio abierto/cerrado, medido

Agregar la **billetera** fue: una clase nueva (`ComisionBilletera`) y una línea en `AppServiceProvider`. `ComisionTarjeta`, `ComisionTransferencia` y `CalculadoraComision` no se tocaron.

Pero también hubo que abrir el Form Request, porque ahí vivía un `Rule::in(['tarjeta', 'transferencia'])` con la lista repetida. Eso se cerró con `app/Rules/MetodoDePagoSoportado.php`, que le pregunta a la calculadora en vez de llevar su propia lista. El detalle importa porque los dos olvidos posibles fallan distinto: faltando la validación la API responde 422 (molesto y visible), faltando la regla responde **500** (un cobro que revienta en producción).

---

## 📡 API · Cierre de un cobro

```http
PATCH /api/transacciones/{id}/estado
Authorization: Bearer <token>
Content-Type: application/json
Accept: application/json

{ "estado": "Completada" }
```

| Caso | Respuesta |
|---|---|
| Una transacción `Iniciada` propia | **200** con la transacción actualizada, y un evento `cambio_estado` en la bitácora |
| `estado` ausente o distinto de `Completada` / `Fallida` | **422** (`Iniciada` no es un destino: este endpoint cierra cobros, no los reabre) |
| Una transacción que ya está cerrada | **422**, con el mensaje de la regla de negocio |
| Una transacción de otra cuenta, o un id que no existe | **404**, con el mismo mensaje para los dos casos |
| Sin token | **401** |

La fila y su evento se escriben dentro de `DB::transaction()`: un estado movido sin su evento sería un cambio sin historia.

---

## 🤖 Semana 16 · Jueves — Guía N.° 17: la IA como asistente

Esta semana no se trata de pedirle código a una IA, sino de **revisar lo que produce**. El endpoint nuevo de este repositorio, `GET /api/transacciones/filtrar`, salió de un pedido a un asistente y pasó por los **seis pasos del método** antes de entrar al proyecto: leer, contrastar con la documentación, revisar seguridad, ejecutar, probar y justificar.

Lo que entregó la IA traía seis problemas. Cada uno quedó corregido y anotado en el código:

| # | Problema | Qué pasaba | Dónde se corrigió |
|---|---|---|---|
| ① | Ruta fuera de `auth:sanctum` | Cualquiera sin token leía los cobros de todos | `routes/api.php` |
| ② | Ruta **después** del `apiResource` | `/transacciones/filtrar` entraba por `show()` y respondía 404, sin ningún error al escribirlo | `routes/api.php` |
| ③ | Sin filtro por usuario | Arrancaba en `Transaccion::query()`: Ana veía lo de Carlos | `filtrar()` |
| ④ | SQL crudo | `whereRaw("estado = '$estado'")`: con `?estado=x' OR '1'='1` devolvía la tabla entera | `filtrar()` |
| ⑤ | Fechas sin validar | `?desde=hola` llegaba a la consulta, y un rango al revés respondía 200 vacío | `filtrar()` |
| ⑥ | Sin Resource | `response()->json($query->get())` publicaba las columnas crudas | `filtrar()` |

> 🔑 **Lo que el pedido completo no arregló.** Un pedido con contexto, tarea, restricciones y formato eliminó cinco de los seis, pero sobrevivieron dos cosas: `whereBetween` contra `hasta`, que deja fuera los cobros de hoy porque `created_at` guarda hora, y la falta de `after_or_equal:desde`, porque un rango invertido es una regla del negocio y no un error de formato. **La IA puede saber más Laravel que uno; no puede saber qué significa un cobro en esta pasarela.**

> 📄 Las respuestas de los cuatro laboratorios, el experimento de inyección SQL y la **ficha de uso crítico de IA** (el entregable del día) están en **[RESPUESTAS_SEMANA16.md](RESPUESTAS_SEMANA16.md)**.

## 🛠️ Semana 16 · Viernes — Guía N.° 18: mantenimiento con apoyo de IA

Dos solicitudes reales sobre un sistema que ya funciona, resueltas con el ciclo completo (solicitud → impacto → cambio → pruebas → documentación) y bajo la regla de **una solicitud, un cambio**.

| | Tipo | Qué se pidió | Semáforo |
|---|---|---|---|
| **MT-013** | Correctivo | `GET /api/transacciones?estado=Pagada` respondía 200 con lista vacía, haciendo pasar un error de tipeo del cliente por un dato del negocio | ⚠️ **Riesgoso** — una petición que antes daba 200 ahora puede dar 422 |
| **MT-014** | Evolutivo | `GET /api/transacciones/filtrar` acepta `monto_min` y `monto_max` para revisar cobros grandes | ✅ **Seguro** — parámetros opcionales nuevos, nada existente cambia |

> 🪤 **La trampa de MT-014.** La IA propuso `'gte:monto_min'` a secas, y con eso `?monto_max=100` sin mínimo fallaba con 422: la regla compara contra un campo que no vino. El criterio de aceptación decía *"solo cuando se envían los dos"*, así que se corrigió con `Rule::when($request->filled('monto_min'), ['gte:monto_min'])`. Revisar el **diff contra la solicitud**, línea por línea, es lo que lo encontró.

El historial de los dos cambios, escrito desde el punto de vista de quien consume la API, está en **[CAMBIOS.md](CAMBIOS.md)**.

---

## 📡 API · Filtro de transacciones

Documentación del endpoint para los clientes de la API. La redactó un asistente y está corregida: la primera versión marcaba `estado` como obligatorio, inventaba un estado `Pendiente`, no mencionaba el 401 y no aclaraba el filtrado por usuario.

```http
GET /api/transacciones/filtrar
Authorization: Bearer <token>
Accept: application/json
```

**Autenticación:** obligatoria. Sin un token válido responde **401**.

**Parámetros** (todos de query, **todos opcionales**; sin ninguno devuelve todas las transacciones del usuario):

| Parámetro | Tipo | Valores válidos |
|---|---|---|
| `estado` | texto | `Iniciada`, `Completada` o `Fallida` — exactamente así, distingue mayúsculas |
| `desde` | fecha | Formato de fecha válido. Incluye el día completo |
| `hasta` | fecha | Formato de fecha válido. **Incluye el día completo**; no puede ser anterior a `desde` |
| `monto_min` | número | ≥ 0 |
| `monto_max` | número | ≥ 0. Cuando se envían los dos, no puede ser menor que `monto_min` |

**Alcance:** devuelve **únicamente** las transacciones de los comercios del usuario dueño del token. No existe forma de consultar las de otro usuario.

| Código | Cuándo |
|---|---|
| **200** | Consulta correcta. `data` puede venir vacío si nada coincide: eso no es un error |
| **401** | Falta el token, o está vencido o revocado |
| **422** | Un parámetro no cumple las reglas de arriba. El cuerpo trae `errors` con el campo y el mensaje |

```bash
GET /api/transacciones/filtrar?estado=Completada&monto_min=20&hasta=2026-11-13
```

```json
{
  "data": [
    {
      "id": 1,
      "comercio_id": 1,
      "cliente": "María López",
      "monto": 45,
      "estado": "Completada",
      "fecha": "2026-11-13T18:22:05+00:00"
    }
  ]
}
```

### Probado con

La colección de Bruno, en cuatro carpetas: **`4. Filtro`** (F-1 a F-9, el jueves), **`5. MT-013`** (C-1 a C-4), **`6. MT-014`** (M-1 a M-6) y la de casos límite de la Semana 15 como regresión. Todo en verde, y `php artisan test` sigue en **13 passed**.

---

## 📌 La Semana 15, que es la base

Las semanas anteriores construyeron la API y le pusieron cerradura. La Semana 15 no construyó nada nuevo: buscó dónde estaba mal. **El jueves se diseñaron casos límite para encontrar errores, el viernes se corrigieron y se demostró con las mismas pruebas que quedaron resueltos.**

Son **dos guías y una sola entrega**:

**Guía N.° 15 — Semana 15 · Jueves: ¿Cómo se prueba una API? Estrategias y casos límite**

1. Diseñar casos con tres técnicas: **partición de equivalencia**, **valores límite** y **casos negativos**.
2. Ejecutarlos en Bruno con pruebas automáticas, escribiendo el resultado esperado **antes** de pulsar Send.
3. Registrar los 15 casos en una bitácora y reportar los errores con sus **seis partes** y su severidad.
4. Comprobar la **idempotencia** del DELETE con un experimento dirigido.
5. (Extra) Escribir la primera prueba de **PHPUnit**.

**Guía N.° 16 — Semana 15 · Viernes: Manejo de errores e integridad de los datos**

1. Corregir los errores de la bitácora y demostrarlo con una **prueba de regresión**.
2. Configurar respuestas de error **uniformes y seguras** en `bootstrap/app.php`.
3. Comprobar con un experimento cómo **`DB::transaction()`** evita registros a medias.
4. (Extra) Proteger cada corrección con una prueba de PHPUnit.

> 🎯 **La regla de oro del jueves.** El resultado esperado se escribe en la bitácora **ANTES** de pulsar Send. Si se escribe después de ver la respuesta, la prueba siempre "pasa" y no prueba nada.

> 📄 Las respuestas de análisis, las tablas de los experimentos y las dos bitácoras de aprendizaje están en **[RESPUESTAS_SEMANA15.md](RESPUESTAS_SEMANA15.md)**. La bitácora de pruebas con los 15 casos y los 5 reportes de error está en **[Bitacora_Pruebas_Semana15_Interiano.xlsx](Bitacora_Pruebas_Semana15_Interiano.xlsx)**.

### 🐛 Lo que se encontró, y lo que se corrigió

| # | Caso | Qué pasaba el jueves | Severidad | Corrección del viernes |
|---|---|---|---|---|
| **E-01** | CL-07 · `monto: 100000000` | **500** `Numeric value out of range`. El dato era del cliente y la API echaba la culpa al servidor | Alta | Regla `max:999999.99` |
| **E-02** | CL-09 · `monto: 25.999` | **201**, y guardaba **26.00**. La base redondeaba dinero en silencio | Alta | Regla `decimal:0,2` |
| **E-03** | CL-12 · `"estado": "Completada"` | **201**, y descartaba el campo sin avisar: el cliente creía haber creado una transacción Completada | Media | Regla `'estado' => ['prohibited']` |
| **E-04** | CL-10 · nombre de 256 | **422** correcto, pero con el texto `validation.max.string` sin traducir | Baja | Mensaje `cliente_nombre.max` |
| **E-05** | CL-13 · JSON con coma de más | **422** "faltan comercio_id, cliente_nombre y monto". Los tres venían | Media | Middleware `ValidarCuerpoJson` → **400** |
| **⚠** | CL-14 · sin `Content-Type` | **422** con el mismo mensaje engañoso. El jueves quedó como *contrato ambiguo* | Media | Contrato decidido: **415** |

### 📐 Dos diferencias con la guía

1. La guía supone que la columna `monto` es `decimal(10,2)`, con tope 99 999 999.99. En este proyecto es **`decimal(8,2)`**, así que el tope real es **999 999.99** y esa es la cota que usa la validación. El caso CL-07 sigue siendo válido: 100 000 000 está muy por encima de los dos topes.
2. La guía nombra el Form Request como `StoreTransaccionRequest`. Aquí se llama **`GuardarTransaccionRequest`**, porque desde la Semana 10 lo comparten `store()` y `update()` de las dos puertas.

---

## 📁 Estructura del Proyecto

Lo de la **Semana 18**. Las dos carpetas nuevas dentro de `app/` se crearon a mano; Laravel las carga sola, porque el namespace sigue la ruta.

```text
taskboard/
├── app/
│   ├── Enums/                                        # NUEVO  carpeta
│   │   └── EstadoTransaccion.php                     # NUEVO  los tres estados, en un solo lugar
│   ├── Services/                                     # NUEVO  carpeta
│   │   ├── TransaccionService.php                    # NUEVO  registrar, actualizar, cambiarEstado, eliminar
│   │   ├── CalculadoraComision.php                   # NUEVO  elige la regla y le pide el numero
│   │   └── Comisiones/                               # NUEVO  carpeta
│   │       ├── ReglaComision.php                     # NUEVO  la interfaz: aplica() y calcular()
│   │       ├── ComisionTarjeta.php                   # NUEVO  3.5 %
│   │       ├── ComisionTransferencia.php             # NUEVO  1.50 fijo
│   │       └── ComisionBilletera.php                 # NUEVO  2 %, el reto
│   ├── Rules/                                        # NUEVO  carpeta
│   │   └── MetodoDePagoSoportado.php                 # NUEVO  la validacion le pregunta a la calculadora
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Api/TransaccionApiController.php      # MODIFICADO  mas delgado + PATCH /estado
│   │   │   └── TransaccionController.php             # MODIFICADO  el mapa de estados se fue al enum
│   │   ├── Requests/
│   │   │   ├── GuardarTransaccionRequest.php         # MODIFICADO  valida metodo
│   │   │   └── CambiarEstadoRequest.php              # NUEVO  el cuerpo del cierre
│   │   └── Resources/TransaccionResource.php         # MODIFICADO  metodo, comision y estado->value
│   ├── Models/Transaccion.php                        # MODIFICADO  fillable + cast a enum
│   └── Providers/AppServiceProvider.php              # MODIFICADO  el singleton de la calculadora
├── bootstrap/app.php                                 # MODIFICADO  404 limpio para ModelNotFoundException
├── database/
│   ├── factories/ComercioFactory.php                 # NUEVO  pedida por el Laboratorio 2
│   ├── migrations/..._add_metodo_y_comision_...php   # NUEVO  las dos columnas que faltaban
│   └── seeders/ComercioSeeder.php                    # MODIFICADO  usa la calculadora
├── resources/views/
│   ├── comercios/show.blade.php                      # MODIFICADO  compara contra el enum
│   └── components/badge-estado.blade.php             # MODIFICADO  compara contra el enum
├── routes/api.php                                    # MODIFICADO  la ruta del cierre
├── tests/Feature/
│   ├── RegistrarTransaccionTest.php                  # NUEVO  7, la red de seguridad del Lab 2
│   ├── ActualizarTransaccionTest.php                 # NUEVO  7, la red de PUT y DELETE
│   ├── CambiarEstadoTransaccionTest.php              # NUEVO  6, escritas antes del codigo
│   └── PanelWebTest.php                              # NUEVO  2, el agujero que dejo ver el enum
├── CAMBIOS.md                                        # MODIFICADO  entrada del 2026-11-19
└── RESPUESTAS_SEMANA18.md                            # NUEVO  los cinco laboratorios y los extras
```

Lo de las **Semanas 15 y 16**, que sigue en el repositorio:

```text
taskboard/
├── app/Http/Middleware/ValidarCuerpoJson.php         # Semana 15  400 y 415 antes de validar
├── tests/Feature/CrearTransaccionTest.php            # Semana 15  11 pruebas de regresion
├── TaskBoard_CasosLimite_S15/                        # Semanas 15 a 18  coleccion de Bruno
├── TaskBoard-API-Semana15.postman_collection.json    # Semana 15  los mismos casos, en Postman
├── Bitacora_Pruebas_Semana15_Interiano.xlsx          # Semana 15  bitacora y reportes de error
└── RESPUESTAS_SEMANA16.md                            # Semana 16  ficha de uso critico de IA
```

---

## ⚙️ Puesta en marcha

El proyecto corre sobre **XAMPP** (Apache + MySQL) con la base de datos `taskboard_18`.

### 1. Crear la base de datos

```sql
CREATE DATABASE taskboard_18 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 2. Preparar el entorno

```bash
composer install
cp .env.example .env
php artisan key:generate
```

| Variable | Valor |
|---|---|
| `DB_DATABASE` | `taskboard_18` |
| `DB_USERNAME` | `root` |
| `DB_PASSWORD` | *(vacío, usuario por defecto de XAMPP)* |

### 3. Crear las tablas y los datos de ejemplo

```bash
php artisan migrate:fresh --seed
php artisan serve
```

### 4. Usuarios y datos de prueba

| Usuario | Correo | Contraseña | Comercios |
|---|---|---|---|
| Ana López | `ana@taskboard.test` | `password123` | 1 Café Amanecer, 2 Pupusería Doña Marta |
| Carlos Pérez | `carlos@taskboard.test` | `password123` | 3 Ferretería El Tornillo, 4 Librería La Página |

---

## 🔌 Códigos de error de la API

La API sigue teniendo las 12 rutas de la Semana 14 (una pública y once dentro de `auth:sanctum`). Lo que cambió esta semana son **las respuestas cuando algo sale mal**:

| Código | Qué significa | Cuándo sale ahora |
|---|---|---|
| **400** Bad Request | No pude leer tu petición | El cuerpo dice ser JSON y no se puede parsear *(nuevo)* |
| **401** Unauthorized | No sé quién eres | Sin token, token vencido o revocado |
| **404** Not Found | No existe para ti | Un id que no existe, o que es de otro usuario |
| **415** Unsupported Media Type | No sé en qué formato me lo mandas | Cuerpo sin `Content-Type: application/json` *(nuevo)* |
| **422** Unprocessable Content | Te entendí, pero los datos no sirven | Validación: monto, nombre, comercio, estado, método de pago. Y desde la Semana 18, también las reglas de negocio del cierre, que las lanza el servicio con `ValidationException` |
| **429** Too Many Requests | Demasiados intentos | 6 logins en un minuto |
| **500** Internal Server Error | Fue culpa mía | Falla de base de datos. **Mensaje genérico**, detalle solo en el log *(nuevo)* |

**Los tres códigos que antes se confundían en uno.** Hasta el jueves, un JSON mal escrito, un cuerpo sin `Content-Type` y una petición a la que de verdad le faltan campos respondían los tres **422 con el mismo mensaje**. Ahora cada uno responde lo suyo, y eso es lo que permite que quien consume la API sepa qué arreglar.

---

## 🧪 Cómo probar

Van **tres formas** en el repositorio, con los mismos casos.

### Opción A — Bruno (la que usa la guía)

La carpeta **`TaskBoard_CasosLimite_S15/`**, que se abre con *Open Collection* (**no** *Import*) apuntando a esa carpeta.

```text
TaskBoard · Casos límite (Semana 15)
├── 0. Preparación   Login Ana → guarda {{tokenAna}}
├── 1. Resueltos     CL-02 (pasa) · CL-07 (el jueves falla)
├── 2. Semiarmados   CL-01 · CL-03 · CL-04 · CL-05 · CL-09
├── 3. Desde cero    CL-06 · CL-08 · CL-10 a CL-17 (+ LEEME)
└── 4. Limpieza      borra lo que crearon CL-01, CL-08, CL-16 y CL-17
```

**Hay que correr primero `0. Preparación / Login Ana`**: ahí se guarda el token que el resto hereda como Bearer.

> 🧹 **Por qué hay una carpeta de limpieza.** Cuatro casos crean transacciones de verdad. Sin borrarlas, cada corrida dejaría cuatro filas nuevas en la base y los conteos del experimento de integridad se irían moviendo entre una corrida y la siguiente.

### Opción B — Postman

**`TaskBoard-API-Semana15.postman_collection.json`**, formato v2.1 (Insomnia también lo importa). Trae las carpetas de la Semana 14 como prueba de regresión **más** la carpeta `4. Casos límite` con los mismos 17 casos:

```text
0. Públicas · 1. Ana · 2. Carlos · 3. Sin token   ← regresión de la Semana 14
4. Casos límite · 5. Limpieza                     ← lo nuevo de la Semana 15
45 peticiones · 101 comprobaciones automáticas
```

> ⏱️ `/api/login` tiene `throttle:5,1` y la colección hace 4 logins: correrla dos veces dentro del mismo minuto devuelve **429** en los últimos. No es un error, es la defensa funcionando.

### Opción C — PHPUnit

```bash
php artisan test
```

```text
PASS  Tests\Feature\CrearTransaccionTest           11 pruebas, Semana 15
PASS  Tests\Feature\RegistrarTransaccionTest        7 pruebas, Laboratorio 2
PASS  Tests\Feature\ActualizarTransaccionTest       7 pruebas, Laboratorio 4
PASS  Tests\Feature\CambiarEstadoTransaccionTest    6 pruebas, Laboratorio 4
PASS  Tests\Feature\PanelWebTest                    2 pruebas, el panel tras el enum
PASS  Tests\Feature\ExampleTest
PASS  Tests\Unit\ExampleTest

Tests:  35 passed (100 assertions)   Duration: 1.4s
```

Para correr solo las de la semana:

```bash
php artisan test --filter=RegistrarTransaccion
```

No necesita servidor ni base de datos encendida: Laravel arma la petición por dentro y usa SQLite en memoria, que nace limpia en cada prueba.

---

## 🧠 Decisiones de diseño

### El límite va en la validación, no en la columna

```php
'monto' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999.99'],
```

Para E-01 se podría haber agrandado la columna a `decimal(15,2)`. **No resuelve el error: lo mueve.** El borde seguiría existiendo más arriba y un monto de un billón volvería a dar el mismo 500, solo que con un valor tan grande que nadie lo probaría por casualidad.

Y responde la pregunta equivocada. La pregunta no es *"¿cuánto cabe?"* sino *"¿cuál es un monto válido para esta pasarela?"*, que es una decisión de negocio: un cobro de cien millones en una pasarela que atiende pupuserías no es un dato que no quepa, es un dato que está mal.

> La columna es la última red, no la regla. Validando contesta la aplicación, que puede decir 422 y explicar el límite; dejándoselo a la columna contesta el motor, que solo sabe reventar — y **contesta distinto según el motor**: el mismo caso que en MySQL da 500, en SQLite da 201 y guarda el valor.

### `decimal:0,2` y no `decimal:2`

Con un solo número exige **exactamente** dos decimales, y eso rechazaría `25` y `25.5`, que son montos válidos. El riesgo está cubierto con una prueba propia, `test_la_correccion_de_e02_no_rompe_los_montos_normales`.

### El cuerpo se revisa antes de validar

```php
$middleware->appendToGroup('api', [ValidarCuerpoJson::class]);
```

E-05 y CL-14 tenían el mismo síntoma — *"faltan los tres campos"* cuando los tres venían — porque Laravel solo convierte el cuerpo en datos cuando puede: con el JSON mal escrito no puede, y sin `Content-Type` ni lo intenta. En los dos casos `$request->all()` queda vacío y la validación reclama lo obligatorio.

Tiene que ser un **middleware** y no una regla: para cuando la validación entra en escena, el cuerpo ya se intentó leer y el daño ya está hecho.

### El detalle del error no desaparece, cambia de destinatario

```php
$exceptions->shouldRenderJsonWhen(fn (Request $r, Throwable $e) => $r->is('api/*') || $r->expectsJson());

$exceptions->render(function (QueryException $e, Request $request) {
    if ($request->is('api/*')) {
        return response()->json(['message' => 'No se pudo guardar la información. Intenta de nuevo más tarde.'], 500);
    }
});
```

Antes, una falla de base de datos respondía con la consulta SQL completa, el nombre de la base, el host, el puerto y las rutas absolutas del servidor. Ahora el cliente recibe una frase y **el log recibe todo**, que es donde hace falta y donde solo entra quien tiene acceso al servidor.

`shouldRenderJsonWhen` hace que `/api` responda JSON aunque falte el `Accept`. Eso no vuelve al encabezado innecesario: sigue siendo la forma en que el cliente dice qué entiende, y un proxy o un gateway delante de esta API decidirían mirando el encabezado, no este archivo.

### `DB::transaction()`: todo o nada

Crear la transacción y registrar su evento son dos INSERT en dos tablas, y entre uno y otro puede fallar cualquier cosa. Medido:

| Momento | `Transaccion::count()` | `EventoTransaccion::count()` |
|---|---|---|
| Antes | 4 | 0 |
| Sin `DB::transaction` | **5** | **0** |
| Con `DB::transaction` | **4** | **0** |

Las dos veces el cliente recibió un 500. Sin la transacción quedó **un cobro sin su evento de creación**: una fila perfectamente válida, que pasa cualquier revisión, que no aparece en ningún reporte armado desde los eventos y que nadie sabe que está ahí, porque el cliente vio un error y dio por hecho que no se guardó nada.

> El 500 miente por omisión: dice "falló" y uno entiende "no pasó nada", cuando en realidad pasó la mitad.

---

## ✅ Verificación

### La Semana 18, medida con pruebas

La suite completa queda en **35 pruebas**:

```text
Tests:    35 passed (100 assertions)
```

| Archivo | Pruebas | Qué fija |
|---|---|---|
| `CrearTransaccionTest.php` | 11 | Los casos límite de la Semana 15. Son la regresión de fondo: pasaban antes del refactor y siguen pasando |
| `RegistrarTransaccionTest.php` | 7 | La red de seguridad del Laboratorio 2: comisión por método, el valor por omisión, 422 y 401 |
| `ActualizarTransaccionTest.php` | 7 | PUT y DELETE: 6 que pasaban antes y después, más la regla nueva de recalcular la comisión |
| `CambiarEstadoTransaccionTest.php` | 6 | El `PATCH /estado`, escritas **antes** del código |
| `PanelWebTest.php` | 2 | El panel web después del enum |
| `ExampleTest.php` (Feature y Unit) | 2 | De semanas anteriores |

Tres momentos de la semana en que el rojo dijo algo:

| Momento | Qué salió |
|---|---|
| Las pruebas del Laboratorio 2, corridas antes de mover código | Una esperaba `60.0` donde JSON devuelve `60`, y otra esperaba 404 donde este proyecto responde 422 porque el dueño se comprueba en el Form Request. Las dos eran errores **de la prueba**, no del código |
| El experimento del Laboratorio 5: inyectar `CalculadoraComision` sin enlazarla | `BindingResolutionException: Unresolvable dependency resolving [Parameter #0 [ <required> array $reglas ]]`. El contenedor resuelve tipos de clase, y de un `array` no hay nada que deducir |
| Volver a poner una comparación contra `'Iniciada'` en una vista, con el cast a enum activo | La página responde 200 y se queda sin los botones de mover estado. Comparar un enum con una cadena no es un error de PHP: es simplemente falso |

---

### Prueba de regresión (Semana 15)

| | Pasan | Fallan |
|---|---|---|
| Línea base (código del jueves) | 11 | **6** — CL-07, CL-09, CL-10, CL-12, CL-13, CL-14 |
| Después de corregir | **17** | 0 |

Esos números son **casos** de la bitácora. El Runner de Bruno cuenta **peticiones**, y la colección tiene 25 (los 17 casos más el login, el LEEME, los tres pasos de CL-15 por separado y las cuatro de limpieza), así que ahí la línea base se ve como 19 en verde y 6 en rojo. Los 6 en rojo son los mismos.

La colección quedó completa en verde **sin tocar una sola prueba**. Si se hubiera cambiado el esperado para que pasaran, no habría demostrado nada.

### Una prueba que pasaba y empezó a fallar

Al correr también la colección de la Semana 14 aparecieron dos pruebas en rojo, las de la petición `3.2`, que afirmaban que una petición sin `Accept` **no** devolvía JSON.

La guía dice que, si algo que pasaba ahora falla, no se cambia la prueba: se revisa la regla. Se revisó, y el caso resultó ser el otro: **la regla está bien y la prueba afirmaba un comportamiento que se cambió a propósito** con `shouldRenderJsonWhen`. Esas dos pruebas se reescribieron, con la explicación dentro de la propia colección.

> Una prueba en rojo puede significar "rompiste algo" o "cambiaste algo a propósito y la prueba no se enteró". Confundirlos es como se cuelan los errores; lo que nunca es válido es cambiar la prueba hasta que pase sin entender cuál de las dos cosas ocurrió.

### El reto del extra

Quitando `max:999999.99` y corriendo `php artisan test`, se pone en rojo **exactamente una** prueba:

```text
⨯ e01 monto sobre el maximo
Tests:  1 failed, 10 passed
```

Las otras diez siguen en verde. Eso es lo que hace útil a una prueba de regresión: no avisa solo que algo se rompió, sino **qué** se rompió.

---

## 📚 Semanas anteriores

- **Semana 13** — servicios web REST, JSON y API Resources
- **Semana 14** — autenticación de API con Laravel Sanctum ([RESPUESTAS_SEMANA14.md](RESPUESTAS_SEMANA14.md))
- **Semana 15** — casos límite, manejo de errores e integridad ([RESPUESTAS_SEMANA15.md](RESPUESTAS_SEMANA15.md))
- **Semana 16** — uso crítico de IA y revisión de código ([RESPUESTAS_SEMANA16.md](RESPUESTAS_SEMANA16.md))
- **Semana 18** — arquitectura, capa de servicios y SOLID ([RESPUESTAS_SEMANA18.md](RESPUESTAS_SEMANA18.md))
