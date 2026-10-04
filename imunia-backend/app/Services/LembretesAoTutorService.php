<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\Atendimento;
use App\Models\Imunobiologico;
use App\Models\Notificacao;
use App\Models\User;
use App\Notifications\LembreteAoTutor;
use App\Notifications\LembreteDeDose;
use App\Notifications\LembreteDeRetorno;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * O motor de lembretes ao tutor (RF42, RF43): a rotina diária que decide quem
 * avisar, grava o aviso em `notificacoes` e o entrega à fila.
 *
 * As três mensagens, como a Thais as fixou em 01/10/2026 (RN44):
 *
 * - **véspera da dose** — um dia antes da data prevista;
 * - **atraso** — no quinto dia de atraso, e uma única vez: depois disso, a
 *   rechamada é da clínica, pelo painel de pendências (RF49);
 * - **véspera do retorno** — um dia antes do retorno marcado no atendimento.
 *
 * A rotina não guarda estado próprio: o que já foi enviado está em
 * `notificacoes`, e é isso que torna inofensivo rodá-la duas vezes no mesmo dia
 * (RNF19) — pelo agendador e pelo disparo externo do plano gratuito, por
 * exemplo.
 */
class LembretesAoTutorService
{
    /** RN44 — o lembrete sai na véspera da data prevista. */
    public const DIAS_DE_ANTECEDENCIA = 1;

    /** RN44 — o alerta sai quando a dose completa cinco dias de atraso. */
    public const DIAS_DE_ATRASO = 5;

    /**
     * Por quantos dias o aviso perdido ainda sai. O disparo diário do plano
     * gratuito vem de fora (GitHub Actions), que às vezes atrasa ou pula uma
     * execução: sem folga, um dia sem rotina seria um aviso que nunca sai. A
     * folga não multiplica mensagens — cada aviso continua saindo uma vez só —,
     * apenas dá a ele mais de uma oportunidade.
     *
     * O lembrete de véspera tem folga de um dia (sai no próprio dia, como
     * "hoje"); passada a data, avisar que ela se aproxima não faz sentido. O
     * alerta de atraso tem dois: sai entre o quinto e o sétimo dia. A folga é
     * também o que impede que, na primeira execução, todas as doses vencidas há
     * meses recebam de uma vez um alerta que nunca foi o delas.
     */
    public const FOLGA_DO_ALERTA_DIAS = 2;

    /** Duração máxima de uma execução, para a trava que impede duas simultâneas. */
    private const TRAVA_SEGUNDOS = 15 * 60;

    public function __construct(private readonly CalendarioVacinalService $calendario) {}

    /**
     * @return array{executada: bool, lembretes_de_dose: int, alertas_de_atraso: int, lembretes_de_retorno: int, falhas: int}
     */
    public function enviar(): array
    {
        $resumo = [
            'executada' => false,
            'lembretes_de_dose' => 0,
            'alertas_de_atraso' => 0,
            'lembretes_de_retorno' => 0,
            'falhas' => 0,
        ];

        // RN43 — a chave única de `notificacoes` não alcança o retorno, cujo
        // imunobiológico é nulo; a trava é o que impede duas execuções
        // simultâneas de lerem "ainda não enviado" ao mesmo tempo. Sequenciais,
        // a segunda já encontra as linhas da primeira.
        $trava = Cache::lock('imunia:lembretes-ao-tutor', self::TRAVA_SEGUNDOS);

        if (! $trava->get()) {
            return $resumo;
        }

        try {
            $hoje = CarbonImmutable::today();
            $this->doses($hoje, $resumo);
            $this->retornos($hoje, $resumo);
            $resumo['executada'] = true;
        } finally {
            $trava->release();
        }

        return $resumo;
    }

    /**
     * RN42 — quem recebe: todo tutor com e-mail cadastrado, tenha ou não criado
     * a senha. A exceção é o tutor que já usa a conta e trocou o endereço
     * (RF06a): o novo só passa a receber depois de confirmado, porque a troca é
     * dele, e um erro de digitação desviaria os lembretes sem que ninguém
     * soubesse. O endereço que a clínica informou no cadastro vale desde o
     * início — foi o tutor quem o deu, no balcão.
     *
     * @param  Builder<User>  $conta
     */
    private function contaQueRecebe(Builder $conta): void
    {
        $conta->whereNotNull('email')
            ->where(fn (Builder $situacao) => $situacao
                ->whereNull('ativado_em')
                ->orWhereNotNull('email_verified_at'));
    }

