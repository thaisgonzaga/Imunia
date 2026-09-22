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
        // RF24, RN31, RN32 — os parâmetros temporais do calendário ficam aqui,
        // versionados, e não codificados no cálculo. Cada vacinação registrada
        // (RF25) guarda qual versão estava vigente ao aplicar, e a publicação
        // de uma versão nova não recalcula datas já emitidas.
        Schema::create('protocolos_vacinais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('imunobiologico_id')->constrained('imunobiologicos');

            // Identifica a fonte e a revisão — ex. "wsava-2024.1" — e aparece
            // ao tutor na nota explicativa do VaccineRail (RF26b).
            $table->string('versao');

            // RN34 — janela de intervalo entre doses da série primária.
            $table->unsignedSmallInteger('numero_doses_serie_primaria');
            $table->unsignedSmallInteger('intervalo_minimo_dias');
            $table->unsignedSmallInteger('intervalo_maximo_dias');

            // RN33 — a dose final da série primária de filhotes não pode ser
            // aplicada antes desta idade; abaixo dela, agenda-se dose extra.
            $table->unsignedSmallInteger('idade_minima_dose_final_semanas')->nullable();
            $table->unsignedSmallInteger('idade_minima_primeira_dose_semanas')->nullable();

            // RN35 — primeiro reforço após a série primária, e periodicidade
            // das revacinações seguintes.
            $table->unsignedSmallInteger('reforco_inicial_meses')->nullable();
            $table->unsignedSmallInteger('periodicidade_revacinacao_meses');

            $table->timestamp('publicado_em');
            $table->boolean('vigente')->default(true);
            $table->timestamps();

            $table->index(['imunobiologico_id', 'vigente']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('protocolos_vacinais');
    }
};
