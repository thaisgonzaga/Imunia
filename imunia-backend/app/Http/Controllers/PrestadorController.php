<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePrestadorRequest;
use App\Models\Prestador;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * P04 — cadastrar prestador (RF07).
 *
 * Duas origens, um resultado. O visitante anônimo cria conta e estabelecimento
 * no mesmo ato. Quem já está no Imunia cadastra o estabelecimento na conta que
 * já tem — a tutora que abre o próprio consultório não precisa de um segundo
 * endereço de correio para isso, porque os papéis se somam na mesma conta
 * (RN05) e o convite de A03 há tempos faz o caminho inverso.
 *
 * Mais de um estabelecimento por conta é previsto, e não acidente: RF09 admite
 * vínculo simultâneo com vários prestadores, e o contexto ativo é que decide
 * onde a pessoa está trabalhando (RF09b). O que não se repete é a conta.
 */
class PrestadorController extends Controller
{
    public function store(StorePrestadorRequest $request): JsonResponse
    {
        $dados = $request->validated();
        $criaConta = $request->criaConta();
        $autenticado = $request->user();

        [$prestador, $usuario] = DB::transaction(function () use ($dados, $criaConta, $autenticado) {
            // O nome do responsável técnico batiza a conta nova, e só ela: na
            // conta que já existe o nome é o da pessoa, escrito por ela, e é
            // ele que assina cada registro clínico que ela já produziu. O
            // cadastro do estabelecimento não rebatiza ninguém.
            $usuario = $criaConta
                ? User::create([
                    'name' => $dados['responsavel_tecnico_nome'],
                    'email' => $dados['email'],
                    'password' => $dados['password'],
                ])
                : $autenticado;

            // A senha é definida pelo próprio administrador no cadastro: a
            // conta nasce ativada, sem convite de RF14 a aceitar.
            if ($criaConta) {
                $usuario->forceFill(['ativado_em' => now()])->save();
            }

            $prestador = Prestador::create([
                'tipo' => $dados['tipo'],
                'nome' => $dados['nome'],
                'cnpj' => $dados['cnpj'],
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
        // Quem já confirmou o endereço não é mandado confirmá-lo de novo: a
        // mensagem repetida sugeriria que o cadastro suspendeu algo, e não
        // suspendeu nada.
        if (! $usuario->hasVerifiedEmail()) {
            $usuario->sendEmailVerificationNotification();
        }

        return response()->json([
            'message' => 'Estabelecimento cadastrado com sucesso.',
            // A tela de sucesso é outra conforme o caso: quem acabou de criar a
            // conta ainda tem de confirmar o endereço antes de entrar; quem já
            // estava dentro segue direto para a administração do que cadastrou.
            'conta_nova' => $criaConta,
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