    /**
     * RF42 — percorre os animais com vacinação, e não as doses, porque a data
     * prevista não está gravada em lugar nenhum: sai do calendário, que é o
     * mesmo de V02 e da carteira (`montarCarteiraCom`). O registro da aplicação
     * cancela os avisos da dose por construção (RF42c) — a dose seguinte passa a
     * ser outra, com outra data.
     *
     * @param  array<string, int|bool>  $resumo
     */
    private function doses(CarbonImmutable $hoje, array &$resumo): void
    {
        $idPorChave = Imunobiologico::pluck('id', 'chave');

        Animal::query()
            // RF22a — o calendário de um animal com óbito já não prevê dose;
            // o filtro só poupa o cálculo.
            ->whereNull('obito_em')
            ->whereHas('vacinacoes')
            ->whereHas('tutor.user', fn (Builder $conta) => $this->contaQueRecebe($conta))
            ->with([
                'tutor.user',
                'vacinacoes' => fn ($consulta) => $consulta->orderBy('aplicado_em'),
                'vacinacoes.imunobiologico',
                'vacinacoes.protocoloVacinal',
                'vacinacoes.prestador',
                'vacinacoes.lancadoPor',
            ])
            ->chunkById(100, function (Collection $animais) use ($hoje, $idPorChave, &$resumo) {
                foreach ($animais as $animal) {
                    $carteira = $this->calendario->montarCarteiraCom($animal, $animal->vacinacoes);

                    foreach ($carteira['proximas_doses'] as $dose) {
                        $tipo = $this->tipoDoAvisoDeDose(CarbonImmutable::parse($dose['prevista_para']), $hoje);
                        $imunobiologicoId = $idPorChave[$dose['imunobiologico_chave']] ?? null;

                        if ($tipo === null || $imunobiologicoId === null) {
                            continue;
                        }

                        $notificacao = $this->registrar($animal, [
                            'imunobiologico_id' => $imunobiologicoId,
                            'tipo' => $tipo,
                            'referente_a' => $dose['prevista_para'],
                        ]);

                        if ($notificacao === null) {
                            continue;
                        }

                        $enviada = $this->entregar($notificacao, $animal->tutor->user, new LembreteDeDose(
                            $notificacao,
                            $animal->tutor->nome,
                            $animal->nome,
                            $animal->codigo,
                            $animal->tutor->user->ativado_em !== null,
                            $dose['imunobiologico'],
                            $dose['rotulo_curto'],
                        ));

                        $resumo[$enviada ? ($tipo === 'alerta_atraso' ? 'alertas_de_atraso' : 'lembretes_de_dose') : 'falhas']++;
                    }
                }
            });
    }

    /**
     * A janela de cada aviso de dose, contada em dias de calendário. Fora das
     * duas, a dose não gera aviso hoje — nem antes da véspera, nem depois da
     * folga do alerta.
     */
    private function tipoDoAvisoDeDose(CarbonImmutable $prevista, CarbonImmutable $hoje): ?string
    {
        $diasAteADose = (int) $hoje->diffInDays($prevista, absolute: false);

        if ($diasAteADose >= 0 && $diasAteADose <= self::DIAS_DE_ANTECEDENCIA) {
            return 'aviso_previo';
        }

        $diasDeAtraso = -$diasAteADose;

        if ($diasDeAtraso >= self::DIAS_DE_ATRASO && $diasDeAtraso <= self::DIAS_DE_ATRASO + self::FOLGA_DO_ALERTA_DIAS) {
            return 'alerta_atraso';
        }

        return null;
    }

