<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // O âmbito do prestador deixa de ser concedido pelo tutor e passa a nascer
        // do próprio trabalho clínico: o animal se vincula à clínica que o
        // cadastra ou que abre a ficha dele por um identificador exato (código,
        // QR, CPF do tutor, micro-chip). O vínculo não é permissão — qualquer
        // prestador alcança o animal pelo identificador —, é a carteira de
        // pacientes que alimenta painel, pendências, listas e busca por nome.
        Schema::create('animal_prestador', function (Blueprint $table) {
            $table->id();
            $table->foreignId('animal_id')->constrained('animais');
            $table->foreignId('prestador_id')->constrained('prestadores');

            // De onde o vínculo veio. Só informativo: nenhuma regra muda por
            // causa dele, mas a ficha e o livro de acessos podem dizer desde
            // quando, e por qual ato, a clínica acompanha o animal.
            $table->string('origem', 20);

            $table->dateTime('vinculado_em');
            $table->timestamps();

            // Um vínculo por par: vincular de novo é no-op, e é isso que deixa
            // cada porta de entrada chamar `vincular()` sem consultar antes.
            $table->unique(['prestador_id', 'animal_id']);
        });

        $this->herdarVinculos();
    }

    /**
     * Os animais que cada prestador já acompanhava continuam na carteira dele:
     * os sob autorização vigente, e aqueles em que ele já registrou vacina ou
     * atendimento. Autorização expirada ou revogada sem registro próprio não
     * vira vínculo — era o tutor encerrando o acompanhamento.
     */
    private function herdarVinculos(): void
    {
        $agora = now();

        $pares = DB::table('autorizacoes_acesso')
            ->whereNull('revogada_em')
            ->where('expira_em', '>', $agora)
            ->select('animal_id', 'prestador_id', DB::raw('MIN(concedida_em) as desde'))
            ->groupBy('animal_id', 'prestador_id')
            ->get()
            ->concat(
                DB::table('vacinacoes')
                    ->whereNotNull('prestador_id')
                    ->select('animal_id', 'prestador_id', DB::raw('MIN(created_at) as desde'))
                    ->groupBy('animal_id', 'prestador_id')
                    ->get(),
            )
            ->concat(
                DB::table('atendimentos')
                    ->select('animal_id', 'prestador_id', DB::raw('MIN(created_at) as desde'))
                    ->groupBy('animal_id', 'prestador_id')
                    ->get(),
            );

        $linhas = $pares
            ->groupBy(fn ($par) => $par->prestador_id.'-'.$par->animal_id)
            ->map(fn ($grupo) => [
                'animal_id' => $grupo->first()->animal_id,
                'prestador_id' => $grupo->first()->prestador_id,
                'origem' => 'migracao',
                'vinculado_em' => $grupo->pluck('desde')->filter()->min() ?? $agora,
                'created_at' => $agora,
                'updated_at' => $agora,
            ])
            ->values();

        foreach ($linhas->chunk(500) as $lote) {
            DB::table('animal_prestador')->insertOrIgnore($lote->all());
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('animal_prestador');
    }
};
