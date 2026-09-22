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
        // RF32 — exames e documentos anexados ao atendimento. O anexo herda a
        // imutabilidade do registro a que se vincula (RN28): não há caminho de
        // escrita que o troque depois de confirmado, e o vínculo é sempre com um
        // atendimento, nunca solto no animal.
        Schema::create('anexos_atendimento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('atendimento_id')->constrained('atendimentos');

            $table->string('descricao');

            // A data do exame, que RF32 exige e que quase nunca é a data do
            // atendimento: o laudo do raspado é de três dias depois da consulta.
            // Nula quando o documento não tem data própria — uma autorização
            // assinada, por exemplo.
            $table->date('exame_em')->nullable();

            // Só os dois que RN28 admite. O tipo é derivável do `mime`, mas
            // existe como coluna porque é ele que a tela lê para escolher o
            // ícone e o visualizador, e derivar isso em toda leitura seria
            // espalhar a tabela de conversão por três lugares.
            $table->enum('tipo', ['imagem', 'documento']);

            // RN28 — formato e tamanho conferidos no ato do envio; guardados
            // porque a rota que serve o arquivo precisa devolver o cabeçalho
            // correto sem reabrir o arquivo para adivinhá-lo.
            $table->string('mime');
            $table->unsignedInteger('tamanho_bytes');

            // RF32c — caminho no disco privado. Nunca sai em resposta alguma da
            // API: o arquivo é servido por rota que confere a autorização a cada
            // pedido, e um endereço direto no armazenamento contornaria essa
            // conferência para sempre, para quem o tivesse guardado.
            $table->string('caminho');

            $table->timestamps();

            $table->index('atendimento_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('anexos_atendimento');
    }
};
