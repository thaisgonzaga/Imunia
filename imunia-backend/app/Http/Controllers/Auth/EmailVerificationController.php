<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ReenviarConfirmacaoRequest;
use App\Models\EmailVerificationToken;
use App\Models\User;
use App\Support\EnderecoDeEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EmailVerificationController extends Controller
{
    /**
     * P08 — confirma a titularidade do endereço pela ligação recebida (RF05).
     */
    public function update(string $token): JsonResponse
    {
        $registro = EmailVerificationToken::localizar($token);

        if ($registro === null) {
            return response()->json(['situacao' => 'invalida'], 404);
        }

        if ($registro->foiUsado()) {
            return response()->json(['situacao' => 'confirmado']);
        }

        if ($registro->expirou()) {
            return response()->json([
                'situacao' => 'expirada',
                'email_mascarado' => EnderecoDeEmail::mascarar($registro->email),
            ], 410);
        }

        DB::transaction(function () use ($registro) {
            $usuario = $registro->usuario;

            // O token confirma o endereço para o qual foi emitido. Se o usuário
            // trocou de e-mail nesse meio-tempo, a ligação antiga não vale mais
            // (RF06a).
            if ($usuario->email === $registro->email) {
                $usuario->forceFill(['email_verified_at' => Carbon::now()])->save();
            }

            $registro->forceFill(['usado_em' => Carbon::now()])->save();
        });

        if ($registro->usuario->email !== $registro->email) {
            return response()->json(['situacao' => 'invalida'], 404);
        }

        return response()->json(['situacao' => 'confirmado']);
    }

    /**
     * Reenvia a confirmação. Como em P05, a resposta é a mesma exista ou não
     * conta para o endereço informado; a máscara devolvida é derivada do que o
     * próprio usuário digitou, e por isso não confirma cadastro algum.
     */
    public function store(ReenviarConfirmacaoRequest $request): JsonResponse
    {
        $email = $request->string('email')->toString();
        $usuario = User::firstWhere('email', $email);

        if ($usuario !== null && ! $usuario->hasVerifiedEmail()) {
            $usuario->sendEmailVerificationNotification();
        }

        return response()->json([
            'message' => 'Se o endereço estiver aguardando confirmação, a mensagem sai em instantes.',
            'email_mascarado' => EnderecoDeEmail::mascarar($email),
        ], 202);
    }
}
