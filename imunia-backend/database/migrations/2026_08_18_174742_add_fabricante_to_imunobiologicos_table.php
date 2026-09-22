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
        // RF23 exige fabricante como campo do catálogo, ao lado da
        // denominação e da espécie de destino — ausente da migration
        // original porque ela nasceu da fatia de vacinação (RF25), que só
        // precisava de chave, nome e via. É X01 quem primeiro escreve o
        // catálogo, e é X01 quem fecha essa lacuna.
        Schema::table('imunobiologicos', function (Blueprint $table) {
            $table->string('fabricante')->after('nome_tecnico')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('imunobiologicos', function (Blueprint $table) {
            $table->dropColumn('fabricante');
        });
    }
};
