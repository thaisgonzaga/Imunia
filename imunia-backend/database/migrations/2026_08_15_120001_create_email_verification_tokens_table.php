<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ligações de confirmação de endereço (RF05). Guardadas como resumo, de uso
     * único e com prazo de validade, na mesma disciplina exigida pela RN04 para
     * as ligações de redefinição de senha.
     */
    public function up(): void
    {
        Schema::create('email_verification_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // O endereço fica registrado no token porque a troca de e-mail
            // reinicia o ciclo de verificação (RF06a): a ligação antiga não
            // pode confirmar o endereço novo.
            $table->string('email');
            $table->string('token', 64)->unique();
            $table->timestamp('expira_em');
            $table->timestamp('usado_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_tokens');
    }
};
