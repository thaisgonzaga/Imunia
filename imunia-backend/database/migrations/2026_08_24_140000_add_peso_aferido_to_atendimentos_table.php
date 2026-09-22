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
        // V08 — o peso aferido na consulta. É a primeira vez que peso entra no
        // esquema, e entra aqui, e não em `animais`, pela razão que os
        // requisitos registram na observação de modelagem de RF19: peso não é
        // atributo estável do animal, é **medição**, e o valor clínico está na
        // série. Guardá-lo como campo sobrescrevível no cadastro destruiria a
        // medição anterior a cada consulta — e, mais grave, seria escrita sobre
        // registro clínico, que RN26 não admite.
        //
        // A medição não precisa de tabela própria porque já tem a sua data e a
        // sua autoria: são as do atendimento em que foi aferida. Uma tabela de
        // pesos com `atendimento_id` repetiria essas duas colunas para guardar
        // um número, e abriria a porta para peso sem atendimento — medição sem
        // quem a tomou.
        //
        // Nulo quando não houve pesagem: um zero afirmaria uma balança que
        // ninguém usou.
        Schema::table('atendimentos', function (Blueprint $table) {
            $table->decimal('peso_kg', 5, 2)->nullable()->after('conduta');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('atendimentos', function (Blueprint $table) {
            $table->dropColumn('peso_kg');
        });
    }
};
