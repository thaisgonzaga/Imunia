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
        // O pedido de acesso (RF38) — a única coisa que o prestador pode fazer
        // sozinho a respeito do histórico de um animal que não é dele: pedir.
        //
        // A tabela existe separada de `autorizacoes_acesso` porque as duas dizem
        // coisas opostas. Aquela é o registro de um ato de vontade do tutor e
        // confere acesso; esta é o registro de um pedido, e não confere acesso
        // algum enquanto não houver concessão (RF38a). Guardar as duas na mesma
        // linha, com uma coluna de situação, faria a consulta de âmbito de RN48
        // depender de lembrar sempre de filtrá-la — e uma linha esquecida ali é
        // acesso indevido.
        Schema::create('solicitacoes_acesso', function (Blueprint $table) {
            $table->id();

            // Nominal por animal, como a autorização que ela pede (RN37): não
            // existe pedido de acesso ao tutor, nem ao plantel dele.
            $table->foreignId('animal_id')->constrained('animais');

            // Quem pede é o prestador, porque é ele quem receberia o acesso
            // (decisoes.md §4.2). O veterinário fica ao lado, na coluna
            // seguinte, como autor do ato.
            $table->foreignId('prestador_id')->constrained('prestadores');

            // RF38c — o tutor vê quem solicitou. "Quem" é o estabelecimento na
            // tela, mas o ato tem autor, e é ele que responde por tê-lo
            // praticado.
            $table->foreignId('solicitada_por_user_id')->constrained('users');

            $table->dateTime('solicitada_em');

            // RF38b — o pedido caduca se não for respondido. Sete dias, o mesmo
            // prazo do convite de ativação: é o intervalo em que o tutor ainda
            // se lembra do atendimento em que o pedido nasceu. Como na
            // autorização, a caducidade não precisa de rotina que a escreva —
            // uma data no passado já é o fim do pedido.
            $table->dateTime('expira_em');

            // A resposta do tutor, quando houver. As duas datas são nulas
            // enquanto o pedido está de pé, e apenas uma delas se preenche:
            // recusar não exige justificativa e não abre caminho para autorizar
            // depois pelo mesmo pedido — quem muda de ideia autoriza pelo
            // diretório (T10), que é o que a tela oferece.
            $table->dateTime('recusada_em')->nullable();

            // Preenchida pela concessão de T11, e não por esta tela: é o que
            // impede o pedido já atendido de continuar cobrando resposta de
            // quem já respondeu.
            $table->dateTime('atendida_em')->nullable();

            $table->timestamps();

            // A pergunta de T13 é por tutor, mas passa por animal; a da
            // concessão é pelo par, para dar baixa no pedido que ela atende.
            $table->index(['animal_id', 'prestador_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('solicitacoes_acesso');
    }
};
