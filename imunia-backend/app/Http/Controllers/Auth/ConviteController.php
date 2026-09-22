<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AceitarConviteRequest;
use App\Models\Convite;
use App\Models\Prestador;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ConviteController extends Controller
{
    /**
     * P07 — apresenta quem convidou e o que o convite concede, antes de pedir
     * a senha (RF09, RF14).
     */
    public function show(string $token): JsonResponse
    {
        $convite = Convite::localizar($token);

        if ($convite === null) {
            return response()->json(['situacao' => 'invalido'], 404);
        }

        if ($convite->foiAceito()) {
            return response()->json([
                'situacao' => 'aceito',
                'aceito_em' => $convite->aceito_em->toDateString(),
            ], 409);
        }

        $convite->load(['usuario', 'prestador', 'convidante']);

        if ($convite->expirou()) {
            return response()->json([
                'situacao' => 'expirado',
                'convite' => $this->representar($convite),
            ], 410);
        }

        return response()->json([
            'situacao' => 'valido',
            'convite' => $this->representar($convite),
        ]);
    }

    /**
     * Ativa o acesso: o titular define a própria senha e, com isso, confirma o
     * endereço — a ativação dispensa a verificação de RF05 (RF14c).
     */
    public function store(AceitarConviteRequest $request, string $token): JsonResponse
    {
        $convite = Convite::localizar($token);

        if ($convite === null || $convite->foiAceito() || $convite->expirou()) {
            return response()->json([
                'message' => 'Este convite não vale mais. Peça um novo a quem convidou você.',
            ], 410);
        }

        $usuario = DB::transaction(function () use ($convite, $request) {
            $usuario = $convite->usuario;

            $atributos = [
                'email_verified_at' => $usuario->email_verified_at ?? Carbon::now(),
                // RF14b — a data de ativação marca a primeira vez, e só ela.
                // Reescrevê-la a cada convite aceito apagaria justamente o que
                // distingue quem nunca ativou a conta de quem já usa o sistema.
                'ativado_em' => $usuario->ativado_em ?? Carbon::now(),
            ];

            // Quem já tem conta — o veterinário que RF09 permite vincular a
            // mais de um prestador — não redefine senha nem nome ao aceitar o
            // segundo convite: ele já tem os dois, e sobrescrevê-los tiraria
            // dele a senha com que entra no primeiro.
            if (filled($request->input('password'))) {
                $atributos['password'] = $request->string('password')->toString();
            }

            if (filled($request->input('nome'))) {
                $atributos['name'] = $request->string('nome')->toString();
            }

            $usuario->forceFill($atributos)->save();

            // Quem foi cadastrado pela clínica nunca viu os termos: quem
            // preencheu o formulário foi a recepção, e ninguém aceita em nome
            // de outro. A ativação é a primeira vez que o titular está diante
            // do documento, e é aqui que o aceite passa a existir.
            $tutor = $usuario->tutor;

            if ($convite->tipo === 'tutor' && $tutor !== null && ! $tutor->aceitouOsTermos()) {
                $tutor->registrarAceiteDosTermos();
            }

            $convite->forceFill(['aceito_em' => Carbon::now()])->save();

            return $usuario;
        });

        // A ativação já estabelece a sessão: quem acabou de definir a senha não
        // deve ser mandado de volta à tela de entrar para digitá-la de novo.
        Auth::login($usuario);
        $request->session()->regenerate();

        return response()->json([
            'usuario' => [
                'id' => $usuario->id,
                'nome' => $usuario->name,
                'email' => $usuario->email,
                'papeis' => $usuario->papeis(),
                'rota_inicial' => $usuario->rotaInicial(),
            ],
        ]);
    }

    /**
     * RF14a — o convite expira e pode ser reenviado. O pedido parte de quem
     * tem em mãos a ligação vencida, e a mensagem nova vai para o mesmo
     * endereço de sempre: nada é informado a quem apenas conhece o token.
     */
    public function reenviar(string $token): JsonResponse
    {
        $convite = Convite::localizar($token);

        if ($convite === null || $convite->foiAceito()) {
            return response()->json([
                'message' => 'Este convite não vale mais. Peça um novo a quem convidou você.',
            ], 410);
        }

        $convite->enviar($convite->reemitir());

        return response()->json([
            'message' => 'Pedimos um convite novo. A mensagem sai para o mesmo endereço.',
        ], 202);
    }

    /**
     * @return array<string, mixed>
     */
    private function representar(Convite $convite): array
    {
        // Filtra pelo papel, e não só pelo prestador: quem cadastrou a clínica
        // em P03 tem duas linhas no pivô para o mesmo estabelecimento, e a de
        // `admin_prestador` não carrega CRMV algum. Sem o filtro, P07 exibiria
        // "CRMV" em branco para quem tem os dois papéis.
        $vinculo = $convite->usuario->prestadores->first(
            fn (Prestador $candidato) => $candidato->id === $convite->prestador_id
                && $candidato->pivot->papel === 'veterinario',
        );

        $usuario = $convite->usuario;

        return [
            'tipo' => $convite->tipo,
            'nome' => $usuario->name ?: null,
            // Convite de veterinário não pede aceite: quem responde pelos
            // termos do estabelecimento é quem o cadastrou, em P03. O tutor
            // cadastrado pela clínica, esse sim, aceita aqui — é a primeira
            // vez que ele está diante do documento.
            'aceita_termos' => $convite->tipo === 'tutor'
                && $usuario->tutor !== null
                && ! $usuario->tutor->aceitouOsTermos(),
            // O que o convidado ainda precisa preencher. Quem já tem conta —
            // RF09 admite vínculo com mais de um prestador — não define senha
            // nem nome de novo: o convite só confirma o vínculo novo.
            'define_senha' => $usuario->ativado_em === null,
            'define_nome' => blank($usuario->name),
            // O endereço aparece por inteiro: quem tem o token em mãos é quem
            // recebeu a mensagem nele.
            'email' => $convite->usuario->email,
            'prestador' => [
                'nome' => $convite->prestador->nome,
                'municipio' => $convite->prestador->municipio,
                'uf' => $convite->prestador->uf,
            ],
            'convidante' => $convite->convidante?->name,
            'enviado_em' => $convite->created_at->format('d/m/Y'),
            'crmv' => $vinculo?->pivot->crmv,
            'crmv_uf' => $vinculo?->pivot->crmv_uf,
            'expira_em' => $convite->expira_em->format('d/m/Y'),
        ];
    }
}
