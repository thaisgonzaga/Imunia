<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidaCaracterizacaoDoAnimal;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A consolidação do cadastro preliminar (RF19, RF20b) — o veterinário completa
 * e mantém a caracterização do animal que o tutor iniciou.
 *
 * Só a caracterização: nome, espécie e foto são identificação, permanecem do
 * tutor (RF16) e não passam por aqui. Sexo e nascimento entram porque são os
 * dois declarados sujeitos a confirmação profissional (RF19b, RN14) — e a
 * confirmação é justamente o que esta tela faz com eles.
 */
class CaracterizarAnimalRequest extends FormRequest
{
    use ValidaCaracterizacaoDoAnimal;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->sanearCaracterizacao();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->regrasDeCaracterizacao();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->mensagensDeCaracterizacao();
    }
}
