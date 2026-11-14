<?php

namespace Tests\Feature;

use App\Enums\EstadoTransaccion;
use App\Models\Comercio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El panel web, después del enum.
 *
 * Esta prueba existe por un susto concreto del Extra de la Semana 18.
 * El cast a enum cambia lo que devuelve $transaccion->estado en TODO el
 * proyecto, no solo en la API, y el CRUD web de las Semanas 9 a 12
 * comparaba ese valor contra 'Iniciada' y 'Fallida' escritos a mano en
 * dos vistas. Esas comparaciones pasan a ser falsas en silencio, y lo
 * comprobé volviendo a poner una a propósito: la página carga igual,
 * responde 200 y se queda sin los botones de mover estado. Nada se
 * queja, porque comparar un enum con una cadena no es un error de PHP,
 * es simplemente falso.
 *
 * Ninguna de las 33 pruebas que había lo habría notado, porque todas
 * hablan de la API. Así que esta es la que debí tener antes de empezar:
 * si mañana el estado vuelve a cambiar de forma, algo se pone rojo.
 */
class PanelWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_panel_muestra_los_botones_de_una_iniciada(): void
    {
        $usuario = User::factory()->create();
        $comercio = Comercio::factory()->create(['user_id' => $usuario->id]);

        $comercio->transacciones()->create([
            'cliente_nombre' => 'Luis Mena',
            'monto' => 100,
            'metodo' => 'tarjeta',
            'comision' => 3.50,
            'estado' => EstadoTransaccion::Iniciada,
        ]);

        $this->actingAs($usuario)
            ->get("/comercios/{$comercio->id}")
            ->assertOk()
            ->assertSee('Marcar Completada')
            ->assertSee('Marcar Fallida');
    }

    public function test_el_panel_no_ofrece_mover_una_completada(): void
    {
        $usuario = User::factory()->create();
        $comercio = Comercio::factory()->create(['user_id' => $usuario->id]);

        $comercio->transacciones()->create([
            'cliente_nombre' => 'Marta Rivas',
            'monto' => 50,
            'metodo' => 'transferencia',
            'comision' => 1.50,
            'estado' => EstadoTransaccion::Completada,
        ]);

        $this->actingAs($usuario)
            ->get("/comercios/{$comercio->id}")
            ->assertOk()
            // El badge verde del componente: si el enum rompiera la
            // comparación, saldría el amarillo de "cualquier otro".
            ->assertSee('✔ Completada')
            ->assertDontSee('Marcar Fallida');
    }
}
