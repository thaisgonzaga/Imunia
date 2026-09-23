<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SessionController extends Controller
{
    /**
     * P02 — estabelece a sessão por cookie httpOnly (RF01, RNF08).
     *
     * A entrada de P02 tem duas portas, a do tutor e a do profissional, e é
     * por isso que o pedido pode trazer `papel` (RF01a): a mesma conta atende aos
     * dois (RN05), e sem a escolha a precedência de `rotaInicial()` mandaria
     * sempre para o ambiente clínico quem também cuida dos próprios animais.
     */
    public function store(LoginRequest $request): JsonResponse
    {
        $request->autenticar();

        /** @var User $usuario */
        $usuario = $request->user();

        $papel = $request->input('papel');

        return response()->json([
            'usuario' => $this->representar($usuario, is_string($papel) ? $papel : null),
        ]);
    }

    /**
     * Dados do usuário da sessão corrente, usados pelo SPA para decidir a rota
     * inicial e para desenhar as tarjas de e-mail não confirmado (RF05).
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        return response()->json([
            'usuario' => $this->representar($usuario),
        ]);
    }

    /**
     * RF02 — encerra a sessão e invalida o cookie correspondente.
     */
    public function destroy(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Sessão encerrada.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function representar(User $usuario, ?string $papelPedido = null): array
    {
        $rotaDoPapel = $papelPedido === null ? null : $usuario->rotaInicialPara($papelPedido);

        return [
            'id' => $usuario->id,
            'nome' => $usuario->name,
            'email' => $usuario->email,
            'email_verificado' => $usuario->hasVerifiedEmail(),
            'papeis' => $usuario->papeis(),
            // Sem papel pedido, ou com papel que a conta tem, é o painel de
            // sempre. Com papel que ela não tem, a rota cai na precedência —
            // não para levar a pessoa até lá, mas para que a tela tenha uma
            // saída a oferecer a quem decidir não criar o cadastro que falta.
            'rota_inicial' => $rotaDoPapel ?? $usuario->rotaInicial(),
            // O papel que a porta prometia e a conta não tem. Não é erro: a
            // credencial estava certa, e o que falta é um cadastro que a
            // própria pessoa pode criar ali mesmo, sem segunda conta e sem
            // segundo endereço de correio (RN05).
            'papel_ausente' => $papelPedido !== null && $rotaDoPapel === null ? $papelPedido : null,
        ];
    }
}
