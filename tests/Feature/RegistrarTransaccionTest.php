<?php

namespace Tests\Feature;

use App\Models\Comercio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Laboratorio 2 · la red de seguridad del refactor.
 *
 * Estas pruebas se escribieron ANTES de mover una sola línea de código,
 * y ese orden es el laboratorio entero. Un refactor es, por definición,
 * cambiar la forma sin cambiar el comportamiento; sin algo que diga qué
 * es el comportamiento, "no cambió nada" es una opinión. Con estas
 * cinco pruebas en verde antes y después, es un hecho verificable.
 *
 * Cubren las cuatro respuestas que POST /api/transacciones sabe dar:
 * 201 con la comisión correcta según el método, 422 cuando el cuerpo
 * está mal y 401 sin token. Lo que NO cubren es el camino interno: a
 * ninguna le importa si la comisión la calcula el controlador, un
 * servicio o tres clases de reglas. Por eso sobreviven al refactor.
 */
class RegistrarTransaccionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Un usuario con un comercio propio, que es todo lo que estas
     * pruebas necesitan del mundo.
     *
     * @return array{0: User, 1: Comercio}
     */
    private function usuarioConComercio(): array
    {
        $usuario = User::factory()->create();
        $comercio = Comercio::factory()->create(['user_id' => $usuario->id]);

        return [$usuario, $comercio];
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function cobrar(Comercio $comercio, array $datos = [])
    {
        return $this->postJson('/api/transacciones', array_merge([
            'comercio_id' => $comercio->id,
            'cliente_nombre' => 'Luis Mena',
            'monto' => 100,
        ], $datos));
    }

    /**
     * Tarjeta cobra 3.5 %, así que 100 deja 3.50 de comisión.
     *
     * La comisión se revisa en los dos lados a propósito: en el JSON,
     * porque es lo que ve quien consume la API, y en la base de datos
     * con assertDatabaseHas, porque es lo que queda guardado. Si un
     * refactor rompiera el INSERT pero dejara bien la respuesta, la
     * segunda afirmación lo atraparía.
     */
    public function test_registra_transaccion_con_comision_de_tarjeta(): void
    {
        [$usuario, $comercio] = $this->usuarioConComercio();
        Sanctum::actingAs($usuario);

        $this->cobrar($comercio, ['metodo' => 'tarjeta'])
            ->assertCreated()
            ->assertJsonPath('data.estado', 'Iniciada')
            ->assertJsonPath('data.metodo', 'tarjeta')
            ->assertJsonPath('data.comision', 3.5);

        $this->assertDatabaseHas('transacciones', [
            'comercio_id' => $comercio->id,
            'comision' => 3.50,
        ]);
    }

    /**
     * Transferencia cobra 1.50 fijo, no un porcentaje: la comisión no
     * depende del monto.
     */
    public function test_comision_fija_en_transferencia(): void
    {
        [$usuario, $comercio] = $this->usuarioConComercio();
        Sanctum::actingAs($usuario);

        $this->cobrar($comercio, ['metodo' => 'transferencia'])
            ->assertCreated()
            ->assertJsonPath('data.comision', 1.5);

        $this->cobrar($comercio, ['monto' => 1000, 'metodo' => 'transferencia'])
            ->assertCreated()
            ->assertJsonPath('data.comision', 1.5);
    }

    /**
     * La promesa de compatibilidad de la semana, escrita como prueba.
     *
     * El cliente de la Semana 14 y la colección de Bruno no envían
     * metodo, porque hasta esta semana la columna no existía. Tienen
     * que seguir recibiendo 201, y lo que se guarda es tarjeta.
     */
    public function test_sin_metodo_usa_tarjeta_por_omision(): void
    {
        [$usuario, $comercio] = $this->usuarioConComercio();
        Sanctum::actingAs($usuario);

        $this->cobrar($comercio)
            ->assertCreated()
            ->assertJsonPath('data.metodo', 'tarjeta')
            ->assertJsonPath('data.comision', 3.5);
    }

    /**
     * Un comercio de otra persona.
     *
     * La guía esperaba 404 acá, y este proyecto responde 422. La
     * diferencia no es un error: el dueño se comprueba en el Form
     * Request con Rule::exists(...)->where('user_id', ...), así que un
     * comercio_id ajeno es un campo inválido del cuerpo, y eso es un
     * 422 con su error de validación. El 404 de la guía corresponde a
     * un proyecto donde la comprobación vive en el servicio y el id
     * llega por la URL.
     *
     * Cambiarlo a 404 habría sido romper el contrato que la API
     * sostiene desde la Semana 15, que es exactamente lo que esta
     * semana prohíbe. Así que la prueba se ajustó al proyecto, no el
     * proyecto a la prueba.
     */
    public function test_comercio_ajeno_devuelve_422(): void
    {
        [$usuario, ] = $this->usuarioConComercio();
        $ajeno = Comercio::factory()->create();
        Sanctum::actingAs($usuario);

        $this->cobrar($ajeno)
            ->assertStatus(422)
            ->assertJsonValidationErrors('comercio_id');

        $this->assertDatabaseCount('transacciones', 0);
    }

    /**
     * Datos inválidos: monto cero y método inventado.
     *
     * El campo que se revisa con assertJsonValidationErrors es el que
     * importa, porque un 422 "a secas" no dice si falló lo que se
     * quería probar. Un refactor que moviera la validación al servicio
     * y perdiera el nombre del campo dejaría el código en 422 y esta
     * prueba en rojo, que es lo correcto.
     */
    public function test_datos_invalidos_devuelven_422(): void
    {
        [$usuario, $comercio] = $this->usuarioConComercio();
        Sanctum::actingAs($usuario);

        $this->cobrar($comercio, ['monto' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('monto');

        $this->cobrar($comercio, ['metodo' => 'efectivo'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('metodo');
    }

    /**
     * Sin token no se pasa, y el 401 llega antes que la validación:
     * el cuerpo de esta petición está perfecto y aun así no entra.
     */
    public function test_sin_token_devuelve_401(): void
    {
        [, $comercio] = $this->usuarioConComercio();

        $this->cobrar($comercio, ['metodo' => 'tarjeta'])
            ->assertStatus(401);

        $this->assertDatabaseCount('transacciones', 0);
    }
}
