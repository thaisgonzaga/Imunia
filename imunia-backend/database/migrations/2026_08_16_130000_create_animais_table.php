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
        Schema::create('animais', function (Blueprint $table) {
            $table->id();

            // RF17 e RN15 — identificador permanente, não sequencial, apto a
            // QR Code. Fica antes de tudo porque é por ele que a clínica acha
            // o animal sem conhecer o tutor.
            $table->char('codigo', 12)->unique();

            // O animal é entidade global (RN10): não há `prestador_id` aqui, e
            // a única posse registrada é a titularidade do tutor.
            $table->foreignId('tutor_id')->constrained('tutores');

            // Identificação — o que o tutor pode declarar (RF16, RN17).
            $table->string('nome');
            $table->enum('especie', ['cao', 'gato']); // RN13
            $table->enum('sexo', ['macho', 'femea'])->nullable();
            $table->date('nascimento_em')->nullable();

            // RN14 — a natureza da data acompanha a data, para que todo cálculo
            // dela derivado saiba se está diante de aferição ou de estimativa.
            // Nasce falso: o que o tutor informa é estimativa até que o
            // veterinário confirme (RF19).
            $table->boolean('nascimento_exato')->default(false);

            // RN20 — a fotografia é dado de identificação, mantido pelo tutor.
            $table->string('foto_caminho')->nullable();

            // RN17 — enquanto nulo, o cadastro é preliminar. Preenchê-lo é ato
            // privativo do veterinário (RF19), e é o que encerra a condição.
            $table->timestamp('caracterizado_em')->nullable();

            $table->timestamps();

            // O painel do tutor (RF50) e a relação de animais (RF16) sempre
            // partem do tutor e ordenam por nome.
            $table->index(['tutor_id', 'nome']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('animais');
    }
};
