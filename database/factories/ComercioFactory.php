<?php

namespace Database\Factories;

use App\Models\Comercio;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fábrica de comercios, pedida por el Laboratorio 2 de la Semana 18.
 *
 * Hasta ahora las pruebas de este proyecto se armaban con el seeder:
 * $this->seed() y después buscar a Ana por su correo. Eso sirve cuando
 * la prueba necesita el escenario completo (cuatro comercios de dos
 * dueños, con transacciones en tres estados), y por eso las pruebas de
 * casos límite de la Semana 15 siguen haciéndolo así.
 *
 * Para las pruebas de hoy estorba. Una prueba de comisión necesita un
 * usuario y un comercio suyo, y nada más; sembrar todo el escenario la
 * ataría a datos que no le importan, y el día que el seeder cambie un
 * monto, fallaría una prueba que no habla de montos. La fábrica deja
 * que cada prueba diga exactamente de qué depende.
 *
 * user_id => User::factory() crea el dueño solo si la prueba no pasó
 * uno. Si pasa ->create(['user_id' => $ana->id]), ese gana.
 */
class ComercioFactory extends Factory
{
    protected $model = Comercio::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'nombre_comercio' => fake()->unique()->company(),
            'rubro' => fake()->randomElement([
                'Restaurante', 'Ferretería', 'Librería', 'Farmacia',
            ]),
            'telefono' => '2222-'.fake()->numerify('####'),
        ];
    }
}
