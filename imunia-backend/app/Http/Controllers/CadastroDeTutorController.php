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

/**
 * V04 — cadastrar tutor no atendimento (RF12, RF13, RF14).
 *
 * A segunda origem do cadastro que RF12 admite: o profissional cria o registro
 * global no balcão, só com nome e e-mail. O convite de ativação (RF14) não sai daqui: sai com o
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

        $contaExistente = User::query()->where('email', $dados['email'])->first();
        $existente = $contaExistente?->tutor;

        // RF12b — o e-mail que já é de um tutor jamais cria segundo registro:
        // o atendimento segue para o animal com o cadastro que existe.
        if ($existente !== null) {
            return $this->conduzirAoCadastroExistente($profissional, $prestador, $existente);
        }

        // RN05 — um e-mail, uma conta, os papéis que a pessoa tiver. O de quem
        // ainda não é tutor — o veterinário que também tem animais, por
        // exemplo — ganha o papel na própria conta, sem convite: ela já tem
        // senha, e o aviso do animal novo chega pelo caminho de sempre.
        $tutor = DB::transaction(function () use ($dados, $contaExistente) {
            $usuario = $contaExistente ?? User::create([
                'name' => $dados['nome'],
                'email' => $dados['email'],
                // Inacessível de propósito, como em A03: a senha real é
                // definida no aceite do convite (RF14).
                'password' => Str::random(64),
            ]);

            return Tutor::create([
                'user_id' => $usuario->id,
                'nome' => $dados['nome'],
                // Sem aceite de termos: quem os aceita é o titular, na
                // ativação. O veterinário não pode consentir por ele.
            ]);
        });

        // Nenhum e-mail sai aqui: o convite vai com o primeiro animal
        // (`ConviteDoTutorService`), que é o que dá sentido à mensagem.
        return response()->json([
            'message' => 'Tutor cadastrado. Cadastre agora o animal — o convite de acesso sai com ele.',
            'tutor' => [
                'id' => $tutor->id,
                'nome' => $tutor->nome,
                'email' => $dados['email'],
            ],
        ], 201);
    }

    /**
     * RF12b — o e-mail existente jamais cria segundo registro. Em vez de parar
     * o atendimento, a resposta devolve o cadastro que já existe, e a tela
     * segue para o animal: o veterinário não precisa de nada do tutor para
     * continuar.
     *
     * Encontrar pelo e-mail um tutor que a clínica ainda não acompanha fica
     * registrado (RF18b), pela mesma razão da busca de V03: o titular vê em T14
     * que este prestador chegou ao seu cadastro.
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
                'natureza' => RegistroDeAcesso::BUSCA_POR_EMAIL,
                'ocorrido_em' => now(),
            ]);
        }

        return response()->json([
            'situacao' => 'tutor_existente',
            'message' => 'Este e-mail já tem cadastro de tutor no Imunia. Siga para o cadastro do animal.',
            'tutor' => [
                'id' => $existente->id,
                'nome' => $existente->nome,
                'email' => $existente->user->email,
            ],
            // Os animais dele, para a tela oferecer a ficha de cada um ao lado
            // do cadastro de um novo.
            'animais' => $existente->animais()
                ->orderBy('nome')
                ->get()
                ->map(fn (Animal $animal) => $animal->paraListagem())
                ->all(),
        ]);
    }
}
