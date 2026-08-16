<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AceitarConviteRequest;
use App\Models\Convite;
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

            $usuario->forceFill([
                'password' => $request->string('password')->toString(),
                'email_verified_at' => $usuario->email_verified_at ?? Carbon::now(),
                'ativado_em' => Carbon::now(),
            ])->save();

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
        $vinculo = $convite->usuario->prestadores
            ->firstWhere('id', $convite->prestador_id);

        return [
            'tipo' => $convite->tipo,
            'nome' => $convite->usuario->name,
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
