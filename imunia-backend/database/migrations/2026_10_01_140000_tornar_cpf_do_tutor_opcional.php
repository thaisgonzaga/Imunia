<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O veterinário cadastra o tutor com nome e e-mail (V04): o CPF deixa de ser
 * exigido no balcão e passa a ser dado opcional. A unicidade continua — dois
 * tutores não dividem um CPF —, e o índice único admite vários nulos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tutores', function (Blueprint $table) {
            $table->char('cpf', 11)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tutores', function (Blueprint $table) {
            $table->char('cpf', 11)->nullable(false)->change();
        });
    }
};
