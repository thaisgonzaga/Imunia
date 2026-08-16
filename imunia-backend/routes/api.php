<?php

use App\Http\Controllers\Auth\ConviteController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\PrestadorController;
use App\Http\Controllers\TutorController;
use App\Http\Controllers\VerificacaoDocumentoController;
use Illuminate\Support\Facades\Route;

Route::post('/prestadores', [PrestadorController::class, 'store']);
Route::post('/tutores', [TutorController::class, 'store']);

// P09 — verificação pública do documento exportado (RF47). Única rota aberta
// do sistema, ressalva expressa de RN01.
Route::get('/documentos/{codigo}', [VerificacaoDocumentoController::class, 'show']);

// P02 — sessão por cookie httpOnly (RF01, RF02, RNF08).
Route::post('/sessao', [SessionController::class, 'store']);
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/sessao', [SessionController::class, 'show']);
    Route::delete('/sessao', [SessionController::class, 'destroy']);
});

// P05 e P06 — redefinição de senha esquecida (RF03).
Route::post('/senha/recuperar', [PasswordResetController::class, 'store']);
Route::get('/senha/redefinir/{token}', [PasswordResetController::class, 'show']);
Route::post('/senha/redefinir', [PasswordResetController::class, 'update']);

// P08 — confirmação do endereço de e-mail (RF05).
Route::post('/email/verificar/{token}', [EmailVerificationController::class, 'update']);
Route::post('/email/reenviar', [EmailVerificationController::class, 'store']);

// P07 — ativação de acesso por convite (RF09, RF14).
Route::get('/convites/{token}', [ConviteController::class, 'show']);
Route::post('/convites/{token}', [ConviteController::class, 'store']);
Route::post('/convites/{token}/reenviar', [ConviteController::class, 'reenviar'])
    ->middleware('throttle:3,10');
