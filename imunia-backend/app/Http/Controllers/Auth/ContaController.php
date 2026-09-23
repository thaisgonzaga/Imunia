<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AlterarSenhaRequest;
use App\Http\Requests\Auth\AtualizarContaRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * T18 — minha conta (RF04, RF06), a tela comum aos quatro ambientes.
 *
 * Fica junto de sessão, senha esquecida e confirmação de endereço porque é
 * disso que trata: a conta com que a pessoa entra, e não o papel que ela
 * exerce. Tutor, veterinário, administrador de prestador e administrador da
 * plataforma chegam todos aqui, e nenhum deles vê nada além do que é seu.
 */
class ContaController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        return response()->json(['conta' => $this->representar($usuario)]);
    }

    /**
     * RF06 — nome e endereço de contato. A troca de endereço reinicia a
     * verificação (RF06a) e, com ela, suspende os lembretes até a nova
     * confirmação: sem endereço verificado nenhuma notificação sai (RN42), e é
     * consequência que a tela precisa ter dito **antes** de o botão ser
     * apertado, não depois.
     */
    public function update(AtualizarContaRequest $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();
        $dados = $request->validated();
        $trocouDeEmail = $dados['email'] !== $usuario->email;

        DB::transaction(function () use ($usuario, $dados, $trocouDeEmail) {
            $usuario->fill([
                'name' => $dados['nome'],
                'email' => $dados['email'],
            ]);

            if ($trocouDeEmail) {
                $usuario->forceFill(['email_verified_at' => null]);
            }

            $usuario->save();

            // O cadastro de tutor nasce com o nome do usuário (P03) e é ele que
            // encabeça a carteira e o histórico exportados. Deixar os dois
            // divergirem faria a mesma pessoa aparecer com dois nomes conforme
            // a tela — e o documento verificável exibiria o antigo para sempre.
            $usuario->tutor()->update(['nome' => $dados['nome']]);
        });

        if ($trocouDeEmail) {
            $usuario->sendEmailVerificationNotification();
        }

        return response()->json([
            'conta' => $this->representar($usuario->refresh()),
            'avisos' => $trocouDeEmail
                ? ["Enviamos um link de confirmação para {$usuario->email}. Até você confirmar, os lembretes de vacina ficam suspensos."]
                : [],
        ]);
    }

    /**
     * RF04 — troca de senha com confirmação da vigente.
     *
     * As demais sessões caem junto, como na redefinição de RF03b e pelo mesmo
     * motivo: quem troca a senha costuma estar trocando porque desconfia de
     * quem mais a tem. A sessão que pediu a troca é a única preservada — pedir
     * à pessoa que entre de novo no aparelho que ela tem na mão seria punir o
     * cuidado.
     */
    public function atualizarSenha(AlterarSenhaRequest $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $usuario->forceFill([
            'password' => $request->string('password')->toString(),
            'remember_token' => Str::random(60),
        ])->save();

        $this->encerrarOutrasSessoes($request, $usuario);

        return response()->json([
            'message' => 'Senha alterada. As sessões abertas em outros aparelhos foram encerradas.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function representar(User $usuario): array
    {
        return [
            'nome' => $usuario->name,
            'email' => $usuario->email,
            'email_verificado' => $usuario->hasVerifiedEmail(),
            // Só quem tem cadastro de tutor tem CPF no Imunia: o veterinário é
            // identificado pelo CRMV do vínculo, e o administrador de prestador,
            // pelo CNPJ do estabelecimento.
            'cpf' => $usuario->tutor?->cpf,
            'papeis' => $usuario->papeis(),
        ];
    }

    /**
     * A sessão vive na base (SESSION_DRIVER=database), de modo que apagar as
     * linhas do usuário derruba de fato os outros aparelhos.
     */
    private function encerrarOutrasSessoes(Request $request, User $usuario): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $usuario->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();
    }
}
