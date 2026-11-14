# Registro de cambios · TaskBoard API

Cada entrada dice qué cambió desde el punto de vista de **quien consume la API**,
no desde el punto de vista del código. Un cliente no necesita saber qué archivo
se tocó: necesita saber si tiene que cambiar algo de su lado.

Las entradas marcadas con ⚠ son cambios **riesgosos**: no rompen a quien usa la
API correctamente, pero sí cambian la respuesta en algún caso que antes pasaba
sin avisar.

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
