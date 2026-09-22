<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // RF45 — o registro das notificações emitidas é "com destinatário", e a
        // tabela nasceu em V02 sem ele: a coluna daquela tela só precisava de
        // data e situação. T17 é a primeira tela que mostra para onde a
        // mensagem foi, e a resposta não pode sair do cadastro do tutor.
        //
        // O endereço muda (RF06), e a notificação não: quem trocou de e-mail
        // depois de um lembrete não entregue precisa ver o endereço que falhou,
        // e não o novo — sem o retrato gravado na linha, a tela afirmaria que a
        // mensagem foi recusada num endereço que nunca a recebeu.
        Schema::table('notificacoes', function (Blueprint $table) {
            $table->string('destinatario')->nullable()->after('tutor_id');
        });

        // As linhas que já existem — as do cenário de demonstração — recebem o
        // endereço atual do tutor, que é o melhor retrato disponível para
        // notificações que nenhum motor de envio chegou a emitir.
        DB::table('notificacoes')->whereNull('destinatario')->update([
            'destinatario' => DB::raw(
                '(select users.email from tutores inner join users on users.id = tutores.user_id where tutores.id = notificacoes.tutor_id)',
            ),
        ]);

        Schema::table('notificacoes', function (Blueprint $table) {
            $table->string('destinatario')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notificacoes', function (Blueprint $table) {
            $table->dropColumn('destinatario');
        });
    }
};
