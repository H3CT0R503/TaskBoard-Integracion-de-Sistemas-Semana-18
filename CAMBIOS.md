# Registro de cambios · TaskBoard API

Cada entrada dice qué cambió desde el punto de vista de **quien consume la API**,
no desde el punto de vista del código. Un cliente no necesita saber qué archivo
se tocó: necesita saber si tiene que cambiar algo de su lado.

Las entradas marcadas con ⚠ son cambios **riesgosos**: no rompen a quien usa la
API correctamente, pero sí cambian la respuesta en algún caso que antes pasaba
sin avisar.

---

## 2026-11-19

### Cambiado

- **Refactorización interna hacia una capa de servicios.** El registro de
  un cobro, su actualización y su borrado salieron del controlador y
  viven ahora en `TransaccionService`. **Para quien consume la API no
  cambia nada:** las mismas direcciones, los mismos cuerpos, los mismos
  códigos de estado y los mismos mensajes. No hay nada que tocar del
  lado del cliente. La entrada está acá porque este registro es también
  la bitácora del proyecto, no porque haya algo que atender. Verificado
  con 35 pruebas automáticas, que pasaban antes de mover el código y
  siguen pasando después.

- **El mensaje de un método de pago inválido.** Antes decía "El método de
  pago debe ser tarjeta o transferencia" y ahora dice "Ese método de
  pago no está disponible". El código sigue siendo **422** y el campo
  del error sigue siendo `metodo`. El mensaje dejó de enumerar los
  métodos porque la lista de métodos válidos ya no está escrita en la
  validación: se la pregunta al cálculo de comisiones, para que agregar
  un método de pago no pueda dejar dos listas en desacuerdo.

### Agregado

- **Método de pago y comisión en las transacciones.** `POST
  /api/transacciones` acepta un campo nuevo, **`metodo`**, con los
  valores `tarjeta`, `transferencia` y `billetera`, y calcula la
  **`comision`** del cobro: 3.5 % en tarjeta, 1.50 fijo en transferencia
  y 2 % en billetera.
  **`metodo` es opcional.** Quien no lo envíe recibe exactamente lo de
  siempre, un 201, y el cobro queda registrado como `tarjeta`. Los dos
  campos salen además en la respuesta de todas las transacciones, y
  agregar campos a una respuesta es seguro: quien ya leía este JSON toma
  las llaves que le interesan y las nuevas las ignora.
  En `PUT /api/transacciones/{id}`, `metodo` también es opcional, y no
  enviarlo **no** cambia el método que la transacción ya tenía. Lo que
  sí cambia es la comisión: si el PUT trae un monto o un método nuevo,
  la comisión se recalcula, porque una comisión de tarjeta que no
  corresponde al monto guardado es un dato falso.
  `comision` puede venir en **null**: las transacciones creadas antes de
  esta fecha no tuvieron comisión, y un 0.00 diría que se cobró cero.

- **`PATCH /api/transacciones/{id}/estado`**, para cerrar un cobro.
  Recibe `{"estado": "Completada"}` o `{"estado": "Fallida"}` y responde
  la transacción actualizada con **200**. Las reglas: solo se puede
  cerrar una transacción que esté en `Iniciada` (si ya está cerrada
  responde **422**), `Iniciada` no es un destino válido (**422**), y una
  transacción de otra cuenta responde **404**.
  Es una dirección nueva y no un cambio en el `PUT`: el `PUT` sigue
  prohibiendo `estado` en el cuerpo, igual que desde la Semana 15.

---

## 2026-11-13

### Corregido

- **MT-013:** `GET /api/transacciones` responde **422** si `?estado` no es
  `Iniciada`, `Completada` o `Fallida` (antes: **200** con lista vacía).
  ⚠ Los clientes que enviaban estados mal escritos ahora verán 422 donde antes
  recibían una lista vacía. Ese era justamente el problema: una lista vacía se
  lee como "no hay cobros con ese estado" y escondía el error de quien pedía.
  El parámetro sigue siendo opcional y los estados válidos responden igual que
  antes.

### Agregado

- **MT-014:** `GET /api/transacciones/filtrar` acepta dos parámetros nuevos,
  **`monto_min`** y **`monto_max`**, para filtrar por rango de monto. Los dos
  son **opcionales** y deben ser números mayores o iguales a 0; cuando se envían
  juntos, `monto_max` no puede ser menor que `monto_min`. Se pueden combinar con
  los filtros que ya existían (`estado`, `desde`, `hasta`).
  **Nada de lo existente cambia:** quien ya usaba el endpoint sin estos
  parámetros recibe exactamente la misma respuesta que antes. Verificado con la
  carpeta `4. Filtro`, que sigue dando los mismos resultados que ayer.

---

## 2026-11-12

### Agregado

- **Semana 16 (jueves):** nuevo endpoint `GET /api/transacciones/filtrar`, que
  filtra las transacciones del usuario autenticado por `estado`, `desde` y
  `hasta`. Los tres parámetros son opcionales. Requiere token.

---

## Semanas anteriores

El detalle de lo que entró en las semanas 13 a 15 (API REST, Sanctum, manejo de
errores e integridad) está en el `README.md` y en los archivos
`RESPUESTAS_SEMANA*.md`. Este registro arranca con el mantenimiento de la
Semana 16, que es cuando el proyecto pasó a tener clientes que dependen de que
la respuesta no cambie de un día para otro.
