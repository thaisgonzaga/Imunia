<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Convites de ativação: do tutor cadastrado por um prestador (RF14) e do
     * médico-veterinário convidado por um administrador (RF09). Nos dois casos
     * a conta e o vínculo já existem no momento da emissão; o convite apenas
     * entrega ao titular o direito de definir a própria senha.
     */
    public function up(): void
    {
        Schema::create('convites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('prestador_id')->constrained('prestadores')->cascadeOnDelete();
            // Quem emitiu. Nulo quando a emissão foi automática, sem um
            // administrador nomeável por trás.
            $table->foreignId('convidado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('tipo', ['tutor', 'veterinario']);
            $table->string('token', 64)->unique();
            $table->timestamp('expira_em');
            $table->timestamp('aceito_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('convites');
    }
};
