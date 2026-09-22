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
        // O papel `admin_plataforma` (RF23, RF24) não nasce de vínculo com
        // prestador nem de registro em `tutores` — é global, como o próprio
        // catálogo que ele administra. Por isso a coluna fica no usuário, e
        // não em tabela pivô: não há "sobre qual prestador" a perguntar.
        // Nunca atribuível em massa — de propósito fora do #[Fillable] do
        // model — porque é a API de sessão, não a de cadastro, que decide
        // quem entra no papel (decisoes.md §9.2).
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('admin_plataforma')->default(false)->after('password');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('admin_plataforma');
        });
    }
};
