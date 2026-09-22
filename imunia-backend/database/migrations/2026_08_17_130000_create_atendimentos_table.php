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
        // RF31 — prontuário do atendimento clínico. Como `vacinacoes`, é registro
        // clínico e por isso tem `prestador_id` (decisoes.md §3.1): quem responde
        // tecnicamente pelo que está escrito aqui é o prestador, não a
        // plataforma. Imutável depois de confirmado (RN26): não há coluna de
        // exclusão lógica nem de edição, e a correção entra como registro novo
        // apontando para o original.
        Schema::create('atendimentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('animal_id')->constrained('animais');

            // RF31c — carimbado com o prestador ativo no momento da criação.
            // Não é nulo em hipótese alguma: atendimento sem prestador seria
            // ato clínico sem responsável técnico.
            $table->foreignId('prestador_id')->constrained('prestadores');

            // RF33 — a retificação é um registro novo que aponta para o
            // retificado. Nulo no registro original; o encadeamento é lido nos
            // dois sentidos a partir daqui (RF33b).
            $table->foreignId('retifica_atendimento_id')->nullable()->constrained('atendimentos');
            $table->text('motivo_retificacao')->nullable();

            // RF31b — atribuída pelo sistema no ato do registro, nunca editável.
            $table->dateTime('atendido_em');

            // Não está entre os campos que RF31 enumera, porque RF31 descreve o
            // conteúdo clínico e não a apresentação: é a linha do tempo (RF35) e
            // o cabeçalho de T08 que precisam nomear o atendimento em uma linha.
            // Derivá-lo do diagnóstico daria títulos como "Aguardando resultado
            // do raspado" — que não é o nome do atendimento, é o estado dele.
            $table->string('titulo');

            // RF31 — o conteúdo clínico, na ordem em que o prontuário é lido.
            // `diagnostico` admite nulo porque o atendimento pode encerrar-se
            // com o diagnóstico ainda pendente de exame, e afirmar um que não
            // existe seria pior do que declarar a pendência.
            $table->text('motivo');
            $table->text('anamnese');
            $table->text('exame_fisico');
            $table->text('hipoteses_diagnosticas');
            $table->text('diagnostico')->nullable();
            $table->text('conduta');

            // RF31 — identificação do profissional responsável. Nome e CRMV são
            // copiados do usuário no ato e ficam congelados no registro: o
            // prontuário não muda porque o cadastro do veterinário mudou depois.
            // O vínculo com `users` é o que RN27 consulta para saber quem é o
            // autor, único que pode retificar.
            $table->foreignId('profissional_user_id')->constrained('users');
            $table->string('profissional_nome');
            $table->string('profissional_crmv');

            // RF34 — retorno programado, com data prevista e finalidade
            // descrita. Alimenta o lembrete de RF43 e o painel de RF49.
            $table->date('retorno_em')->nullable();
            $table->string('retorno_finalidade')->nullable();

            $table->timestamps();

            $table->index(['animal_id', 'atendido_em']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('atendimentos');
    }
};
