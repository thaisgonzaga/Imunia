<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RedefinirSenhaRequest;
use App\Http\Requests\Auth\SolicitarRedefinicaoRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    /**
     * P05 — envia a ligação de redefinição. A resposta é a mesma exista ou não
     * conta para o endereço informado (RF03): é o que impede que a tela sirva
     * de consulta a quais endereços estão cadastrados.
     */
    public function store(SolicitarRedefinicaoRequest $request): JsonResponse
    {
        Password::sendResetLink(['email' => $request->string('email')->toString()]);

        return response()->json([
            'message' => 'Se houver uma conta com este endereço, enviaremos as instruções em instantes. Verifique também a caixa de spam.',
        ], 202);
    }

    /**
     * P06 — informa em que estado está a ligação antes de desenhar o formulário,
     * para que a tela de senha nova só apareça quando puder ser concluída.
     */
    public function show(Request $request, string $token): JsonResponse
    {
        $email = $request->string('email')->toString();

        $registro = DB::table(config('auth.passwords.users.table'))
            ->where('email', $email)
            ->first();

        // Sem registro, não há como distinguir uma ligação já consumida de uma
        // inventada — as duas somem da tabela do mesmo jeito. A tela usa o
        // texto de ligação já utilizada, que é verdadeiro no caso legítimo e
        // não revela nada no ilegítimo.
        if ($registro === null || ! Hash::check($token, $registro->token)) {
            return response()->json(['situacao' => 'utilizada']);
        }

        $expiraEm = Carbon::parse($registro->created_at)
            ->addMinutes((int) config('auth.passwords.users.expire'));

        if ($expiraEm->isPast()) {
            return response()->json(['situacao' => 'expirada']);
        }

        return response()->json(['situacao' => 'valida']);
    }

    /**
     * Conclui a redefinição e encerra as demais sessões do usuário (RF03b).
     */
    public function update(RedefinirSenhaRequest $request): JsonResponse
    {
        $situacao = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $usuario, string $senha) {
                $usuario->forceFill([
                    'password' => $senha,
                    'remember_token' => Str::random(60),
                    'ativado_em' => $usuario->ativado_em ?? Carbon::now(),
                ])->save();

                $this->encerrarSessoes($usuario);
            },
        );

        if ($situacao !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Esta ligação não vale mais. Peça novas instruções para definir a senha.',
                'situacao' => $situacao === Password::INVALID_TOKEN ? 'expirada' : 'utilizada',
            ], 422);
        }

        return response()->json([
            'message' => 'Senha alterada. Entre com a nova senha.',
        ]);
    }

    /**
     * A sessão vive na base (SESSION_DRIVER=database), de modo que apagar as
     * linhas do usuário derruba de fato os outros dispositivos — inclusive
     * aquele em poder de quem porventura tenha obtido a senha antiga.
     */
    private function encerrarSessoes(User $usuario): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $usuario->id)
            ->delete();
    }
}
