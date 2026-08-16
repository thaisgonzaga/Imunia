<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Emissões de histórico em PDF (RF46). A linha existe para que a rota
     * pública de verificação (RF47) possa afirmar três coisas e nada além
     * delas: que o documento saiu do Imunia, quando saiu e a que animal se
     * refere.
     *
     * O nome e a espécie do animal são gravados aqui como retrato do momento
     * da emissão, e não lidos de `animais` na hora da consulta. São o que o
     * papel na mão do conferente diz; se o cadastro mudar depois, o documento
     * antigo continua conferindo consigo mesmo — que é justamente o que a
     * verificação precisa provar.
     */
    public function up(): void
    {
        Schema::create('exportacoes', function (Blueprint $table) {
            $table->id();
            // Identificador próprio da emissão (RN47): 16 caracteres
            // hexadecimais, impressos no rodapé em quatro grupos de quatro e
            // embutidos no QR Code.
            $table->char('codigo', 16)->unique();
            // Resumo criptográfico do conteúdo emitido (sha-256, 64 caracteres).
            // Ao conferente são exibidos apenas os 16 primeiros, que são os
            // mesmos impressos no rodapé do PDF.
            $table->char('resumo', 64);
            $table->string('animal_nome');
            $table->enum('animal_especie', ['cao', 'gato']);
            // Autor da emissão (RF46a). Nulo quando a conta que emitiu deixa de
            // existir: apagar o autor não pode invalidar um documento que já
            // circula fora da plataforma.
            $table->foreignId('emitido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('emitido_em');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exportacoes');
    }
};
