<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A periodicidade da revacinação passa a poder faltar (A04).
 *
 * Nulo é "dose única, sem revacinação" — o agendamento que uma clínica declara
 * para um imunobiológico próprio que não se repete. Não é falta de dado: é a
 * resposta, e o motor a distingue de "sem data suficiente para calcular", que
 * na carteira do tutor se leria como falha do sistema.
 *
 * As linhas versionadas da plataforma continuam exigindo o valor, mas na
 * `SalvarParametrosProtocoloRequest` e não no esquema — RN35 não conhece vacina
 * essencial sem revacinação, e é a diretriz que responde por isso, não a
 * coluna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('protocolos_vacinais', function (Blueprint $table) {
            // O tipo é repetido de propósito: `->change()` redefine a coluna
            // inteira, e o atributo não repetido se perde.
            $table->unsignedSmallInteger('periodicidade_revacinacao_meses')->nullable()->change();
        });
    }

    public function down(): void
    {
        // A descida não tem como devolver o que a nulidade significava. Doze
        // meses é o valor menos surpreendente para uma coluna que volta a ser
        // obrigatória, e o agendamento que dizia "não repete" passa a dizer
        // "repete anualmente" — perda declarada, não silenciosa.
        DB::table('protocolos_vacinais')
            ->whereNull('periodicidade_revacinacao_meses')
            ->update(['periodicidade_revacinacao_meses' => 12]);

        Schema::table('protocolos_vacinais', function (Blueprint $table) {
            $table->unsignedSmallInteger('periodicidade_revacinacao_meses')->nullable(false)->change();
        });
    }
};
