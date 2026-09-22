<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O último contexto de prestador em que o usuário trabalhou (RF09b). É memória
 * de conveniência, não decisão de acesso: quem resolve o contexto continua
 * verificando o vínculo vigente a cada pedido, e um id lembrado de vínculo já
 * encerrado é simplesmente ignorado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('ultimo_prestador_id')
                ->nullable()
                ->constrained('prestadores')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ultimo_prestador_id');
        });
    }
};
