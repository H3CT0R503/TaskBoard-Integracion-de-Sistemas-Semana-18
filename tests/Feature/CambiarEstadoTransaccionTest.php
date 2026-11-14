<?php

namespace Tests\Feature;

use App\Models\Comercio;
use App\Models\Transaccion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Laboratorio 4 · el cierre del cobro, escrito al revés.
 *
 * Estas pruebas se escribieron antes del código y arrancaron todas en
 * rojo, con un 405 porque la ruta no existía. Es el otro orden posible
 * y conviene distinguirlo del Laboratorio 2: allá las pruebas
 * describían algo que YA funcionaba, para poder moverlo sin romperlo;
 * acá describen algo que no existía, y fueron la especificación.
 *
 * Por qué este endpoint es nuevo y no un refactor de update(): el
 * método update de la guía cambia el estado, y el update de este
 * proyecto no lo toca, porque desde la Semana 15 'estado' está
 * prohibido en el cuerpo de PUT. Mover el estado era una capacidad que
 * la API no tenía. Metérsela a PUT habría cambiado su contrato, que es
 * justo lo que esta semana no se hace, así que entró por una dirección
 * propia: PATCH /api/transacciones/{id}/estado.
 */
class CambiarEstadoTransaccionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Transaccion}
     */
    private function cobro(string $estado = 'Iniciada'): array
    {
        $usuario = User::factory()->create();
        $comercio = Comercio::factory()->create(['user_id' => $usuario->id]);

        $transaccion = $comercio->transacciones()->create([
            'cliente_nombre' => 'Luis Mena',
            'monto' => 100,
            'metodo' => 'tarjeta',
            'comision' => 3.50,
            'estado' => $estado,
        ]);

        return [$usuario, $transaccion];
    }

    public function test_cierra_una_transaccion_iniciada(): void
    {
        [$usuario, $transaccion] = $this->cobro();
        Sanctum::actingAs($usuario);

        $this->patchJson("/api/transacciones/{$transaccion->id}/estado", [
            'estado' => 'Completada',
        ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'Completada');

        $this->assertDatabaseHas('transacciones', [
            'id' => $transaccion->id,
            'estado' => 'Completada',
        ]);
    }

    /**
     * El cambio deja rastro, igual que la creación.
     *
     * El evento y la actualización van en la misma transacción de base
     * de datos: un estado movido sin su evento sería un cambio del que
     * nadie podría decir cuándo pasó ni desde dónde.
     */
    public function test_el_cambio_queda_en_la_bitacora(): void
    {
        [$usuario, $transaccion] = $this->cobro();
        Sanctum::actingAs($usuario);

        $this->patchJson("/api/transacciones/{$transaccion->id}/estado", [
            'estado' => 'Fallida',
        ])->assertOk();

        $this->assertDatabaseHas('eventos_transaccion', [
            'transaccion_id' => $transaccion->id,
            'tipo_evento' => 'cambio_estado',
            'detalle' => 'Iniciada -> Fallida',
        ]);
    }

    /**
     * La regla de negocio del día: solo se cierra lo que está abierto.
     *
     * Y llega como 422 con el formato de un error de validación, aunque
     * no la haya puesto una regla de validación sino el servicio, con
     * ValidationException::withMessages(). Para el cliente es igual a
     * cualquier otro 422, que es exactamente lo que se buscaba: el
     * servicio no sabe qué es un 422 y aun así responde uno.
     */
    public function test_una_transaccion_ya_cerrada_devuelve_422(): void
    {
        [$usuario, $transaccion] = $this->cobro('Completada');
        Sanctum::actingAs($usuario);

        $this->patchJson("/api/transacciones/{$transaccion->id}/estado", [
            'estado' => 'Fallida',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('estado');

        $this->assertDatabaseHas('transacciones', [
            'id' => $transaccion->id,
            'estado' => 'Completada',
        ]);
    }

    /**
     * Iniciada no es un destino: este endpoint cierra cobros, no los
     * reabre. La lista de destinos válidos es un dato, así que vive en
     * el Form Request y no en el servicio.
     */
    public function test_un_estado_que_no_cierra_devuelve_422(): void
    {
        [$usuario, $transaccion] = $this->cobro();
        Sanctum::actingAs($usuario);

        $this->patchJson("/api/transacciones/{$transaccion->id}/estado", [
            'estado' => 'Iniciada',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('estado');

        $this->patchJson("/api/transacciones/{$transaccion->id}/estado", [
            'estado' => 'Pagada',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('estado');
    }

    public function test_la_transaccion_de_otro_devuelve_404(): void
    {
        [, $ajena] = $this->cobro();
        Sanctum::actingAs(User::factory()->create());

        $this->patchJson("/api/transacciones/{$ajena->id}/estado", [
            'estado' => 'Completada',
        ])->assertStatus(404);

        $this->assertDatabaseHas('transacciones', [
            'id' => $ajena->id,
            'estado' => 'Iniciada',
        ]);
    }

    public function test_sin_token_devuelve_401(): void
    {
        [, $transaccion] = $this->cobro();

        $this->patchJson("/api/transacciones/{$transaccion->id}/estado", [
            'estado' => 'Completada',
        ])->assertStatus(401);
    }
}
