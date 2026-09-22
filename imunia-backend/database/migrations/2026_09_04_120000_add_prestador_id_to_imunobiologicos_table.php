<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A04, RF23 — o catálogo deixa de ser exclusivamente global.
 *
 * Nulo é o item da plataforma: o que a WSAVA sustenta, o que X01 mantém e o
 * que RN30 restringe. Preenchido é o item privado daquela clínica — a vacina
 * que ela usa e que a diretriz não nomeia —, invisível às demais e fora do
 * alcance de X01.
 *
 * `decisoes.md` §3.6 decidira contra parametrização por prestador, e a decisão
 * continua valendo para o que ela julgava: os parâmetros das vacinas da
 * diretriz seguem sendo os mesmos em toda parte, porque o mesmo cálculo tem de
 * responder igual em qualquer lugar (RN31). O que se abre aqui é outra coisa —
 * o acervo próprio, que a diretriz nunca examinou e sobre o qual a plataforma
 * não tem o que dizer. Ver §9.25.
 *
 * A coluna fica deliberadamente fora do `#[Fillable]` do modelo, como
 * `admin_plataforma` no usuário: de quem é o item é decisão do servidor, e
 * nunca campo de formulário.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imunobiologicos', function (Blueprint $table) {
            $table->foreignId('prestador_id')
                ->nullable()
                ->after('id')
                ->constrained('prestadores');
        });
    }

    public function down(): void
    {
        Schema::table('imunobiologicos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prestador_id');
        });
    }
};
