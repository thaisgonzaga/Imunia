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
        Schema::table('animais', function (Blueprint $table) {
            // O registro do óbito é ato do veterinário (RF22) e entra com V12,
            // fatia própria. As colunas nascem aqui pelo mesmo motivo que o
            // micro-chip nasceu na fatia de V03: V06 é a primeira tela que *lê*
            // o estado, e o briefing o exige desenhado — animal com óbito
            // registrado tem tarja permanente, ações de registro clínico
            // suprimidas e o histórico anunciado como encerrado. Sem coluna, a
            // tela teria um estado que nada pode produzir.
            //
            // A causa do óbito fica de fora: é campo do formulário de V12, e
            // ninguém a lê nesta fatia. A data, sim, é lida por três frases da
            // ficha, e o autor por uma delas.
            $table->date('obito_em')->nullable()->after('caracterizado_em');

            // RF22c — o registro não pode ser excluído, apenas retificado, e
            // quem o produziu responde por ele. É a mesma autoria preservada
            // dos demais registros clínicos (RN27), aqui no próprio animal
            // porque o óbito é atributo dele, e não um atendimento.
            $table->foreignId('obito_registrado_por_user_id')
                ->nullable()
                ->after('obito_em')
                ->constrained('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('animais', function (Blueprint $table) {
            $table->dropConstrainedForeignId('obito_registrado_por_user_id');
            $table->dropColumn('obito_em');
        });
    }
};
