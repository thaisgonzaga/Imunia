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
        // RF43 — o lembrete de retorno é a quarta espécie de notificação, e a
        // primeira que não trata de vacina. O valor entra no fim da lista: o
        // TiDB de produção só altera ENUM acrescentando membros, nunca
        // reordenando. `aviso_na_data` fica, embora o motor não o emita mais
        // (RN44, 01/10/2026): o cenário de demonstração ainda o grava, e o
        // histórico do tutor precisa continuar sabendo nomeá-lo.
        Schema::table('notificacoes', function (Blueprint $table) {
            $table->enum('tipo', ['aviso_previo', 'aviso_na_data', 'alerta_atraso', 'lembrete_retorno'])->change();
        });

        // O atendimento que marcou o retorno. É dele que o histórico do tutor
        // (T17) tira a finalidade, que faz para o retorno o papel que a vacina
        // faz para a dose: sem ela, a linha diria "Lembrete de retorno" e não
        // diria de quê.
        //
        // Não entra em índice único. O evento de RN43 é o retorno do animal
        // naquela data, e não o atendimento que o marcou: a retificação (RF33)
        // cria outro atendimento com a mesma data, e a garantia por
        // `atendimento_id` mandaria um segundo lembrete do mesmo retorno. A
        // chave que impede a segunda emissão continua sendo a de 17/08, que
        // aqui não alcança, por causa do imunobiológico nulo — e o motor a
        // supre (ver `LembretesAoTutorService`).
        Schema::table('notificacoes', function (Blueprint $table) {
            $table->foreignId('atendimento_id')->nullable()->after('imunobiologico_id')->constrained('atendimentos');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notificacoes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('atendimento_id');
        });

        // O ENUM antigo não comporta as linhas de retorno.
        DB::table('notificacoes')->where('tipo', 'lembrete_retorno')->delete();

        Schema::table('notificacoes', function (Blueprint $table) {
            $table->enum('tipo', ['aviso_previo', 'aviso_na_data', 'alerta_atraso'])->change();
        });
    }
};
