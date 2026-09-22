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
        // V05 — a caracterização do animal (RF19, RN18): observação clínica e
        // zootécnica, privativa do veterinário. As colunas não nasceram com a
        // tabela de propósito, e o comentário de lá o anunciava: entram com a
        // fatia que constrói o fluxo que as escreve.
        //
        // O peso não entra aqui, e a ausência é decisão: peso é medição datada
        // vinculada ao atendimento em que foi aferida (observação de RF19, já
        // materializada em `atendimentos.peso_aferido` pela fatia de V08).
        // Uma coluna de peso no cadastro recriaria o atributo sobrescrevível
        // que aquela observação manda evitar.
        Schema::table('animais', function (Blueprint $table) {
            $table->string('raca', 80)->nullable()->after('sexo');
            $table->string('pelagem', 60)->nullable()->after('raca');
            $table->enum('situacao_reprodutiva', ['inteiro', 'castrado'])
                ->nullable()
                ->after('pelagem');

            // RF19c — toda alteração registra autor, data e hora. A data já
            // existia (`caracterizado_em`, RN17); o autor entra agora, porque
            // até esta fatia ninguém escrevia caracterização.
            $table->foreignId('caracterizado_por_user_id')
                ->nullable()
                ->after('caracterizado_em')
                ->constrained('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('animais', function (Blueprint $table) {
            $table->dropConstrainedForeignId('caracterizado_por_user_id');
            $table->dropColumn(['raca', 'pelagem', 'situacao_reprodutiva']);
        });
    }
};
