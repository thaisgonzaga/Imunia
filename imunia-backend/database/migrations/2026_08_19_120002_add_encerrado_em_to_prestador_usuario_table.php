<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O encerramento do vínculo entre profissional e prestador (RF10).
 *
 * A coluna existe porque o encerramento **não** pode ser um `detach`. RF10
 * separa duas coisas que a remoção da linha juntaria: o acesso, que cessa
 * imediatamente, e a autoria, que permanece. Apagar o vínculo apagaria com ele
 * o CRMV sob o qual cada registro foi assinado, e é justamente esse dado que
 * RF10b e RF10c mandam preservar — os registros anteriores continuam exibindo
 * nome e CRMV do autor, e a autoria não é removida nem anonimizada.
 *
 * Guardar a data, e não só um sinalizador, é o que permite a A03 dizer
 * "encerrado em 14/05/2026" na linha esmaecida: quem lê a equipe hoje precisa
 * saber desde quando aquela pessoa não atende mais ali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prestador_usuario', function (Blueprint $table) {
            $table->timestamp('encerrado_em')->nullable()->after('crmv_uf');
        });
    }

    public function down(): void
    {
        Schema::table('prestador_usuario', function (Blueprint $table) {
            $table->dropColumn('encerrado_em');
        });
    }
};
