<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvePrestadorAtivo;
use App\Http\Requests\CadastrarTutorNaClinicaRequest;
use App\Models\Animal;
use App\Models\Convite;
use App\Models\Prestador;
use App\Models\RegistroDeAcesso;
use App\Models\Tutor;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * V04 — cadastrar tutor no atendimento (RF12, RF13, RF14).
 *
 * A segunda origem do cadastro que RF12 admite: o profissional cria o registro
 * global no balcão, e o titular recebe o convite de ativação pelo qual define a
 * própria senha (RF14). Até ativar, a conta existe para o registro clínico e
 * para nada mais — não recebe lembrete, não concede autorização.
 *
 * A conta nasce como a do veterinário convidado em A03: senha aleatória
 * inacessível, `ativado_em` nulo. A diferença é o nome, que aqui vem preenchido
 * — o veterinário o colheu de quem está à sua frente, e é ele que RF13b manda
 * ocultar de todo outro prestador até a autorização.
 */
class CadastroDeTutorController extends Controller
{
    use ResolvePrestadorAtivo;

    public function store(CadastrarTutorNaClinicaRequest $request): JsonResponse
    {
        [$profissional, $prestador] = $this->contextoClinico($request);

        $dados = $request->validated();

        $existente = Tutor::query()->where('cpf', $dados['cpf'])->first();

        if ($existente !== null) {
            return $this->conduzirAoVinculo($profissional, $prestador, $existente);
        }

        // Depois do CPF, de propósito: com cadastro existente, o fluxo é o de
        // RF13 e o e-mail digitado nem chega a importar. A recusa confirma que
        // o endereço tem conta, mas não de quem nem de que papel — e quem a lê
        // é um profissional autenticado com o titular à sua frente, não o
        // visitante anônimo de quem o autocadastro se defende.
        if (User::query()->where('email', $dados['email'])->exists()) {
            throw ValidationException::withMessages([
                'email' => 'Já existe uma conta com este e-mail na plataforma. Confira o endereço com o tutor.',
            ]);
        }

        [$convite, $token, $tutor] = DB::transaction(function () use ($dados, $prestador, $profissional) {
            $usuario = User::create([
                'name' => $dados['nome'],
                'email' => $dados['email'],
                // Inacessível de propósito, como em A03: a senha real é
                // definida no aceite do convite (RF14).
                'password' => Str::random(64),
            ]);

            $tutor = Tutor::create([
                'user_id' => $usuario->id,
                'nome' => $dados['nome'],
                'cpf' => $dados['cpf'],
                // Sem aceite de termos: quem os aceita é o titular, na
                // ativação. O veterinário não pode consentir por ele.
            ]);

            [$convite, $token] = Convite::emitir($usuario, $prestador, 'tutor', $profissional);

            return [$convite, $token, $tutor];
        });

        // Fora da transação, como em P03 e A03: o envio é efeito colateral, e
        // uma falha de entrega não pode desfazer o cadastro já criado — o
        // convite expirado ou perdido se reenvia, o registro não se refaz.
        $convite->enviar($token);

        return response()->json([
            'message' => 'Tutor cadastrado. O convite de ativação foi enviado por e-mail.',
            'tutor' => [
                'id' => $tutor->id,
                'nome' => $tutor->nome,
            ],
            'convite' => [
                'email' => $dados['email'],
                'validade_em_dias' => Convite::VALIDADE_EM_DIAS,
            ],
        ], 201);
    }

    /**
     * RF12b — o CPF existente jamais cria segundo registro: conduz ao fluxo de
     * vínculo de RF13. A resposta diz só que o cadastro existe — sem nome, sem
     * contato, sem animais (RN12) —, e fica registrada como toda revelação de
     * existência (RF18b), pela mesma razão da busca de V03: o titular vê em T14
     * que este prestador chegou ao seu CPF.
     *
     * No caminho desenhado o profissional já verificou o CPF pela busca antes
     * de abrir o formulário; chegar aqui com CPF existente é a janela entre a
     * verificação e o envio. O log não pode depender de qual dos dois caminhos
     * revelou a existência.
     */
    private function conduzirAoVinculo(User $profissional, Prestador $prestador, Tutor $existente): JsonResponse
    {
        // Com animal do tutor já sob autorização vigente, o profissional o
        // conhece pelo próprio âmbito — anunciar a existência não acrescenta
        // nada, e registrá-la de novo encheria T14 de linhas sem informação.
        $jaConhecido = Animal::query()
            ->sobAutorizacaoVigenteDe($prestador)
            ->where('tutor_id', $existente->id)
            ->exists();

        if (! $jaConhecido) {
            RegistroDeAcesso::create([
                'prestador_id' => $prestador->id,
                'user_id' => $profissional->id,
                'tutor_id' => $existente->id,
                'animal_id' => null,
                'natureza' => RegistroDeAcesso::BUSCA_POR_CPF,
                'ocorrido_em' => now(),
            ]);
        }

        return response()->json([
            'situacao' => 'cpf_existente',
            'message' => 'Já existe um cadastro com este CPF na plataforma. Para vincular este tutor ao atendimento, solicite a autorização.',
        ], 409);
    }
}
