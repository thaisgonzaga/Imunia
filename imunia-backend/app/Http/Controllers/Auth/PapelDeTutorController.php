<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CriarCadastroDeTutorRequest;
use App\Models\Tutor;
use App\Models\User;
use App\Support\DocumentosLegais;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * O papel de tutor acrescentado a uma conta que já existe (RF12, RN05).
 *
 * Fica junto de sessão, senha e minha conta pela mesma razão que `ContaController`:
 * trata da conta com que a pessoa entra, e não do papel que ela exerce num
 * prestador. É a contrapartida do convite de A03, que há tempos reaproveita a
 * conta de quem já é tutor para vinculá-lo a uma equipe — faltava o caminho
 * inverso, e era ele que obrigava o veterinário com um cão em casa a inventar
 * um segundo endereço de correio.
 *
 * Um cadastro de tutor por conta, e nenhum a mais: `tutores.user_id` é único na
 * base, e a recusa aqui existe para que a segunda tentativa leia uma frase em
 * vez de uma violação de integridade.
 */
class PapelDeTutorController extends Controller
{
    public function store(CriarCadastroDeTutorRequest $request): JsonResponse
    {
        $usuario = $request->usuario();
        $dados = $request->validated();

        if ($usuario->tutor()->exists()) {
            return response()->json([
                'message' => 'Esta conta já tem cadastro de tutor.',
            ], 409);
        }

        $tutor = DB::transaction(function () use ($usuario, $dados) {
            // Só de quem chegou sem nome — ver a regra homônima do
            // `CriarCadastroDeTutorRequest`. Quem já tem nome não o redigita
            // aqui, e por isso não há o que divergir.
            if (isset($dados['nome'])) {
                $usuario->forceFill(['name' => $dados['nome']])->save();
            }

            return Tutor::create([
                'user_id' => $usuario->id,
                'nome' => $usuario->name,
                'cpf' => $dados['cpf'],
                // O aceite é desta pessoa e de agora: ela está diante do
                // documento, ao contrário do tutor cadastrado no balcão em V04,
                // que só o vê ao ativar o convite.
                'termos_aceitos_em' => now(),
                'termos_versao' => DocumentosLegais::VERSAO,
            ]);
        });

        return response()->json([
            'message' => 'Cadastro de tutor criado. Seus animais ficam nesta mesma conta.',
            'tutor' => [
                'id' => $tutor->id,
                'nome' => $tutor->nome,
            ],
            // A sessão muda de forma no mesmo ato: o papel novo abre um
            // ambiente que não existia um instante atrás, e o SPA precisa do
            // conjunto atualizado para desenhar a moldura certa.
            'usuario' => $this->representar($usuario->refresh()),
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function representar(User $usuario): array
    {
        return [
            'id' => $usuario->id,
            'nome' => $usuario->name,
            'email' => $usuario->email,
            'email_verificado' => $usuario->hasVerifiedEmail(),
            'papeis' => $usuario->papeis(),
            'rota_inicial' => $usuario->rotaInicialPara('tutor') ?? $usuario->rotaInicial(),
        ];
    }
}
