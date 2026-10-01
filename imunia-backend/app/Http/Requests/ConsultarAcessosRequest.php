<?php

namespace App\Http\Requests;

use App\Support\FiltroDeAcessos;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Os filtros da auditoria de acessos (T14, RF53).
 *
 * Nada aqui é obrigatório e nada aqui volta como erro de campo: código de
 * animal que não existe ou período desconhecido não é estado que a tela
 * precise explicar ao tutor — é recorte que não encontra nada, ou que recai no
 * padrão. As regras existem para limitar o que chega à
 * consulta, não para conversar com quem digitou.
 *
 * A tolerância importa por causa de quem monta a URL: um endereço guardado
 * pode apontar para um recorte que já não tem linha alguma. A resposta certa é a lista vazia, não a tela de erro.
 */
class ConsultarAcessosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'animal' => ['nullable', 'string', 'max:20'],
            'periodo' => ['nullable', 'string', 'max:10'],
            'prestador' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function filtro(): FiltroDeAcessos
    {
        return FiltroDeAcessos::de($this->validated());
    }
}
