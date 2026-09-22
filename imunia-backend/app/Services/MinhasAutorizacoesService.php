<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\Autorizacao;
use App\Models\Prestador;
use App\Models\Tutor;
use App\Models\User;
use App\Notifications\AutorizacaoRevogada;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;

/**
 * O que o tutor vê e faz sobre as autorizações que já concedeu (T12, RF41,
 * RF39, RF40).
 *
 * O agrupamento é por animal, e isso é a tese da tela: a pergunta que o tutor
 * faz não é "quantas autorizações eu tenho", é "quem vê o Théo". Uma relação
 * plana de autorizações responderia à primeira pergunta e obrigaria o tutor a
 * responder à segunda de cabeça.
 *
 * A revogação e a renovação vivem aqui porque são a mesma leitura vista do
 * outro lado: as duas mudam o que esta relação mostra, e nenhuma delas passa
 * por código novo — a revogação porque desfazer nunca deve ser mais difícil que
 * fazer, a renovação porque o consentimento já foi manifestado uma vez (RF40c).
 */
class MinhasAutorizacoesService
{
    /**
     * A relação inteira, das quatro situações, numa resposta só. As abas de T12
     * filtram no navegador porque o número de autorizações de um tutor é da
     * ordem da dezena: buscar de novo a cada toque custaria mais rede do que a
     * lista custa inteira, e faria piscar uma tela que só mudou de recorte.
     *
     * @return array<string, mixed>
     */
    public function listar(Tutor $tutor): array
    {
        $autorizacoes = $this->doTutor($tutor);
        $relacao = $autorizacoes->reject(
            fn (Autorizacao $autorizacao) => $this->substituidaPorRenovacao($autorizacao, $autorizacoes),
        );

        $grupos = $relacao
            ->groupBy('animal_id')
            ->map(fn (EloquentCollection $doAnimal) => [
                'animal' => $this->cartaoDoAnimal($doAnimal->first()->animal),
                'autorizacoes' => $doAnimal
                    // Vigentes primeiro, encerradas depois, e dentro de cada
                    // bloco a de prazo mais recente à frente. É a ordem da
                    // pergunta: quem vê agora, e só então quem já viu.
                    ->sortBy(fn (Autorizacao $autorizacao) => [
                        $autorizacao->estaVigente() ? 0 : 1,
                        -$autorizacao->expira_em->timestamp,
                    ])
                    ->map(fn (Autorizacao $autorizacao) => $this->cartao($autorizacao))
                    ->values()
                    ->all(),
            ])
            ->values();

        // A ordem dos grupos é a dos animais na relação do tutor (T02), e não a
        // da autorização mais recente: a tela é sobre os animais, e a posição
        // deles não deve mudar porque uma clínica foi autorizada ontem.
        $ordemDosAnimais = $tutor->animais()->orderBy('nome')->pluck('codigo')->flip();

        $quantas = fn (array $situacoes) => $relacao
            ->filter(fn (Autorizacao $autorizacao) => in_array($autorizacao->situacao(), $situacoes, true))
            ->count();

        return [
            'prazo_em_dias' => Autorizacao::PRAZO_DIAS,
            'dias_para_avisar' => Autorizacao::DIAS_PARA_AVISAR_EXPIRACAO,
            'abas' => [
                // "A expirar" é recorte de "vigentes", e não estado ao lado
                // dele: a autorização que vence em doze dias continua valendo
                // hoje, e escondê-la da aba das vigentes faria a tela negar o
                // acesso que a clínica ainda tem.
                'vigentes' => $quantas(['vigente', 'a_expirar']),
                'a_expirar' => $quantas(['a_expirar']),
                'encerradas' => $quantas(['expirada', 'revogada']),
            ],
            'grupos' => $grupos
                ->sortBy(fn (array $grupo) => $ordemDosAnimais->get($grupo['animal']['codigo'], PHP_INT_MAX))
                ->values()
                ->all(),
        ];
    }

    /**
     * RF39 — a revogação, sem justificativa e de efeito imediato: a partir
     * daqui o escopo de RN48 já não alcança este animal, sem que o prestador
     * precise sequer recarregar a tela.
     *
     * A linha não é apagada (RN41). O que muda é a data que encerra a vigência,
     * e é ela que faz a autorização migrar para "encerradas" com a informação
     * de que o fim foi ato do tutor.
     */
    public function revogar(Autorizacao $autorizacao): Autorizacao
    {
        $autorizacao->forceFill(['revogada_em' => Carbon::now()])->save();

        // RF39c — comunicada ao prestador. O aviso sai depois da gravação: o
        // acesso já acabou quando a mensagem parte, e uma falha de envio não
        // pode devolver acesso algum.
        $this->avisarPrestador($autorizacao);

        return $autorizacao;
    }

