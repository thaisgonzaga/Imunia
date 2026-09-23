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
 * A tela é pública e cadastra estabelecimento e conta administradora no mesmo
 * ato — inclusive quando quem a preenche já tem sessão aberta. O cadastro não
 * lê a sessão de quem envia: quem abre um estabelecimento aqui pode não ser
 * quem está no navegador, e tomar a conta corrente por destino do vínculo
 * carimbaria o CRMV alheio num papel da conta de quem apenas preencheu o
 * formulário.
 *
 * O caminho de quem quer acrescentar um vínculo à conta que já tem é outro, e
 * é o de sempre: o convite de A03, que RF09 e RF14 desenham e que reaproveita
 * a conta de quem já está no Imunia.
 */
class PrestadorController extends Controller
{
    public function store(StorePrestadorRequest $request): JsonResponse
    {
        $dados = $request->validated();

        [$prestador, $usuario] = DB::transaction(function () use ($dados) {
            // O nome do responsável técnico batiza a conta que nasce aqui: é
            // ele quem responde pelo estabelecimento e quem assinará os
            // registros clínicos enquanto a equipe não existir.
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
