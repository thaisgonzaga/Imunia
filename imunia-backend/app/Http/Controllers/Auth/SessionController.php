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
     */
    public function store(LoginRequest $request): JsonResponse
    {
        $request->autenticar();

        /** @var User $usuario */
        $usuario = $request->user();

        return response()->json([
            'usuario' => $this->representar($usuario),
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
    private function representar(User $usuario): array
    {
        return [
            'id' => $usuario->id,
            'nome' => $usuario->name,
            'email' => $usuario->email,
            'email_verificado' => $usuario->hasVerifiedEmail(),
            'papeis' => $usuario->papeis(),
            'rota_inicial' => $usuario->rotaInicial(),
        ];
    }
}
