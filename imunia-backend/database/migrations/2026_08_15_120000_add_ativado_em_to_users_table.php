<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca o momento em que o titular definiu a própria senha. Fica nulo nas
     * contas criadas por um prestador e ainda não ativadas pelo convite de
     * RF14 — é o que permite sinalizar o tutor não ativado nas telas do
     * prestador (RF14b).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('ativado_em')->nullable()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ativado_em');
        });
    }
};
