<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * X02, RF24 — a versão do protocolo deixa de ser uma cadeia de caracteres
     * repetida em cada linha de parâmetros e passa a ser entidade própria.
     *
     * A razão é RN32: o que se publica, o que se encerra e o que cada cálculo
     * conserva é a *versão*, não o parâmetro isolado de um imunobiológico. Com
     * a versão sendo texto repetido, "publicar a 2026.1" seria percorrer linhas
     * soltas torcendo para que nenhuma ficasse para trás, e "quantos cálculos
     * foram feitos sob a 2024.1" não teria a quem perguntar. Com a entidade,
     * publicar é um ato só, e a contagem é uma junção.
     */
    public function up(): void
    {
        Schema::create('versoes_protocolo', function (Blueprint $table) {
            $table->id();

            // "2026.1" — o que o administrador lê na lista e o que aparece ao
            // tutor na nota explicativa do VaccineRail (RF26b).
            $table->string('rotulo')->unique();

            // Rascunho é editável e não calcula nada; vigente é a única usada em
            // cálculo novo; encerrada permanece consultável para sempre, porque
            // é dela que uma data antiga continua explicável (RN32).
            $table->string('situacao', 12)->default('rascunho');

            // De onde vieram os parâmetros — "WSAVA 2024, com a revisão de
            // intervalo mínimo da série felina". RN31 exige que a origem da
            // diretriz seja rastreável, e ela não cabe no rótulo.
            $table->string('base')->nullable();

            $table->timestamp('publicado_em')->nullable();
            $table->timestamp('encerrado_em')->nullable();
            $table->timestamps();

            $table->index('situacao');
        });

        Schema::table('protocolos_vacinais', function (Blueprint $table) {
            $table->foreignId('versao_protocolo_id')
                ->nullable()
                ->after('imunobiologico_id')
                ->constrained('versoes_protocolo');

            // RF27, RN34 — atraso acima deste limite deixa de ser "a dose está
            // atrasada" e passa a ser pergunta clínica: prosseguir ou reiniciar
            // a série. A resposta é parâmetro, não código, e o sistema a exibe
            // como sugestão — nunca impede o registro divergente (RN36).
            $table->unsignedSmallInteger('limite_atraso_dias')->default(30);
            $table->string('conduta_apos_limite', 12)->default('prosseguir');

            // O caso 3 de RNF01 — adulto sem histórico conhecido, a gata Nina do
            // cenário — precisa saber quantas doses formam a série nessa
            // situação, que não é a do filhote. Fica parametrizado pelo mesmo
            // motivo que o resto: RF24a proíbe absorver diretriz nova por
            // alteração de código.
            $table->unsignedSmallInteger('doses_adulto_sem_historico')->default(2);
        });

        $this->migrarVersoesExistentes();

        // Só depois de todas as linhas apontarem para uma versão é que a
        // coluna pode exigir isso. `unsignedBigInteger` e não `foreignId`:
        // este `change()` altera a coluna, e a chave estrangeira declarada
        // acima permanece onde está.
        Schema::table('protocolos_vacinais', function (Blueprint $table) {
            $table->unsignedBigInteger('versao_protocolo_id')->nullable(false)->change();
        });

        // O índice novo nasce antes de o antigo morrer: a chave estrangeira de
        // `imunobiologico_id` se apoia num índice que comece por ela, e o MySQL
        // recusa ficar sem nenhum no intervalo entre as duas operações.
        Schema::table('protocolos_vacinais', function (Blueprint $table) {
            $table->index(['imunobiologico_id', 'versao_protocolo_id']);
        });

        Schema::table('protocolos_vacinais', function (Blueprint $table) {
            $table->dropIndex(['imunobiologico_id', 'vigente']);
            $table->dropColumn(['versao', 'vigente', 'publicado_em']);
        });
    }

    /**
     * Cada valor distinto de `versao` vira uma linha de `versoes_protocolo`, e
     * os parâmetros que a carregavam passam a apontar para ela. Nenhum registro
     * de vacinação é tocado: eles referenciam a linha de parâmetros pelo id, que
     * não muda — que é exatamente o que RN32 promete.
     */
    private function migrarVersoesExistentes(): void
    {
        $porRotulo = DB::table('protocolos_vacinais')
            ->select('versao', 'vigente', 'publicado_em')
            ->get()
            ->groupBy('versao');

        foreach ($porRotulo as $rotulo => $linhas) {
            $vigente = $linhas->contains(fn ($linha) => (bool) $linha->vigente);
            $publicadoEm = $linhas->min('publicado_em');

            $id = DB::table('versoes_protocolo')->insertGetId([
                'rotulo' => $rotulo,
                'situacao' => $vigente ? 'vigente' : 'encerrada',
                'base' => null,
                'publicado_em' => $publicadoEm,
                'encerrado_em' => $vigente ? null : $publicadoEm,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('protocolos_vacinais')
                ->where('versao', $rotulo)
                ->update(['versao_protocolo_id' => $id]);
        }
    }

    /**
     * O esquema antigo não sabia o que é um rascunho — só vigente e não
     * vigente. Descer e subir de novo, portanto, devolve como encerrada a
     * versão que estava em edição. É perda conhecida do caminho de volta, e não
     * de dado publicado: rascunho é justamente o que ainda não valeu nada.
     */
    public function down(): void
    {
        Schema::table('protocolos_vacinais', function (Blueprint $table) {
            $table->string('versao')->after('imunobiologico_id')->default('');
            $table->boolean('vigente')->default(true);
            $table->timestamp('publicado_em')->nullable();
        });

        foreach (DB::table('versoes_protocolo')->get() as $versao) {
            DB::table('protocolos_vacinais')
                ->where('versao_protocolo_id', $versao->id)
                ->update([
                    'versao' => $versao->rotulo,
                    'vigente' => $versao->situacao === 'vigente',
                    'publicado_em' => $versao->publicado_em,
                ]);
        }

        // Mesma precedência da subida, ao contrário: o índice que sustenta a
        // chave estrangeira de `imunobiologico_id` volta a existir antes de o
        // outro sair.
        Schema::table('protocolos_vacinais', function (Blueprint $table) {
            $table->index(['imunobiologico_id', 'vigente']);
        });

        Schema::table('protocolos_vacinais', function (Blueprint $table) {
            $table->dropIndex(['imunobiologico_id', 'versao_protocolo_id']);
            $table->dropConstrainedForeignId('versao_protocolo_id');
            $table->dropColumn(['limite_atraso_dias', 'conduta_apos_limite', 'doses_adulto_sem_historico']);
        });

        Schema::dropIfExists('versoes_protocolo');
    }
};
