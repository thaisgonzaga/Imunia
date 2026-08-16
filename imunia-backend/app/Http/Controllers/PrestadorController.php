<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePrestadorRequest;
use App\Models\Prestador;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PrestadorController extends Controller
{
    public function store(StorePrestadorRequest $request): JsonResponse
    {
        $dados = $request->validated();

        [$prestador, $usuario] = DB::transaction(function () use ($dados) {
            $usuario = User::create([
                'name' => $dados['responsavel_tecnico_nome'],
                'email' => $dados['email'],
                'password' => $dados['password'],
            ]);

            // A senha é definida pelo próprio administrador no cadastro: a
            // conta nasce ativada, sem convite de RF14 a aceitar.
            $usuario->forceFill(['ativado_em' => now()])->save();

            $prestador = Prestador::create([
                'tipo' => $dados['tipo'],
                'nome' => $dados['nome'],
                'documento' => $dados['documento'],
                'telefone' => $dados['telefone'],
                'endereco' => $dados['endereco'],
                'municipio' => $dados['municipio'],
                'uf' => $dados['uf'],
                'responsavel_tecnico_nome' => $dados['responsavel_tecnico_nome'],
                'responsavel_tecnico_crmv' => $dados['responsavel_tecnico_crmv'],
                'responsavel_tecnico_crmv_uf' => $dados['responsavel_tecnico_crmv_uf'],
            ]);

            $prestador->usuarios()->attach($usuario->id, [
                'papel' => 'admin_prestador',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $prestador->usuarios()->attach($usuario->id, [
                'papel' => 'veterinario',
                'crmv' => $dados['responsavel_tecnico_crmv'],
                'crmv_uf' => $dados['responsavel_tecnico_crmv_uf'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [$prestador, $usuario];
        });

        // Sem endereço confirmado a conta não recebe comunicação alguma (RN42),
        // e a tarja de pendência acompanha o administrador até que confirme.
        $usuario->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'Estabelecimento cadastrado com sucesso.',
            'prestador' => [
                'id' => $prestador->id,
                'nome' => $prestador->nome,
                'tipo' => $prestador->tipo,
                'municipio' => $prestador->municipio,
                'uf' => $prestador->uf,
            ],
        ], 201);
    }
}
