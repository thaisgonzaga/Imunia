<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // V10 — o campo opcional de mensagem do pedido (RF38). Curta e uma só,
        // porque o pedido não é canal de conversa: é uma linha de contexto que
        // ajuda o tutor a reconhecer de onde o pedido veio ("Atendimento do
        // Théo em 12/11"), lida por ele em T13 ao decidir.
        Schema::table('solicitacoes_acesso', function (Blueprint $table) {
            $table->string('mensagem', 280)->nullable()->after('solicitada_por_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('solicitacoes_acesso', function (Blueprint $table) {
            $table->dropColumn('mensagem');
        });
    }
};
