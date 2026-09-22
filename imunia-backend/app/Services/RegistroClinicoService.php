<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\Atendimento;
use App\Models\Prestador;
use App\Models\RegistroDeAcesso;
use App\Models\User;
use App\Models\Vacinacao;

/**
 * V09 — a leitura de um registro clínico determinado no ambiente do
 * veterinário, e a porta de onde sai a retificação (RF31, RF25, RF33).
 *
 * Existe porque T06 e T08 são telas do **tutor**: as rotas que as servem exigem
 * tutor autenticado e respondem 403 a quem escreveu o prontuário. A decisão de
 * V08 já anotava isso — "a tela de detalhe clínico do atendimento é fatia de
 * V09" —, e é esta classe que a cumpre.
 *
 * Três diferenças em relação à leitura do tutor, todas de âmbito:
 *
 * 1. o âmbito é a autorização vigente do prestador ativo, e não a titularidade
 *    (RN48);
 * 2. abrir o registro de outro prestador grava a linha do livro de acessos
 *    (RF52, RN49) — o mesmo que V06 faz quando a ficha traz histórico alheio, e
 *    pela mesma razão: chegar por endereço direto não pode custar menos do que
 *    chegar pela ficha;
 * 3. a resposta diz se a ação de retificar cabe a quem está lendo (RN27), para
 *    que a tela não a desenhe a quem ela não cabe (RNF09).
 */
class RegistroClinicoService
{
    public function __construct(
        private readonly AtendimentoService $atendimentos,
        private readonly CalendarioVacinalService $calendario,
        private readonly RetificacaoDeRegistroService $retificacao,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function montarAtendimento(
        User $profissional,
        Prestador $prestador,
        Animal $animal,
        Atendimento $atendimento,
    ): array {
        $this->registrarAcessoSeAlheio($profissional, $prestador, $animal, $atendimento->prestador_id);

        return [
            'animal' => $animal->paraListagem(),
            'registro' => 'atendimento',
            ...$this->comAnexosDaRotaClinica($animal, $this->atendimentos->montarDetalhe($animal, $atendimento)),

            // RN27 — e a chave viaja sempre, inclusive falsa. A tela precisa
            // saber que **não** deve desenhar a ação, o que é diferente de não
            // receber notícia dela.
            'pode_retificar' => $this->retificacao
                ->podeRetificarAtendimento($profissional, $prestador, $atendimento),

            // O formulário de V09 abre preenchido com o que está gravado: a
            // retificação é uma correção, e o profissional não deve redigitar o
            // prontuário inteiro para trocar uma palavra do diagnóstico.
            'campos' => $this->retificacao->camposDoAtendimento($atendimento),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function montarVacinacao(
        User $profissional,
        Prestador $prestador,
        Animal $animal,
        Vacinacao $vacinacao,
    ): array {
        $this->registrarAcessoSeAlheio($profissional, $prestador, $animal, $vacinacao->prestador_id);

        return [
            'animal' => $animal->paraListagem(),
            'registro' => 'vacinacao',
            ...$this->calendario->detalheAplicacao($animal, $vacinacao),

            // RF33b — o encadeamento nos dois sentidos, como T08 já o entrega
            // para o atendimento. Só um dos dois vem preenchido em cada
            // registro; ambos nulos é o caso comum.
            'retificacao' => $this->apresentarRetificacao($animal, $vacinacao),
            'original' => $this->apresentarOriginal($animal, $vacinacao),

            'pode_retificar' => $this->retificacao
                ->podeRetificarVacinacao($profissional, $prestador, $vacinacao),
            'campos' => $this->retificacao->camposDaVacinacao($vacinacao),
        ];
    }

    /**
     * RF32c — o mesmo prontuário de T08, com os anexos apontando para a rota do
     * ambiente clínico.
     *
     * `AtendimentoService` monta os endereços da rota do tutor, que pergunta
     * pela titularidade — e o veterinário não é titular de animal nenhum. A
     * rota clínica pergunta outra coisa, a autorização vigente do prestador
     * ativo, e é ela que responde por este arquivo. Duas portas, uma
     * verificação em cada.
     *
     * @param  array<string, mixed>  $detalhe
     * @return array<string, mixed>
     */
    private function comAnexosDaRotaClinica(Animal $animal, array $detalhe): array
    {
        $detalhe['atendimento']['anexos'] = collect($detalhe['atendimento']['anexos'])
            ->map(fn (array $anexo) => [
                ...$anexo,
                'url' => "/api/clinica/animais/{$animal->codigo}/anexos/{$anexo['id']}",
            ])
            ->all();

        return $detalhe;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function apresentarRetificacao(Animal $animal, Vacinacao $vacinacao): ?array
    {
        $retificacao = $vacinacao->retificacao;

        if ($retificacao === null) {
            return null;
        }

        return [
            'id' => $retificacao->id,
            'url' => $this->caminhoDe($animal, $retificacao),

            // A data da correção é a da gravação, e não a da aplicação: esta
            // continua sendo a do dia em que a dose foi dada, inclusive quando
            // foi ela que a correção mudou.
            'em' => $retificacao->created_at->toDateString(),
            'motivo' => $retificacao->motivo_retificacao,
            'responsavel' => $this->responsavel($retificacao),
            'campos_alterados' => $this->retificacao->compararVacinacoes($vacinacao, $retificacao),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function apresentarOriginal(Animal $animal, Vacinacao $vacinacao): ?array
    {
        $original = $vacinacao->original;

        if ($original === null) {
            return null;
        }

        return [
            'id' => $original->id,
            'url' => $this->caminhoDe($animal, $original),
            'em' => $original->created_at->toDateString(),
            'responsavel' => $this->responsavel($original),
            'campos_alterados' => $this->retificacao->compararVacinacoes($original, $vacinacao),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function responsavel(Vacinacao $vacinacao): array
    {
        return [
            'nome' => $vacinacao->aplicador_nome,
            'crmv' => $vacinacao->aplicador_crmv,
            'prestador' => $vacinacao->prestador?->nome,
        ];
    }

    private function caminhoDe(Animal $animal, Vacinacao $vacinacao): string
    {
        return "/clinica/animais/{$animal->codigo}/vacinas/{$vacinacao->id}";
    }

    /**
     * RN49 — "registro clínico originado de outro prestador". A linha é gravada
     * antes de a resposta ser montada, e não depois de enviada (RF52b): se ela
     * não puder ser gravada, o registro alheio não é exibido.
     */
    private function registrarAcessoSeAlheio(
        User $profissional,
        Prestador $prestador,
        Animal $animal,
        ?int $prestadorDoRegistro,
    ): void {
        // O histórico pregresso não tem prestador e não conta: foi o próprio
        // tutor quem o lançou, e dizer-lhe em T14 que uma clínica leu o que ele
        // escreveu sobre o próprio animal seria falso (RN24).
        if ($prestadorDoRegistro === null || $prestadorDoRegistro === $prestador->id) {
            return;
        }

        RegistroDeAcesso::create([
            'prestador_id' => $prestador->id,
            'user_id' => $profissional->id,
            'tutor_id' => $animal->tutor_id,
            'animal_id' => $animal->id,
            'natureza' => RegistroDeAcesso::HISTORICO_DE_OUTRO_PRESTADOR,
            'ocorrido_em' => now(),
        ]);
    }
}
