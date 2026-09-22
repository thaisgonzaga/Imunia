<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O CEP do estabelecimento (RF08, RF11).
 *
 * Nasce nulo de propósito: o cadastro de P03 não o pede, para não alongar o
 * formulário de entrada, e é A01 que cobra o preenchimento depois. Sem CEP o
 * prestador ainda aparece no diretório consultado pelo tutor, mas só pelo
 * município — e é exatamente isso que a pendência de configuração explica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prestadores', function (Blueprint $table) {
            $table->char('cep', 8)->nullable()->after('endereco');
        });
    }

    public function down(): void
    {
        Schema::table('prestadores', function (Blueprint $table) {
            $table->dropColumn('cep');
        });
    }
};