    /**
     * RF40c — renovar em ato único, sem repetir o fluxo de concessão. Não há
     * código novo: o tutor já confirmou esta autorização uma vez, e exigir o
     * segundo código transformaria a renovação em concessão — que é justamente
     * o que o requisito manda evitar.
     *
     * A renovação nasce como linha nova, e não como adiamento de `expira_em`
     * (RN41): o que se conserva não é o acesso, é o registro de cada ato de
     * vontade do tutor, com a data em que ele o praticou.
     */
    public function renovar(Autorizacao $autorizacao, User $usuario): Autorizacao
    {
        $renovada = Autorizacao::create([
            'animal_id' => $autorizacao->animal_id,
            'prestador_id' => $autorizacao->prestador_id,
            'concedida_por_user_id' => $usuario->id,
            'concedida_em' => Carbon::now(),

            // RN39 — o prazo novo corre de hoje, e não do fim do anterior. Quem
            // renova no décimo segundo dia antes do vencimento não recebe
            // noventa e dois dias: recebe noventa, contados do ato.
            'expira_em' => Carbon::now()->addDays(Autorizacao::PRAZO_DIAS),
        ]);

        return $renovada->setRelation('animal', $autorizacao->animal)
            ->setRelation('prestador', $autorizacao->prestador);
    }

    /**
     * Todas as autorizações dos animais do tutor — inclusive as encerradas, que
     * RF41b manda conservar consultáveis.
     *
     * @return EloquentCollection<int, Autorizacao>
     */
    private function doTutor(Tutor $tutor): EloquentCollection
    {
        return Autorizacao::query()
            ->whereHas('animal', fn (Builder $animal) => $animal->where('tutor_id', $tutor->id))
            ->with(['animal', 'prestador'])
            ->orderByDesc('concedida_em')
            ->get();
    }

    /**
     * A autorização que uma renovação sucedeu, e que por isso não figura na
     * relação: mostrá-la seria anunciar como "expirada" um acesso que nunca foi
     * interrompido.
     *
     * O que distingue renovação de nova concessão é o momento: a renovação
     * acontece com a autorização ainda de pé (RF40c), a concessão nova acontece
     * depois que ela caiu — e essa, sim, deixa para trás uma linha encerrada que
     * o tutor tem direito de consultar.
     *
     * @param  EloquentCollection<int, Autorizacao>  $todas
     */
    private function substituidaPorRenovacao(Autorizacao $autorizacao, EloquentCollection $todas): bool
    {
        if ($autorizacao->revogada_em !== null) {
            return false;
        }

        return $todas->contains(
            fn (Autorizacao $outra) => $outra->id !== $autorizacao->id
                && $outra->animal_id === $autorizacao->animal_id
                && $outra->prestador_id === $autorizacao->prestador_id
                && $outra->concedida_em->greaterThan($autorizacao->concedida_em)
                && $outra->concedida_em->lessThanOrEqualTo($autorizacao->expira_em),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function cartao(Autorizacao $autorizacao): array
    {
        $situacao = $autorizacao->situacao();

        return [
            'id' => $autorizacao->id,
            'situacao' => $situacao,
            'prestador' => $this->cartaoDoPrestador($autorizacao->prestador),
            'concedida_em' => $autorizacao->concedida_em->toDateString(),
            'expira_em' => $autorizacao->expira_em->toDateString(),
            'revogada_em' => $autorizacao->revogada_em?->toDateString(),
            'dias_restantes' => $autorizacao->diasRestantes(),
            'proporcao_restante' => round($autorizacao->proporcaoRestante(), 4),

            // A renovação é oferecida enquanto há o que renovar. Depois do
            // prazo não há: o consentimento acabou, e recomeçá-lo é o fluxo
            // inteiro de RF37, com código — que é o "Autorizar de novo" da tela.
            'renovavel' => $autorizacao->estaVigente(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cartaoDoPrestador(Prestador $prestador): array
    {
        return [
            // RF11a — identificação e localização públicas, nada de operação.
            'id' => $prestador->id,
            'nome' => $prestador->nome,
            'tipo_rotulo' => $prestador->tipoRotulo(),
            'municipio' => $prestador->municipio,
            'uf' => $prestador->uf,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cartaoDoAnimal(Animal $animal): array
    {
        return [
            'codigo' => $animal->codigo,
            'nome' => $animal->nome,
            'especie' => $animal->especie,
            'foto_url' => $animal->fotoUrl(),
        ];
    }

    private function avisarPrestador(Autorizacao $autorizacao): void
    {
        $aviso = new AutorizacaoRevogada(
            $autorizacao->prestador->nome,
            $autorizacao->animal->nome,
            $autorizacao->animal->codigo,
        );

        // Só quem atende: o aviso nomeia o animal, e RN08 mantém o
        // administrador do prestador fora de qualquer dado clínico — na caixa
        // de entrada tanto quanto na tela. Quem teve o vínculo encerrado também
        // fica de fora (RF10a).
        foreach ($autorizacao->prestador->veterinariosAtivos as $usuario) {
            $usuario->notify($aviso);
        }
    }
}
