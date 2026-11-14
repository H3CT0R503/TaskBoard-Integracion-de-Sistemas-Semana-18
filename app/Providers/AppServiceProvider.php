<?php

namespace App\Providers;

use App\Services\CalculadoraComision;
use App\Services\Comisiones\ComisionTarjeta;
use App\Services\Comisiones\ComisionTransferencia;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Semana 18 · Laboratorio 5 · el único lugar que sabe qué reglas
     * de comisión existen.
     *
     * Hasta este archivo, el proyecto no tenía ningún enlace propio en
     * el contenedor, y eso no era pereza: Laravel construye solo
     * cualquier clase cuyas dependencias sean también clases. Ve el
     * tipo en el constructor, lo resuelve, y así encadena
     * TransaccionApiController → TransaccionService sin que nadie
     * escriba nada.
     *
     * CalculadoraComision rompe la cadena porque su constructor no pide
     * una clase: pide un array. Y de un array no hay nada que
     * reflexionar: "arreglo" no dice CUÁL arreglo, así que el
     * contenedor no tiene de dónde sacarlo y falla con
     * BindingResolutionException: Unresolvable dependency resolving
     * [Parameter #0 [ <required> array $reglas ]]. El experimento del
     * laboratorio consiste en verlo pasar, y pasa.
     *
     * Este singleton es la respuesta: la receta para construirla.
     * Cuando alguien pida una CalculadoraComision, se arma con estas
     * reglas.
     *
     * singleton y no bind: las reglas no guardan estado, así que una
     * sola instancia para toda la petición alcanza y no se construyen
     * tres objetos cada vez que se calcula una comisión.
     *
     * Y acá está la ganancia del día, en una frase: agregar un método
     * de pago es escribir una clase nueva y sumar una línea a ESTA
     * lista. Ningún archivo ya probado se modifica.
     */
    public function register(): void
    {
        $this->app->singleton(CalculadoraComision::class, fn () => new CalculadoraComision([
            new ComisionTarjeta(),
            new ComisionTransferencia(),
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
