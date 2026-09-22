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
        // O ato de vontade em curso, ainda não consumado (RF37). Entre escolher
        // o prestador e conceder a autorização há um intervalo — o do código que
        // chega ao e-mail —, e é esse intervalo que esta tabela guarda.
        //
        // Ela existe separada de `autorizacoes_acesso` porque as duas respondem
        // a perguntas diferentes: aquela é o livro do que foi consentido, esta é
        // o expediente de uma concessão em andamento. Confundir as duas faria a
        // linha da autorização nascer antes do consentimento, que é exatamente
        // o que RF37 existe para impedir.
        Schema::create('confirmacoes_de_autorizacao', function (Blueprint $table) {
            $table->id();

            // O identificador que viaja na URL. Sequencial, o tutor descobriria
            // pelo próprio número quantas concessões o sistema já processou, e
            // a tentativa de abrir a do vizinho seria trivial de escrever.
            $table->ulid('publico')->unique();

            // Quem concede. O usuário, e não o tutor, porque é o ato dele que
            // RN38 exige e é o e-mail dele que recebe o código.
            $table->foreignId('user_id')->constrained();

            $table->foreignId('prestador_id')->constrained('prestadores');

            // Os animais escolhidos no passo 2, cada um destinado a virar uma
            // linha própria de autorização (RN37). Ficam em JSON, e não em
            // tabela de ligação, porque o conjunto só tem sentido enquanto a
            // confirmação está aberta: consumada ou vencida, quem responde
            // "quem vê o quê" são as autorizações que ela gerou. A titularidade
            // é reconferida na confirmação, de modo que o JSON não é a garantia
            // de nada — é só o rascunho da escolha.
            $table->json('animais');

            // RF37c — o código nunca é exibido em tela, e aqui também não fica
            // legível: guarda-se o resumo, como nas ligações de P05 e P08. Um
            // vazamento desta tabela não entrega código utilizável a ninguém.
            $table->string('codigo');

            $table->dateTime('enviado_em');

            // RN03 aplicado à concessão: prazo curto. O contador da tela é a
            // diferença entre esta data e o relógio.
            $table->dateTime('expira_em');

            // RF37a — número limitado de tentativas. O contador acompanha a
            // confirmação, e não a sessão do navegador: fechar a aba e voltar
            // não devolve tentativa alguma.
            $table->unsignedTinyInteger('tentativas')->default(0);

            // Esgotadas as tentativas, a pausa. Fica gravada, e não em cache,
            // porque é fato que o tutor vai reler no aviso que recebe por
            // e-mail — "houve cinco tentativas erradas, neste dia e hora".
            $table->dateTime('bloqueada_ate')->nullable();

            $table->dateTime('confirmada_em')->nullable();

            $table->timestamps();

            // A pergunta de toda entrada no fluxo: este tutor tem confirmação
            // em aberto, ou pausa em curso?
            $table->index(['user_id', 'expira_em']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('confirmacoes_de_autorizacao');
    }
};
