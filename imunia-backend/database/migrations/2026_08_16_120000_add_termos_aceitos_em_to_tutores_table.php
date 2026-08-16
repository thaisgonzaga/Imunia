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
        Schema::table('tutores', function (Blueprint $table) {
            // Consentimento que não se consegue provar não é consentimento: a
            // marca de tempo é a evidência de que o aceite foi obtido, e quando.
            $table->timestamp('termos_aceitos_em')->nullable()->after('cpf');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tutores', function (Blueprint $table) {
            $table->dropColumn('termos_aceitos_em');
        });
    }
};
