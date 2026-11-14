{{-- Traduce el estado a un badge de color.
     Uso: <x-badge-estado :estado="$transaccion->estado" />
     Al estar en un componente, el día que cambien los colores o los
     íconos se edita solo este archivo y no cada vista --}}
@props(['estado'])

@if ($estado === \App\Enums\EstadoTransaccion::Completada)
    <span class="badge verde">✔ Completada</span>
@elseif ($estado === \App\Enums\EstadoTransaccion::Fallida)
    <span class="badge rojo">✗ Fallida</span>
@else
    <span class="badge amarillo">⏳ {{ $estado->value }}</span>
@endif
