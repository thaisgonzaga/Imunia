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
        Schema::table('animais', function (Blueprint $table) {
            // O micro-chip é campo de caracterização, privativo do veterinário
            // (RF19, RN18), e por isso não nasceu com a tabela. A coluna entra
            // nesta fatia porque V03 é a primeira tela que *lê* por ele: RF51
            // manda buscar "por nome, CPF, código do animal ou micro-chip", e
            // uma busca que anuncia o formato sem ter onde procurar seria uma
            // promessa vazia na tela.
            //
            // Mesma divisão da tabela de autorizações, criada na fatia de V01:
            // aqui só se lê. O preenchimento é ato do veterinário e entra com a
            // fatia de RF19 (V05); até lá, quem semeia o valor é o cenário de
            // demonstração.
            //
            // Único, porque o número identifica o animal como o código o faz
            // (RN15 por analogia): dois animais com o mesmo chip tornariam a
            // busca ambígua justamente onde ela precisa ser exata. Nulo é o
            // estado normal — a maioria dos animais não é chipada, e por isso
            // o índice único convive com muitos nulos.
            $table->char('microchip', 15)->nullable()->unique()->after('codigo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('animais', function (Blueprint $table) {
            $table->dropUnique(['microchip']);
            $table->dropColumn('microchip');
        });
    }
};
