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
            // V12 — a fatia que escreve o óbito (RF22). `obito_em` e o autor
            // nasceram com V06, que é quem lê o estado; a causa nasce agora
            // porque é campo do formulário desta tela, opcional por desenho:
            // nem todo óbito tem causa conhecida, e exigi-la produziria
            // "indeterminada" digitado — ruído, não registro.
            $table->string('obito_causa')->nullable()->after('obito_em');

            // O âmbito e a inscrição de quem registrou, como todo registro
            // clínico (RF31c, RN22): a retificação é privativa do autor dentro
            // do prestador que produziu o registro (RN27), e a linha do tempo
            // declara a procedência (P1). A inscrição é copiada do vínculo no
            // ato porque o vínculo pode encerrar-se depois, e o registro
            // continua respondendo por quem o assinou.
            $table->foreignId('obito_prestador_id')
                ->nullable()
                ->after('obito_registrado_por_user_id')
                ->constrained('prestadores');
            $table->string('obito_registrado_crmv')->nullable()->after('obito_prestador_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('animais', function (Blueprint $table) {
            $table->dropColumn('obito_registrado_crmv');
            $table->dropConstrainedForeignId('obito_prestador_id');
            $table->dropColumn('obito_causa');
        });
    }
};
