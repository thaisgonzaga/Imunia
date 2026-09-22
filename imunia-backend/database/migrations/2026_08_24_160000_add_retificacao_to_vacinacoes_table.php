<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * V09 — as duas colunas que `atendimentos` tem desde que nasceu e
     * `vacinacoes` não tinha (RF33, RN26).
     *
     * A assimetria é histórica, não conceitual: `atendimentos` foi desenhada
     * junto de T08, que já lia o encadeamento entre original e retificação;
     * `vacinacoes` nasceu da carteira (T05) e do lançamento pregresso (T09),
     * duas fases em que a correção não estava em questão. RN26 nunca distinguiu
     * os dois — "registro clínico confirmado é imutável, admite apenas
     * retificação vinculada" vale para a aplicação de vacina exatamente como
     * vale para o prontuário, e RF33 fala de "registro clínico", não de
     * atendimento.
     *
     * O nome das colunas repete o de `atendimentos` de propósito: são a mesma
     * regra, e quem ler as duas tabelas deve reconhecê-la sem tradução.
     */
    public function up(): void
    {
        Schema::table('vacinacoes', function (Blueprint $table) {
            // Nulo no registro original; preenchido na retificação, que aponta
            // para o que corrige. O encadeamento é lido nos dois sentidos a
            // partir daqui (RF33b), como em `atendimentos`.
            $table->foreignId('retifica_vacinacao_id')
                ->nullable()
                ->after('animal_id')
                ->constrained('vacinacoes')

                // Um registro tem no máximo uma retificação, e a regra é
                // estrutural porque a alternativa é uma árvore de versões: duas
                // correções do mesmo original, nenhuma das duas sabendo da
                // outra, e nenhuma resposta possível à pergunta "qual delas
                // vale?". Com o índice, a cadeia é uma linha — corrigir uma
                // correção é criar a terceira versão, jamais uma segunda
                // segunda. É também o que fecha a porta ao clique duplo, que
                // deixaria dois prontuários permanentes onde houve uma correção.
                ->unique();

            // RF33 — a correção declara por que existe, e o motivo é visível ao
            // tutor junto das duas versões. Sem ele, uma retificação seria
            // indistinguível de um segundo lançamento com dados diferentes.
            $table->text('motivo_retificacao')->nullable()->after('observacao');
        });

        // A mesma unicidade em `atendimentos`, que tem a coluna desde T08 e
        // nunca teve o índice — até esta fatia não havia caminho algum que a
        // escrevesse, e a ausência não custava nada. Agora custa.
        Schema::table('atendimentos', function (Blueprint $table) {
            $table->unique('retifica_atendimento_id');
        });
    }

    public function down(): void
    {
        Schema::table('atendimentos', function (Blueprint $table) {
            $table->dropUnique(['retifica_atendimento_id']);
        });

        Schema::table('vacinacoes', function (Blueprint $table) {
            $table->dropForeign(['retifica_vacinacao_id']);
            $table->dropColumn(['retifica_vacinacao_id', 'motivo_retificacao']);
        });
    }
};
