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
        Schema::create('imunobiologicos', function (Blueprint $table) {
            $table->id();

            // RN30 — só o que está aqui pode ser registrado como aplicação
            // (RF25). A chave é o identificador estável usado pelo protocolo
            // e pelo cálculo do calendário; o nome comercial pode mudar de
            // fabricante para fabricante sem que a chave mude.
            $table->string('chave')->unique();
            $table->string('nome_comercial');
            $table->string('nome_tecnico');
            $table->string('agentes_cobertos');

            // RF23 — a espécie de destino restringe o catálogo visível no
            // registro de vacinação; "ambas" cobre a antirrábica.
            $table->enum('especie_destino', ['cao', 'gato', 'ambas']);
            $table->enum('classificacao', ['essencial', 'nao_essencial']);
            $table->string('via_administracao_usual');

            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('imunobiologicos');
    }
};
