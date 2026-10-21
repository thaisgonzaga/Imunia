<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O profissional autônomo se cadastra sem CNPJ (P04): o documento continua
 * exigido de clínica e hospital, mas deixa de ser pedido ao veterinário que
 * atende por conta própria. A unicidade continua — dois estabelecimentos não
 * dividem um CNPJ —, e o índice único admite vários nulos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prestadores', function (Blueprint $table) {
            $table->char('cnpj', 14)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('prestadores', function (Blueprint $table) {
            $table->char('cnpj', 14)->nullable(false)->change();
        });
    }
};
