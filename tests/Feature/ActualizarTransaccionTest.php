<?php

namespace Tests\Feature;

use App\Models\Comercio;
use App\Models\Transaccion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Laboratorio 4 · la red de seguridad de PUT y DELETE.
 *
 * Mismo procedimiento que el Laboratorio 2 y por el mismo motivo:
 * estas cinco pruebas se escribieron y se corrieron ANTES de mover
 * update() y destroy() al servicio, y pasaron. Después de moverlos
 * volvieron a pasar sin tocarles una letra, y eso es la prueba de que
 * fue un refactor y no un rediseño disfrazado.
 *
 * La sexta prueba es distinta y está marcada: la recalculación de la
 * comisión es una regla NUEVA, no un comportamiento que existiera
 * antes, así que estuvo en rojo hasta que la escribí. Las dos cosas
 * caben en el mismo archivo, pero no son lo mismo y conviene que se
 * vea cuál es cuál.
 */
class ActualizarTransaccionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Transaccion}
     */
    private function cobroPropio(array $datos = []): array
    {
        $usuario = User::factory()->create();
        $comercio = Comercio::factory()->create(['user_id' => $usuario->id]);

        $transaccion = $comercio->transacciones()->create(array_merge([
            'cliente_nombre' => 'Luis Mena',
            'monto' => 100,
            'metodo' => 'tarjeta',
            'comision' => 3.50,
        ], $datos));

        return [$usuario, $transaccion];
    }

    public function test_actualiza_los_datos_del_cobro(): void
    {
        [$usuario, $transaccion] = $this->cobroPropio();
        Sanctum::actingAs($usuario);

        $this->putJson("/api/transacciones/{$transaccion->id}", [
            'comercio_id' => $transaccion->comercio_id,
            'cliente_nombre' => 'Marta Rivas',
            'monto' => 60,
        ])
            ->assertOk()
            ->assertJsonPath('data.cliente', 'Marta Rivas')
            // 60 y no 60.0: el Resource entrega el monto como número
            // con (float), y al serializarlo a JSON un 60.0 se escribe
            // "60", así que del otro lado vuelve como entero. El dato
            // es el correcto; lo que no existe en JSON es la diferencia
            // entre 60 y 60.0.
            ->assertJsonPath('data.monto', 60);
    }

    /**
     * Un PUT no mueve el estado, y eso viene de la Semana 15: el estado
     * se cambia por su propio camino, no colándolo en el cuerpo de una
     * actualización de datos.
     */
    public function test_el_put_no_puede_mover_el_estado(): void
    {
        [$usuario, $transaccion] = $this->cobroPropio();
        Sanctum::actingAs($usuario);

        $this->putJson("/api/transacciones/{$transaccion->id}", [
            'comercio_id' => $transaccion->comercio_id,
            'cliente_nombre' => 'Luis Mena',
            'monto' => 100,
            'estado' => 'Completada',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('estado');
    }

    /**
     * Un PUT que no manda metodo no le cambia el método.
     *
     * Esta es la otra mitad de la promesa de compatibilidad: los
     * clientes que ya existen mandan comercio_id, cliente y monto, y
     * nada más. Si el valor por omisión se aplicara también en PUT,
     * una transferencia pasaría a tarjeta sola.
     */
    public function test_el_put_sin_metodo_no_cambia_el_metodo(): void
    {
        [$usuario, $transaccion] = $this->cobroPropio([
            'metodo' => 'transferencia',
            'comision' => 1.50,
        ]);
        Sanctum::actingAs($usuario);

        $this->putJson("/api/transacciones/{$transaccion->id}", [
            'comercio_id' => $transaccion->comercio_id,
            'cliente_nombre' => 'Luis Mena',
            'monto' => 100,
        ])
            ->assertOk()
            ->assertJsonPath('data.metodo', 'transferencia');
    }

    /**
     * El intruso trae un comercio PROPIO a propósito.
     *
     * Si mandara el comercio ajeno, la petición moriría antes en el
     * Form Request con un 422 por comercio_id, y la prueba nunca
     * llegaría a ejercitar lo que quiere probar: que la transacción
     * ajena, identificada en la URL, responde 404. Con un comercio
     * suyo el cuerpo es impecable y el único problema que queda es el
     * dueño de la transacción.
     */
    public function test_actualizar_la_de_otro_devuelve_404(): void
    {
        [, $ajena] = $this->cobroPropio();
        $intruso = User::factory()->create();
        $suyo = Comercio::factory()->create(['user_id' => $intruso->id]);
        Sanctum::actingAs($intruso);

        $this->putJson("/api/transacciones/{$ajena->id}", [
            'comercio_id' => $suyo->id,
            'cliente_nombre' => 'Intruso',
            'monto' => 1,
        ])->assertStatus(404);

        $this->assertDatabaseHas('transacciones', [
            'id' => $ajena->id,
            'cliente_nombre' => 'Luis Mena',
        ]);
    }

    public function test_borrar_la_de_otro_devuelve_404_y_no_la_borra(): void
    {
        [, $ajena] = $this->cobroPropio();
        $intruso = User::factory()->create();
        Sanctum::actingAs($intruso);

        $this->deleteJson("/api/transacciones/{$ajena->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('transacciones', ['id' => $ajena->id]);
    }

    public function test_borra_la_propia_y_responde_204(): void
    {
        [$usuario, $transaccion] = $this->cobroPropio();
        Sanctum::actingAs($usuario);

        $this->deleteJson("/api/transacciones/{$transaccion->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('transacciones', ['id' => $transaccion->id]);
    }

    /**
     * REGLA NUEVA de la Semana 18, no parte de la red de seguridad.
     *
     * La comisión de tarjeta es un porcentaje del monto, así que un
     * monto nuevo con la comisión vieja deja la fila diciendo dos cosas
     * que no concuerdan. La regla vive en el servicio, que es el único
     * que sabe de dónde sale una comisión.
     */
    public function test_al_cambiar_el_monto_se_recalcula_la_comision(): void
    {
        [$usuario, $transaccion] = $this->cobroPropio();
        Sanctum::actingAs($usuario);

        $this->putJson("/api/transacciones/{$transaccion->id}", [
            'comercio_id' => $transaccion->comercio_id,
            'cliente_nombre' => 'Luis Mena',
            'monto' => 200,
        ])
            ->assertOk()
            ->assertJsonPath('data.comision', 7);

        $this->assertDatabaseHas('transacciones', [
            'id' => $transaccion->id,
            'comision' => 7.00,
        ]);
    }
}
