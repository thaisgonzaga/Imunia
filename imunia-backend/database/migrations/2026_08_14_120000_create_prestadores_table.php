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
            $table->string('documento')->unique();
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
