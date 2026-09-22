<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O responsável técnico passa a poder faltar (RF07c, RF08).
 *
 * RF07c diz que "prestador sem responsável técnico identificado não pode
 * registrar informação clínica" — uma regra que pressupõe que o estado exista.
 * Enquanto as três colunas fossem obrigatórias, ele era inalcançável, e o
 * alerta bloqueante de A01 seria código sem caminho até ele.
 *
 * O estado é real: o responsável técnico deixa a clínica, e o vínculo dele é
 * encerrado antes de haver substituto. Daí em diante o estabelecimento
 * continua existindo — a equipe entra, vê a própria conta —, mas não há CRMV a
 * quem atribuir uma aplicação, e é isso que A01 anuncia.
 *
 * O cadastro de P03 segue exigindo os três: quem abre a conta declara quem
 * responde por ela. O que muda é só o que pode acontecer depois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prestadores', function (Blueprint $table) {
            $table->string('responsavel_tecnico_nome')->nullable()->change();
            $table->string('responsavel_tecnico_crmv')->nullable()->change();
            $table->char('responsavel_tecnico_crmv_uf', 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('prestadores', function (Blueprint $table) {
            $table->string('responsavel_tecnico_nome')->nullable(false)->change();
            $table->string('responsavel_tecnico_crmv')->nullable(false)->change();
            $table->char('responsavel_tecnico_crmv_uf', 2)->nullable(false)->change();
        });
    }
};
