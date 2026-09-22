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
        // O livro de consentimento do sistema (decisoes.md §4). Cada linha é uma
        // autorização nominal: um animal determinado, um prestador determinado.
        // Não existe linha genérica, e não há como criá-la — as duas chaves são
        // obrigatórias, que é RN37 escrita no esquema e não só no texto.
        //
        // A tabela nasce nesta fatia porque V01 é a primeira tela cujo âmbito ela
        // define (RN48): sem ela, o painel do veterinário não teria como abranger
        // "exclusivamente animais sob autorização vigente". A concessão pelo
        // tutor (RF36, RF37), a renovação e a revogação (RF39) são fatias
        // próprias; aqui só se lê.
        Schema::create('autorizacoes_acesso', function (Blueprint $table) {
            $table->id();

            // Nominal por animal, nunca por tutor: RF36a. Autorizar o histórico
            // do Théo não autoriza o da Nina.
            $table->foreignId('animal_id')->constrained('animais');

            // O destino é o prestador, e não o veterinário (decisoes.md §4.2): o
            // mesmo profissional atua em vários estabelecimentos, e é o
            // prestador que carimba o registro clínico (RN09).
            $table->foreignId('prestador_id')->constrained('prestadores');

            // RN38 — só o tutor concede, e o ato fica atribuído a quem o
            // praticou. Guardar o usuário, e não apenas o tutor, é o que permite
            // RF53 responder "quem autorizou, e quando".
            $table->foreignId('concedida_por_user_id')->constrained('users');

            $table->dateTime('concedida_em');

            // RN39 — prazo determinado de noventa dias, renováveis, findo o qual
            // a autorização se encerra sozinha. A expiração não precisa de
            // rotina que a escreva: uma data no passado já é o fim da vigência.
            $table->dateTime('expira_em');

            // RN40 — revogar não apaga. Marca a data e encerra a vigência; os
            // registros que o prestador produziu continuam sob a guarda dele.
            // RN41 conserva concessões, renovações, expirações e revogações,
            // e é por isso que a renovação entra como linha nova em vez de
            // adiar `expira_em` desta.
            $table->dateTime('revogada_em')->nullable();

            $table->timestamps();

            // A pergunta que toda tela do veterinário faz antes de qualquer
            // outra: quais animais este prestador pode ver agora?
            $table->index(['prestador_id', 'animal_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('autorizacoes_acesso');
    }
};
