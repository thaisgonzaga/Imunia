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
        // RF25, RF29 — registro de aplicação, seja de origem profissional
        // (RN21, RN22) ou histórico pregresso lançado pelo tutor (RN24, RN25).
        // Ao contrário de `animais` e `tutores`, possui `prestador_id`
        // (decisoes.md §3.1): é registro clínico, não identificação global.
        Schema::create('vacinacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('animal_id')->constrained('animais');

            // Nulo apenas em `origem = pregresso`: aplicação fora da
            // plataforma não tem prestador a carimbar (RF29).
            $table->foreignId('prestador_id')->nullable()->constrained('prestadores');
            $table->foreignId('imunobiologico_id')->constrained('imunobiologicos');

            // A versão do protocolo vigente ao calcular a próxima dose desta
            // aplicação (RN32). Nula em histórico pregresso: não houve cálculo
            // algum a registrar, só o relato do tutor.
            $table->foreignId('protocolo_vacinal_id')->nullable()->constrained('protocolos_vacinais');

            $table->enum('origem', ['profissional', 'pregresso']);

            // RF25a — obrigatórios na origem profissional; RF29 reduz a
            // obrigatoriedade no pregresso, por isso nulos aqui e cobrados na
            // FormRequest de cada fluxo de escrita, não no esquema.
            $table->string('fabricante')->nullable();
            $table->string('lote')->nullable();
            $table->date('validade')->nullable();
            $table->string('via_administracao')->nullable();

            $table->dateTime('aplicado_em');

            // RF29 — quando só o ano (ou o ano e o mês) é conhecido, o registro
            // guarda essa imprecisão em vez de fingir um dia exato; o cálculo
            // do calendário (RF26) não ancora a série em datas aproximadas.
            $table->boolean('data_aproximada')->default(false);

            $table->unsignedSmallInteger('ordem_dose')->nullable();

            // RF25b — identificação do aplicador, travada a partir do usuário
            // autenticado no ato do registro; nula em pregresso.
            $table->string('aplicador_nome')->nullable();
            $table->string('aplicador_crmv')->nullable();

            // RF25c — vacina com validade expirada na aplicação exige
            // confirmação explícita do profissional.
            $table->boolean('validade_expirada_confirmada')->default(false);

            // RF29b — quem lançou o pregresso e quando; nulo em origem
            // profissional, onde a autoria já é `aplicador_*`.
            $table->foreignId('lancado_por_user_id')->nullable()->constrained('users');

            $table->timestamps();

            $table->index(['animal_id', 'imunobiologico_id', 'aplicado_em']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vacinacoes');
    }
};
