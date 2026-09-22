<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O vínculo com o animal, que a fatia de leitura (RF47) não precisava e a
     * de emissão (RF46, T15) precisa: é por ele que o download do documento
     * confere a titularidade antes de entregar o arquivo.
     *
     * Nulo quando o animal deixar de existir, pela mesma razão do autor: um
     * documento que já circula fora da plataforma não pode ser invalidado por
     * remoção do cadastro — o que a verificação afirma continua vindo do
     * retrato gravado na própria linha.
     */
    public function up(): void
    {
        Schema::table('exportacoes', function (Blueprint $table) {
            $table->foreignId('animal_id')
                ->nullable()
                ->after('resumo')
                ->constrained('animais')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('exportacoes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('animal_id');
        });
    }
};
