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
        // O livro de acessos do sistema (RF52, RN49). Cada linha responde à
        // pergunta que RF53 devolve ao tutor: quem olhou o quê, e quando.
        //
        // A tabela nasce nesta fatia porque V03 é a primeira tela que *escreve*
        // nela: RF18b exige que a consulta sem autorização seja registrada em
        // log, e o desenho da tela anuncia isso ao profissional em nota
        // discreta — "esta consulta ficou registrada, e o tutor a verá". Sem a
        // tabela, aquela frase seria falsa, que é pior do que não existir.
        //
        // A leitura pelo tutor (T14/RF53) e o registro de visualização de
        // histórico produzido por outro prestador (RF52, na fatia de V06 e T07)
        // são fatias próprias, e escrevem e leem estas mesmas linhas.
        Schema::create('registros_de_acesso', function (Blueprint $table) {
            $table->id();

            // Quem acessou: o estabelecimento e a pessoa. RF52 pede os dois, e
            // por bom motivo — o mesmo profissional atua em vários prestadores,
            // e é o prestador que responde pela autorização (decisoes.md §4.2),
            // mas é a pessoa que praticou o ato.
            $table->foreignId('prestador_id')->constrained('prestadores');
            $table->foreignId('user_id')->constrained('users');

            // O que foi acessado. Ambos nulos conforme a natureza: a busca por
            // CPF conhece o tutor e ainda não sabe de animal algum; a busca por
            // código conhece o animal, e é dele que o tutor é deduzido na
            // leitura. Guardar os dois quando os dois se sabem poupa a T14 a
            // travessia da titularidade, que a transferência de RF21 mudaria.
            $table->foreignId('tutor_id')->nullable()->constrained('tutores');
            $table->foreignId('animal_id')->nullable()->constrained('animais');

            // RF52 — "natureza do dado acessado". Texto livre e não enum de
            // propósito: cada fatia que passar a registrar acesso acrescenta o
            // seu termo, e um enum obrigaria a migrar o esquema a cada uma.
            // O vocabulário vive em App\Models\RegistroDeAcesso.
            $table->string('natureza', 40);

            // RF52a — o log é imutável. Não há `timestamps()` aqui: sem
            // `updated_at` não há atualização a registrar, e a ausência da
            // coluna é a regra escrita no esquema, e não só no texto.
            $table->dateTime('ocorrido_em');

            // As duas perguntas de T14: o que aconteceu com os animais deste
            // tutor, e com este animal — sempre em ordem de tempo, porque a
            // tela agrupa por dia.
            $table->index(['tutor_id', 'ocorrido_em']);
            $table->index(['animal_id', 'ocorrido_em']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registros_de_acesso');
    }
};
