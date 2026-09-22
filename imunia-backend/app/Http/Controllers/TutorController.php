<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTutorRequest;
use App\Models\Tutor;
use App\Models\User;
use App\Support\DocumentosLegais;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class TutorController extends Controller
{
    public function store(StoreTutorRequest $request): JsonResponse
    {
        $dados = $request->validated();

        [$tutor, $usuario] = DB::transaction(function () use ($dados) {
            $usuario = User::create([
                'name' => $dados['nome'],
                'email' => $dados['email'],
                'password' => $dados['password'],
            ]);

            // No autocadastro a senha é definida pelo próprio titular, de modo
            // que a conta já nasce ativada — não há convite de RF14 a aceitar.
            $usuario->forceFill(['ativado_em' => now()])->save();

            $tutor = Tutor::create([
                'user_id' => $usuario->id,
                'nome' => $dados['nome'],
                'cpf' => $dados['cpf'],
                'termos_aceitos_em' => now(),
                // A versão vem da constante, e não do formulário: o cliente
                // declara que aceita, o servidor determina o que estava no ar.
                'termos_versao' => DocumentosLegais::VERSAO,
            ]);

            return [$tutor, $usuario];
        });

        // O autocadastro dispara imediatamente a verificação de endereço
        // (RF12c), sem a qual o tutor não recebe lembrete algum (RN42).
        $usuario->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'Conta criada com sucesso.',
            'tutor' => [
                'id' => $tutor->id,
                'nome' => $tutor->nome,
            ],
        ], 201);
    }
}