    /**
     * RF43 — os retornos que caem amanhã ou hoje. A versão vigente do
     * atendimento é a que vale (RF33): a retificação que mudou a data do
     * retorno mudou o compromisso, e o original não deve mais avisar de nada.
     *
     * RF43a encerra o lembrete quando há atendimento depois da data prevista, o
     * que, nesta janela — a data ainda não passou —, não acontece.
     *
     * @param  array<string, int|bool>  $resumo
     */
    private function retornos(CarbonImmutable $hoje, array &$resumo): void
    {
        Atendimento::query()
            ->whereBetween('retorno_em', [$hoje->toDateString(), $hoje->addDays(self::DIAS_DE_ANTECEDENCIA)->toDateString()])
            ->whereDoesntHave('retificacao')
            ->whereHas('animal', fn (Builder $animal) => $animal
                ->whereNull('obito_em')
                ->whereHas('tutor.user', fn (Builder $conta) => $this->contaQueRecebe($conta)))
            ->with(['animal.tutor.user', 'prestador'])
            ->orderBy('retorno_em')
            ->orderBy('id')
            ->get()
            // Um aviso por animal e data, ainda que dois atendimentos marquem
            // retorno para o mesmo dia: o tutor vai à clínica uma vez.
            ->unique(fn (Atendimento $atendimento) => $atendimento->animal_id.'|'.$atendimento->retorno_em->toDateString())
            ->each(function (Atendimento $atendimento) use (&$resumo) {
                $animal = $atendimento->animal;

                $notificacao = $this->registrar($animal, [
                    'imunobiologico_id' => null,
                    'atendimento_id' => $atendimento->id,
                    'tipo' => 'lembrete_retorno',
                    'referente_a' => $atendimento->retorno_em->toDateString(),
                ]);

                if ($notificacao === null) {
                    return;
                }

                $enviada = $this->entregar($notificacao, $animal->tutor->user, new LembreteDeRetorno(
                    $notificacao,
                    $animal->tutor->nome,
                    $animal->nome,
                    $animal->codigo,
                    $animal->tutor->user->ativado_em !== null,
                    $atendimento->prestador->nome,
                    $atendimento->retorno_finalidade,
                ));

                $resumo[$enviada ? 'lembretes_de_retorno' : 'falhas']++;
            });
    }

    /**
     * RN43 — grava o aviso antes de enviá-lo, ou devolve nulo se ele já saiu.
     *
     * O evento é animal, assunto (imunobiológico ou retorno), tipo e data
     * prevista — os mesmos campos da chave única de 17/08. A linha que ficou
     * como "não entregue" é reaproveitada: é a nova tentativa de RF42e, que só
     * acontece enquanto o aviso estiver dentro da janela, porque fora dela
     * esta função nem é chamada.
     *
     * @param  array{imunobiologico_id: ?int, atendimento_id?: int, tipo: string, referente_a: string}  $evento
     */
    private function registrar(Animal $animal, array $evento): ?Notificacao
    {
        $existente = Notificacao::query()
            ->where('animal_id', $animal->id)
            ->where('imunobiologico_id', $evento['imunobiologico_id'])
            ->where('tipo', $evento['tipo'])
            ->whereDate('referente_a', $evento['referente_a'])
            ->first();

        if ($existente !== null && $existente->situacao !== 'falhou') {
            return null;
        }

        $notificacao = $existente ?? new Notificacao(['animal_id' => $animal->id, ...$evento]);

        // O destinatário é um retrato do endereço no momento do envio (RF45):
        // na nova tentativa, vale o endereço de agora.
        $notificacao->fill([
            'tutor_id' => $animal->tutor_id,
            'destinatario' => $animal->tutor->user->email,
            'enviada_em' => now(),
            'situacao' => 'sem_confirmacao',
        ])->save();

        return $notificacao;
    }

    /**
     * RNF18 — a falha de um envio não interrompe os demais. Com a fila
     * síncrona do plano gratuito, a exceção do provedor chega até aqui (depois
     * de `LembreteAoTutor::failed()` já ter marcado a linha); com fila de
     * verdade, o envio acontece depois, e a marcação fica só com a fila.
     */
    private function entregar(Notificacao $notificacao, User $conta, LembreteAoTutor $mensagem): bool
    {
        try {
            $conta->notify($mensagem);

            return true;
        } catch (Throwable $erro) {
            $notificacao->forceFill(['situacao' => 'falhou'])->save();

            Log::warning('Lembrete ao tutor não enviado', [
                'notificacao_id' => $notificacao->id,
                'tipo' => $notificacao->tipo,
                'erro' => $erro->getMessage(),
            ]);

            return false;
        }
    }
}
