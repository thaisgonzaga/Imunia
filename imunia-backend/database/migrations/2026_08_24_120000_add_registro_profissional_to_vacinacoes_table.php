<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * As colunas que a fatia V07 precisou e o esquema não tinha.
     *
     * `vacinacoes` nasceu da carteira (T05) e do lançamento pregresso (T09) —
     * duas fases em que a tabela só era lida, ou escrita pelo tutor. O registro
     * profissional de RF25 é a primeira escrita clínica de veterinário do
     * sistema, e traz consigo cinco dados que nenhuma daquelas fases precisava.
     */
    public function up(): void
    {
        Schema::table('vacinacoes', function (Blueprint $table) {
            // RN27 — a retificação é privativa do autor do registro.
            // `atendimentos` guarda `profissional_user_id` desde que existe;
            // aqui havia apenas o nome em texto, que colide entre homônimos e
            // muda quando a pessoa corrige o próprio cadastro. Sem esta coluna,
            // V09 não teria como identificar o autor de uma vacinação.
            $table->foreignId('aplicador_user_id')->nullable()->after('aplicador_crmv')->constrained('users');

            // RF27b — a conduta divergente é registrada "com justificativa e
            // autoria", e divergência só é legível contra o que foi sugerido. A
            // ordem calculada não é reconstruível depois: ela deriva da
            // contagem de doses do grupo, e um pregresso lançado pelo tutor no
            // mês seguinte muda essa contagem retroativamente.
            $table->unsignedSmallInteger('ordem_dose_sugerida')->nullable()->after('ordem_dose');

            $table->text('justificativa_conduta')->nullable()->after('ordem_dose_sugerida');

            $table->text('observacao')->nullable()->after('justificativa_conduta');

            // O "local anatômico" de V07 — e **não** `local_aplicacao`, que
            // esta mesma tabela já usa para outra coisa: o lugar onde a
            // aplicação pregressa ocorreu ("campanha pública", "clínica no
            // bairro antigo"). Escrever "escápula direita" naquela coluna poria
            // dois significados numa coluna só, para sempre.
            $table->string('sitio_anatomico')->nullable()->after('via_administracao');
        });
    }

    public function down(): void
    {
        Schema::table('vacinacoes', function (Blueprint $table) {
            $table->dropForeign(['aplicador_user_id']);
            $table->dropColumn([
                'aplicador_user_id',
                'ordem_dose_sugerida',
                'justificativa_conduta',
                'observacao',
                'sitio_anatomico',
            ]);
        });
    }
};
