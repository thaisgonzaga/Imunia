<?php

namespace App\Http\Requests;

use App\Models\Animal;
use App\Models\Autorizacao;
use App\Models\ConfirmacaoDeAutorizacao;
use App\Models\Prestador;
use App\Models\Tutor;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * T11, passos 1 e 2 — a escolha do prestador e dos animais, submetida para que
 * o código seja enviado (RF36, RF37).
 *
 * Nada é concedido aqui. O que esta requisição produz é uma confirmação em
 * aberto e uma mensagem no e-mail do tutor; a autorização nasce na requisição
 * seguinte, com o código de volta.
 */
class IniciarAutorizacaoRequest extends FormRequest
{
    public ?Tutor $tutor = null;

    public ?Prestador $prestador = null;

    /** @var EloquentCollection<int, Animal> */
    public EloquentCollection $animais;

    /**
     * Pedidos de código tolerados antes da pausa, e a duração dela em segundos.
     *
     * A pausa de RF37a conta erros de código; esta conta pedidos, e existe por
     * outro motivo: sem ela, a tela de concessão seria um caminho aberto para
     * despejar mensagens na caixa de entrada de quem quer que seja o tutor
     * autenticado. Cinco pedidos é folga bastante para quem trocou de ideia
     * sobre o animal e recomeçou o fluxo — os mesmos números de P05.
     */
    private const PEDIDOS = 5;

    private const PAUSA = 10 * 60;

    public function authorize(): bool
    {
        /** @var User $usuario */
        $usuario = $this->user();
        $this->tutor = $usuario->tutor;

        abort_if($this->tutor === null, 403, 'Esta área é do ambiente do tutor.');

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'prestador' => ['required', 'integer', Rule::exists('prestadores', 'id')],

            // Um por um, escolhidos explicitamente (RF36a). A lista vazia não é
            // "todos": é pedido sem objeto, e a mensagem diz isso.
            'animais' => ['required', 'array', 'min:1'],
            'animais.*' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'animais.required' => 'Escolha ao menos um animal para autorizar.',
            'animais.min' => 'Escolha ao menos um animal para autorizar.',
            'prestador.required' => 'Escolha o estabelecimento que vai atender.',
            'prestador.exists' => 'Este estabelecimento não está no diretório.',
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->prestador = Prestador::find($this->integer('prestador'));

                /** @var list<string> $codigos */
                $codigos = $this->input('animais');

                $this->animais = $this->tutor->animais()->whereIn('codigo', $codigos)->get();

                // RN12 — animal de outro tutor e código inexistente recebem a
                // mesma resposta. A diferença entre "não existe" e "existe e não
                // é seu" é justamente o que não pode ser dito.
                if ($this->animais->count() !== count(array_unique($codigos))) {
                    $validator->errors()->add('animais', 'Escolha animais do seu cadastro.');

                    return;
                }

                $this->recusarQuemJaEstaAutorizado($validator);
            },
        ];
    }

    /**
     * Concessão em duplicata deixaria duas linhas vigentes para o mesmo par
     * animal-prestador, e a revogação de uma delas não encerraria o acesso —
     * defeito que só apareceria no dia em que o tutor tentasse revogar.
     */
    private function recusarQuemJaEstaAutorizado(Validator $validator): void
    {
        $vigentes = Autorizacao::query()
            ->vigente()
            ->where('prestador_id', $this->prestador->id)
            ->whereIn('animal_id', $this->animais->pluck('id'))
            ->with('animal:id,nome')
            ->get();

        foreach ($vigentes as $autorizacao) {
            $validator->errors()->add('animais', sprintf(
                '%s já está autorizado para este estabelecimento até %s.',
                $autorizacao->animal?->nome,
                $autorizacao->expira_em->format('d/m/Y'),
            ));
        }
    }

    /**
     * As duas condições que interrompem o fluxo antes do envio, ambas com
     * situação nomeada no corpo: a tela entra em estado próprio para cada uma,
     * e nenhuma delas é erro de campo.
     */
    protected function passedValidation(): void
    {
        /** @var User $usuario */
        $usuario = $this->user();

        // RF37b — sem endereço confirmado, o código não teria para onde ir com
        // segurança, e a concessão não se completa. A tela já sabe disto pelas
        // opções do fluxo; a verificação aqui é a que vale.
        if (! $usuario->hasVerifiedEmail()) {
            abort(response()->json([
                'situacao' => 'email_nao_verificado',
                'message' => 'Confirme seu e-mail para conceder autorizações.',
                'email' => $usuario->email,
            ], 403));
        }

        $bloqueio = ConfirmacaoDeAutorizacao::bloqueioDe($usuario);

        if ($bloqueio !== null) {
            abort(response()->json([
                'situacao' => 'bloqueada',
                'message' => 'Houve códigos errados demais. Espere a pausa terminar para autorizar.',
                'segundos_restantes' => $bloqueio->segundosDeBloqueio(),
            ], 429));
        }

        $chave = 'autorizacao-codigo:'.$usuario->id;

        if (RateLimiter::tooManyAttempts($chave, self::PEDIDOS)) {
            abort(response()->json([
                'situacao' => 'muitos_pedidos',
                'message' => 'Já enviamos vários códigos para você. Espere alguns minutos antes de pedir outro.',
                'segundos_restantes' => RateLimiter::availableIn($chave),
            ], 429));
        }

        RateLimiter::hit($chave, self::PAUSA);
    }
}
