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
        Schema::table('tutores', function (Blueprint $table) {
            // A data do aceite diz quando; a versão diz o quê. Sem ela, publicar
            // um texto novo reescreve retroativamente o que todo mundo aceitou.
            //
            // Fica nula para os cadastros anteriores a esta coluna: eles
            // aceitaram um documento que não existia, e inventar uma versão
            // para eles seria pior do que registrar que não se sabe qual foi.
            $table->string('termos_versao', 10)->nullable()->after('termos_aceitos_em');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tutores', function (Blueprint $table) {
            $table->dropColumn('termos_versao');
        });
    }
};
