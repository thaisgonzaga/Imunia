<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * T09, RF29 — o esquema original de `vacinacoes` foi escrito para a
     * aplicação de origem profissional, onde imunobiológico e data são
     * sabidos por quem aplicou. O histórico pregresso é o caso contrário: o
     * tutor lança o que tem em mãos, e o que ele não sabe não pode ser
     * exigido dele — é justamente a informação que se perderia se o sistema
     * cobrasse precisão (P5).
     *
     * A obrigatoriedade não desaparece: ela sai do esquema e passa para a
     * FormRequest de cada fluxo de escrita, como já previa o comentário da
     * migration de criação. A origem profissional continua exigindo os dois
     * campos; o pregresso exige ao menos um dos dois, porque um registro sem
     * vacina e sem data não afirma fato algum.
     */
    public function up(): void
    {
        Schema::table('vacinacoes', function (Blueprint $table) {
            // "Não sei informar" é resposta legítima no catálogo (T09): o
            // tutor lembra que houve uma aplicação, não qual foi.
            $table->unsignedBigInteger('imunobiologico_id')->nullable()->change();

            // Sem data, o calendário não calcula nada (RF26) — e o registro
            // continua valendo como lembrança do que já foi aplicado, que é
            // mais do que o papel perdido oferecia.
            $table->dateTime('aplicado_em')->nullable()->change();

            // Onde a aplicação foi feita, em texto livre: "campanha pública
            // de vacinação", "clínica no bairro antigo". Não é prestador —
            // prestador é entidade cadastrada, com responsabilidade técnica
            // (RN25) — e por isso não vira `prestador_id` nunca.
            $table->string('local_aplicacao')->nullable()->after('via_administracao');
        });
    }

    public function down(): void
    {
        Schema::table('vacinacoes', function (Blueprint $table) {
            $table->dropColumn('local_aplicacao');
            $table->dateTime('aplicado_em')->nullable(false)->change();
            $table->unsignedBigInteger('imunobiologico_id')->nullable(false)->change();
        });
    }
};
