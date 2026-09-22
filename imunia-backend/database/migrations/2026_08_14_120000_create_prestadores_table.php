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
        Schema::create('prestadores', function (Blueprint $table) {
            $table->id();
            $table->enum('tipo', ['clinica', 'hospital', 'autonomo']);
            $table->string('nome');
            // RF07 — o prestador se inscreve sempre como pessoa jurídica, os
            // três tipos inclusive: o médico-veterinário não pode ser
            // microempreendedor individual, e o autônomo exerce a atividade sob
            // CNPJ. Por isso a coluna tem tamanho fixo, como o CPF do tutor.
            $table->char('cnpj', 14)->unique();
            $table->string('telefone');
            $table->string('endereco');
            $table->string('municipio');
            $table->char('uf', 2);
            $table->string('responsavel_tecnico_nome');
            $table->string('responsavel_tecnico_crmv');
            $table->char('responsavel_tecnico_crmv_uf', 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prestadores');
    }
};
