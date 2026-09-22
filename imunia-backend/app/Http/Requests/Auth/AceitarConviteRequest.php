<?php

namespace App\Http\Requests\Auth;

use App\Models\Convite;
use App\Models\User;
use App\Rules\SenhaForte;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * O aceite do convite (P07, RF09, RF14).
 *
 * O que o convidado precisa preencher depende do que falta na conta dele.
 * Conta nova define nome e senha; conta que já existe — o veterinário que RF09
 * permite manter vínculo com mais de um prestador — não define coisa alguma,
 * porque já tem as duas. Exigir a senha dele aqui não seria uma formalidade
 * inócua: o aceite a **sobrescreveria**, e a pessoa perderia a senha com que
 * entra na outra clínica.
 */
class AceitarConviteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $usuario = $this->usuarioConvidado();

        return [
            // Só o tutor cadastrado pela clínica aceita aqui, e só uma vez.
            // Exigir de novo de quem já aceitou transformaria a reativação num
            // pedido de reconsentimento que a lei não pede.
            'aceite_termos' => [
                Rule::excludeIf(fn () => ! $this->exigeAceiteDosTermos()),
                'accepted',
            ],
            'password' => [
                'nullable',
                Rule::requiredIf(fn () => $usuario !== null && $usuario->ativado_em === null),
                new SenhaForte,
            ],
            'nome' => [
                'nullable',
                Rule::requiredIf(fn () => $usuario !== null && blank($usuario->name)),
                'string',
                'max:255',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'nome.required' => 'Informe o seu nome: ele acompanha cada registro que você assinar.',
            'aceite_termos.accepted' => 'É preciso aceitar os termos de uso e a política de privacidade para ativar a conta.',
        ];
    }

    /**
     * O convite de veterinário não pede aceite — quem responde pelos termos do
     * estabelecimento é quem o cadastrou. O de tutor pede, porque o cadastro
     * foi feito pela clínica e o titular não esteve presente.
     */
    private function exigeAceiteDosTermos(): bool
    {
        $convite = Convite::localizar((string) $this->route('token'));

        // Convite que não vale mais não pede aceite algum: quem responde por
        // ele é o controlador, com 410. Exigir a caixa aqui trocaria "este
        // convite venceu" por "marque a caixa" — mandaria a pessoa cumprir uma
        // formalidade que não a levaria a lugar nenhum.
        if ($convite === null || $convite->foiAceito() || $convite->expirou()) {
            return false;
        }

        return $convite->tipo === 'tutor'
            && $convite->usuario->tutor !== null
            && ! $convite->usuario->tutor->aceitouOsTermos();
    }

    /**
     * Nulo quando o token não corresponde a convite algum — caso em que nada
     * é exigido e o controlador responde 410, que é a resposta certa para um
     * convite que não vale mais.
     */
    private function usuarioConvidado(): ?User
    {
        return Convite::localizar((string) $this->route('token'))?->usuario;
    }
}
