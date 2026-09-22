<?php

namespace App\Http\Requests;

use App\Support\FiltroDoDiretorio;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Os filtros do diretório de prestadores (T10, RF11).
 *
 * Nada aqui é obrigatório, e nada aqui volta como erro de campo: o diretório é
 * uma consulta pública entre autenticados, e termo grande demais ou UF
 * inexistente não é um estado que a tela precise explicar ao tutor — é filtro
 * que não encontra nada. As regras existem para limitar o que chega à consulta,
 * não para conversar com quem digitou.
 */
class ConsultarDiretorioRequest extends FormRequest
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
            'nome' => ['nullable', 'string', 'max:120'],
            'municipio' => ['nullable', 'string', 'max:120'],
            'uf' => ['nullable', 'string', 'size:2'],
        ];
    }

    public function filtro(): FiltroDoDiretorio
    {
        return FiltroDoDiretorio::de(
            $this->validated(),
            // A presença do parâmetro é o que distingue "ainda não escolhi" de
            // "escolhi ver todos" — ver FiltroDoDiretorio. `has()` responde
            // pela presença na requisição, inclusive quando o valor é vazio.
            municipioInformado: $this->has('municipio'),
        );
    }
}
