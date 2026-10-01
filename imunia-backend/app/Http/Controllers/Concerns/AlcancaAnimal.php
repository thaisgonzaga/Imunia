<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Animal;
use App\Models\Prestador;

/**
 * A porta de entrada de toda rota clínica que recebe o código de um animal.
 *
 * O código é identificador exato: quem o tem na mão está com o animal (ou com o
 * tutor) à sua frente, e o atendimento não espera por ninguém. Por isso não há
 * recusa por âmbito — só o 404 do código inexistente —, e alcançar o animal já
 * o põe na carteira do prestador, que é o que painel, pendências e listas leem.
 */
trait AlcancaAnimal
{
    protected function animalAlcancado(string $codigo, Prestador $prestador): Animal
    {
        $animal = Animal::query()->with('tutor')->where('codigo', $codigo)->first();

        abort_if($animal === null, 404, 'Animal não encontrado.');

        $prestador->vincular($animal);

        return $animal;
    }
}
