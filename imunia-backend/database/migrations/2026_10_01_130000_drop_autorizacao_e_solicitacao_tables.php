<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O consentimento por animal deixou de existir: a clínica atende sem
     * esperar o tutor, e o que ela acompanha é o vínculo de `animal_prestador`
     * (que já herdou as autorizações vigentes). Saem a concessão, a confirmação
     * por código e os pedidos de acesso.
     *
     * O livro de acessos (`registros_de_acesso`) fica: ele é do tutor, e as
     * linhas antigas continuam contando quem olhou o quê.
     */
    public function up(): void
    {
        Schema::dropIfExists('solicitacoes_acesso');
        Schema::dropIfExists('confirmacoes_de_autorizacao');
        Schema::dropIfExists('autorizacoes_acesso');
    }

    /**
     * Sem volta: recriar as tabelas vazias não devolveria as autorizações, e
     * nenhum código as lê mais. Quem precisar delas restaura o backup.
     */
    public function down(): void {}
};
