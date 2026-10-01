<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvePrestadorAtivo;
use App\Http\Requests\CadastrarTutorNaClinicaRequest;
use App\Models\Animal;
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
 * global no balcão. O convite de ativação (RF14) não sai daqui: sai com o
 * cadastro do primeiro animal. O atendimento não espera por nada disso — até
 * ativar, a conta existe para o registro clínico, e o tutor que quiser
 * acompanhar entra depois.
 *
 * A conta nasce como a do veterinário convidado em A03: senha aleatória
 * inacessível, `ativado_em` nulo. A diferença é o nome, que aqui vem preenchido
 * — o veterinário o colheu de quem está à sua frente.
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
            return $this->conduzirAoCadastroExistente($profissional, $prestador, $existente);
        }

        // Depois do CPF, de propósito: com cadastro existente, o fluxo segue
        // para o animal e o e-mail digitado nem chega a importar. A recusa confirma que
        // o endereço tem conta, mas não de quem nem de que papel — e quem a lê
        // é um profissional autenticado com o titular à sua frente, não o
        // visitante anônimo de quem o autocadastro se defende.
        if (User::query()->where('email', $dados['email'])->exists()) {
            throw ValidationException::withMessages([
                'email' => 'Já existe uma conta com este e-mail na plataforma. Confira o endereço com o tutor.',
            ]);
        }

        $tutor = DB::transaction(function () use ($dados) {
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

            return $tutor;
        });

        // Nenhum e-mail sai aqui: o convite vai com o primeiro animal
        // (`ConviteDoTutorService`), que é o que dá sentido à mensagem.
        return response()->json([
            'message' => 'Tutor cadastrado. Cadastre agora o animal — o convite de acesso sai com ele.',
            'tutor' => [
                'id' => $tutor->id,
                'nome' => $tutor->nome,
                'cpf' => $tutor->cpf,
                'email' => $dados['email'],
            ],
        ], 201);
    }

    /**
     * RF12b — o CPF existente jamais cria segundo registro. Em vez de parar o
     * atendimento, a resposta devolve o cadastro que já existe, e a tela segue
     * para o animal: o veterinário não precisa de nada do tutor para continuar.
     *
     * Encontrar pelo CPF um tutor que a clínica ainda não acompanha fica
     * registrado (RF18b), pela mesma razão da busca de V03: o titular vê em T14
     * que este prestador chegou ao seu CPF.
     */
    private function conduzirAoCadastroExistente(User $profissional, Prestador $prestador, Tutor $existente): JsonResponse
    {
        $jaAcompanhado = Animal::query()
            ->vinculadoA($prestador)
            ->where('tutor_id', $existente->id)
            ->exists();

        if (! $jaAcompanhado) {
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
            'message' => 'Este CPF já tem cadastro no Imunia. Siga para o cadastro do animal.',
            'tutor' => [
                'id' => $existente->id,
                'nome' => $existente->nome,
                'cpf' => $existente->cpf,
            ],
        ]);
    }
}
