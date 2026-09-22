<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A linha de parâmetros passa a poder não pertencer a versão alguma (A04).
 *
 * O agendamento próprio de uma clínica não é diretriz: não se publica, não se
 * encerra e não entra em rascunho. A nulidade não é conveniência — é o que
 * impede três coisas de uma vez, e todas por construção, sem uma única
 * verificação que alguém possa esquecer, porque `$versao->protocolos` é um
 * `hasMany` que simplesmente não alcança a linha nula:
 *
 * 1. `criarRascunhoAPartirDaVigente()` não copia o acervo privado de cada
 *    clínica para dentro do rascunho da plataforma;
 * 2. `incoerencias()` não confere essas linhas — uma clínica que digitou
 *    bobagem não pode impedir a plataforma de publicar uma revisão;
 * 3. `publicar()` não as encerra. Fosse a linha privada filha da versão
 *    vigente, `protocoloVigente()` — que filtra `situacao = vigente` —
 *    devolveria nulo para toda vacina própria de toda clínica no dia em que a
 *    plataforma publicasse a revisão seguinte da WSAVA, e todo lembrete delas
 *    cessaria em silêncio.
 *
 * O índice único que entra junto faltava desde sempre: o `updateOrCreate` de
 * `ProtocolosVacinaisController::salvarParametros()` chaveia por este par e não
 * tinha o que o sustentasse. Ele fecha essa corrida e, porque o MySQL trata
 * NULLs como distintos entre si, continua permitindo as N linhas sem versão do
 * mesmo imunobiológico que a cópia-ao-escrever de A04 produz (RN32).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('protocolos_vacinais', function (Blueprint $table) {
            // `unsignedBigInteger` e não `foreignId`: a chave estrangeira já
            // existe e não é ela que muda — mudar por `foreignId` tentaria
            // recriá-la. O tipo é repetido porque `->change()` redefine a
            // coluna inteira.
            $table->unsignedBigInteger('versao_protocolo_id')->nullable()->change();
        });

        // O índice novo nasce antes de o antigo morrer: a chave estrangeira de
        // `imunobiologico_id` se apoia num índice que comece por ela, e derrubar
        // o único existente antes de haver substituto faria o MySQL recusar.
        Schema::table('protocolos_vacinais', function (Blueprint $table) {
            $table->unique(
                ['imunobiologico_id', 'versao_protocolo_id'],
                'protocolos_vacinais_imuno_versao_unico',
            );
        });

        Schema::table('protocolos_vacinais', function (Blueprint $table) {
            $table->dropIndex(['imunobiologico_id', 'versao_protocolo_id']);
        });
    }

    public function down(): void
    {
        Schema::table('protocolos_vacinais', function (Blueprint $table) {
            $table->index(['imunobiologico_id', 'versao_protocolo_id']);
        });

        Schema::table('protocolos_vacinais', function (Blueprint $table) {
            $table->dropUnique('protocolos_vacinais_imuno_versao_unico');
        });

        // Descer exige que nenhuma linha esteja sem versão — as de A04 estão, e
        // não há versão a que atribuí-las. Removê-las é a única saída honesta:
        // o agendamento próprio não existe fora deste desenho. As vacinações que
        // o congelaram ficam com `protocolo_vacinal_id` nulo, que o motor já
        // trata como "sem data suficiente para calcular".
        DB::table('vacinacoes')
            ->whereIn('protocolo_vacinal_id', DB::table('protocolos_vacinais')->whereNull('versao_protocolo_id')->select('id'))
            ->update(['protocolo_vacinal_id' => null]);

        DB::table('protocolos_vacinais')->whereNull('versao_protocolo_id')->delete();

        Schema::table('protocolos_vacinais', function (Blueprint $table) {
            $table->unsignedBigInteger('versao_protocolo_id')->nullable(false)->change();
        });
    }
};
