<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O histórico de alterações do cadastro do prestador (RF08).
 *
 * RF08 manda atualizar os dados "preservando histórico das alterações
 * relevantes para a identificação do estabelecimento em documentos já
 * emitidos". A pergunta que esta tabela responde é a que um documento antigo
 * levanta: por que este PDF de janeiro diz "Clínica Vet Amigo" se hoje a
 * clínica se chama outra coisa? A denominação vigente na emissão já fica
 * congelada em `exportacoes`; o que faltava era o caminho inverso — poder
 * mostrar, no cadastro, quando e por quem o nome mudou.
 *
 * É registro de auditoria, e por isso imutável, como `registros_de_acesso`:
 * uma linha por campo alterado, sem `updated_at`, sem caminho de escrita que
 * a altere depois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alteracoes_prestador', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prestador_id')->constrained('prestadores')->cascadeOnDelete();

            // Quem alterou pode deixar de existir; a alteração, não. Por isso
            // `nullOnDelete`, e por isso o nome de quem alterou é resolvido na
            // leitura, e não copiado para cá.
            $table->foreignId('alterado_por_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('campo');
            $table->text('de')->nullable();
            $table->text('para')->nullable();
            $table->timestamp('ocorrido_em');

            $table->index(['prestador_id', 'ocorrido_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alteracoes_prestador');
    }
};
