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
        // O registro persistente das notificações já emitidas, exigido
        // literalmente por RN43 — "garantida por registro persistente das
        // notificações já emitidas". Sem ele não há como cumprir nem RN43 (uma
        // vez por destinatário, evento e janela) nem RN44 (no máximo três
        // comunicações por dose prevista).
        //
        // A tabela nasce nesta fatia porque V02 é a primeira tela que a lê: a
        // coluna "Última notificação" de RF49 responde à pergunta que decide a
        // rechamada — este tutor já foi avisado, e quando? Sem a coluna, o
        // painel manda ligar para quem recebeu o lembrete ontem.
        //
        // O motor que *escreve* estas linhas é fatia própria (RF42, RF43): aqui
        // só se lê, e o cenário de demonstração as semeia.
        Schema::create('notificacoes', function (Blueprint $table) {
            $table->id();

            // Destinatário e assunto. O tutor é quem recebe (RN42); o animal é
            // sobre o que se avisa. Guardar os dois evita ter de atravessar a
            // titularidade para saber a quem a mensagem foi, o que se perderia
            // numa transferência de titularidade (RF21).
            $table->foreignId('animal_id')->constrained('animais');
            $table->foreignId('tutor_id')->constrained('tutores');

            // A série a que o aviso se refere. Nulo quando a aplicação de
            // origem não identifica a vacina (RF29): o grupo existe justamente
            // porque não há imunobiológico a que se referir.
            $table->foreignId('imunobiologico_id')->nullable()->constrained('imunobiologicos');

            // RN44 — as três comunicações previstas para cada dose, e nenhuma
            // além delas.
            $table->enum('tipo', ['aviso_previo', 'aviso_na_data', 'alerta_atraso']);

            // A data prevista da dose que originou o aviso. É o que transforma
            // "já avisei" numa afirmação verificável: avisar sobre o reforço de
            // junho não é avisar sobre o de dezembro.
            $table->date('referente_a');

            $table->dateTime('enviada_em');

            // O que o provedor de correio devolveu. "sem_confirmacao" não é
            // falha: é entrega não confirmada, e a tela a distingue das outras
            // duas porque a conduta da rechamada muda (§8.3, V02).
            $table->enum('situacao', ['entregue', 'sem_confirmacao', 'falhou'])
                ->default('sem_confirmacao');

            $table->timestamps();

            // RN43 escrita no esquema, e não só no texto: destinatário, evento e
            // janela formam a chave que impede a segunda emissão. Em MySQL dois
            // nulos não colidem, de modo que a garantia não alcança as
            // aplicações sem imunobiológico identificado — o motor de RF43
            // precisará tratá-las por outro caminho, e isto fica anotado aqui
            // porque o índice, sozinho, sugeriria uma proteção que não tem.
            $table->unique(['animal_id', 'imunobiologico_id', 'tipo', 'referente_a'], 'notificacoes_evento_unico');

            // A pergunta de V02: qual foi a última notificação desta série?
            $table->index(['animal_id', 'imunobiologico_id', 'enviada_em']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notificacoes');
    }
};
