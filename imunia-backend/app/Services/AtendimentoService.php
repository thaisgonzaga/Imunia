<?php

namespace App\Services;

use App\Models\AnexoAtendimento;
use App\Models\Animal;
use App\Models\Atendimento;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * T08 — detalhe do atendimento (RF31, RF32, RF33). Monta o prontuário inteiro
 * para leitura, com a autoria à vista e o encadeamento da retificação navegável
 * nos dois sentidos.
 *
 * Não existe método de escrita aqui, e não é omissão: o registro clínico é
 * imutável (RN26) e a única correção possível — a retificação — cria um
 * atendimento novo, trabalho de V09. Ler o prontuário e corrigi-lo são atos
 * distintos, e é bom que estejam em lugares distintos.
 */
class AtendimentoService
{
    /**
     * As seções nomeadas do prontuário, na ordem em que RF31 as enumera e o
     * briefing as desenha. A chave viaja com o rótulo porque é ela que a tela
     * usa para achar a nota explicativa em linguagem simples do termo técnico
     * (T08) e para casar cada seção com a sua versão retificada.
     *
     * Pública desde V09: é também a lista do que a retificação pode corrigir
     * (RF33), e duas listas seriam duas respostas possíveis para "o que muda
     * numa retificação" — uma na tela que compara, outra na que grava.
     */
    public const SECOES = [
        'motivo' => 'Motivo da consulta',
        'anamnese' => 'Anamnese',
        'exame_fisico' => 'Exame físico',
        'hipoteses_diagnosticas' => 'Hipóteses diagnósticas',
        'diagnostico' => 'Diagnóstico',
        'conduta' => 'Conduta',
    ];

    /**
     * @return array<string, mixed>
     */
    public function montarDetalhe(Animal $animal, Atendimento $atendimento): array
    {
        $atendimento->load(['prestador', 'anexos', 'retificacao.prestador', 'original.prestador']);

        return [
            'atendimento' => $this->apresentar($animal, $atendimento),

            // RF33b — o encadeamento nos dois sentidos. Só um dos dois é
            // preenchido em cada registro: ou este foi retificado, ou este é a
            // retificação de outro. Ambos nulos é o caso comum.
            'retificacao' => $this->apresentarRetificacao($animal, $atendimento),
            'original' => $this->apresentarOriginal($animal, $atendimento),
        ];
    }

    /**
     * As entradas deste animal na linha do tempo de T07 (RF35), no formato que
     * `HistoricoConsolidadoService` espera de qualquer fonte.
     *
     * A retificação entra como entrada própria, do tipo `retificacao`, e não
     * como nota da entrada original: ela é um registro clínico autônomo, com
     * data e autoria próprias, e escondê-la dentro do registro que corrige
     * desfaria justamente o que RF33 quer que fique visível. O vínculo viaja em
     * `vinculada_a`, e é a tela que a desenha ligada à original.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function entradasParaHistorico(Animal $animal): Collection
    {
        return $animal->atendimentos()
            ->with(['prestador', 'original'])
            ->withCount('anexos')
            ->get()
            ->map(fn (Atendimento $atendimento) => [
                'id' => $atendimento->id,
                'tipo' => $atendimento->ehRetificacao() ? 'retificacao' : 'atendimento',

                // A retificação de um atendimento é um atendimento, e abre na
                // mesma tela que ele (T08). `tipo` diz o que a entrada é para
                // quem lê a linha do tempo e para o filtro; `registro` diz que
                // registro clínico ela é — que nem sempre é a mesma coisa.
                'registro' => 'atendimento',
                'titulo' => $atendimento->ehRetificacao()
                    ? 'Retificação · '.Str::lower($atendimento->original->titulo)
                    : 'Atendimento · '.Str::lower($atendimento->titulo),
                'resumo' => $this->resumir($atendimento),
                'data' => $atendimento->atendido_em->toDateString(),

                // O atendimento sempre tem data e hora exatas, atribuídas pelo
                // sistema (RF31b): não há aqui o "aproximada" que o registro
                // pregresso de vacinação carrega (RN25).
                'data_aproximada' => false,
                'origem' => 'profissional',
                'prestador' => [
                    'chave' => "prestador-{$atendimento->prestador_id}",
                    'rotulo' => $atendimento->prestador->nome,
                ],
                'aplicador' => $this->responsavel($atendimento),
                'lancado_por' => null,
                'anexos' => $atendimento->anexos_count,

                // Nulo em tudo o que não seja retificação; é o que permite a T07
                // desenhar o conector entre a correção e o registro corrigido.
                'vinculada_a' => $atendimento->retifica_atendimento_id,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function apresentar(Animal $animal, Atendimento $atendimento): array
    {
        return [
            'id' => $atendimento->id,
            'titulo' => $atendimento->titulo,
            'data' => $atendimento->atendido_em->toDateString(),
            'hora' => $atendimento->atendido_em->format('H:i'),

            // Mesmas chaves de uma aplicação de vacina: o chip de procedência é
            // montado no cliente a partir delas, e assim o prontuário e a
            // carteira escrevem a mesma frase sobre o mesmo profissional.
            'origem' => 'profissional',
            'aplicador' => $this->responsavel($atendimento),

            'secoes' => $this->secoes($atendimento),
            'anexos' => $this->anexos($animal, $atendimento),

            // RF34 — data prevista e finalidade descrita, ou nada: um bloco de
            // retorno vazio afirmaria um compromisso que ninguém marcou.
            'retorno' => $atendimento->retorno_em === null ? null : [
                'em' => $atendimento->retorno_em->toDateString(),
                'finalidade' => $atendimento->retorno_finalidade,
            ],
            'eh_retificacao' => $atendimento->ehRetificacao(),

            // RF33 — a correção declara por que existe. Nulo em tudo o que não
            // seja retificação.
            'motivo_retificacao' => $atendimento->motivo_retificacao,
        ];
    }

    /**
     * A retificação deste registro, quando existir (RF33). Vai junto o que
     * mudou, campo a campo: é o que permite a T08 exibir a versão original e a
     * corrigida lado a lado, em vez de pedir ao tutor que compare duas telas.
     *
     * @return array<string, mixed>|null
     */
    private function apresentarRetificacao(Animal $animal, Atendimento $atendimento): ?array
    {
        $retificacao = $atendimento->retificacao;

        if ($retificacao === null) {
            return null;
        }

        return [
            'id' => $retificacao->id,
            'url' => $this->caminhoDe($animal, $retificacao),

            // A data da **correção**, e não a do atendimento: a retificação
            // conserva o `atendido_em` do original, porque a consulta aconteceu
            // quando aconteceu e uma correção com a data de hoje inventaria um
            // atendimento que ninguém prestou. O que o tutor lê aqui é "foi
            // retificado em", e a resposta a isso é a gravação (V09).
            'em' => $retificacao->created_at->toDateString(),
            'motivo' => $retificacao->motivo_retificacao,
            'responsavel' => $this->responsavel($retificacao),
            'campos_alterados' => $this->compararCom($atendimento, $retificacao),
        ];
    }

    /**
     * O registro que este corrige, quando este for uma retificação (RF33b). A
     * comparação viaja também aqui, na ordem contrária: quem lê a correção
     * precisa poder ver o que estava escrito antes, sem sair da tela.
     *
     * @return array<string, mixed>|null
     */
    private function apresentarOriginal(Animal $animal, Atendimento $atendimento): ?array
    {
        $original = $atendimento->original;

        if ($original === null) {
            return null;
        }

        return [
            'id' => $original->id,
            'url' => $this->caminhoDe($animal, $original),

            // Aqui a data é a do registro original, que é quando ele passou a
            // existir — e coincide com a do atendimento em todo registro que
            // não seja, ele próprio, uma retificação.
            'em' => $original->created_at->toDateString(),
            'titulo' => $original->titulo,
            'responsavel' => $this->responsavel($original),
            'campos_alterados' => $this->compararCom($original, $atendimento),
        ];
    }

    /**
     * Os campos em que a retificação difere do original, na ordem das seções.
     * Só os que mudaram: repetir os iguais lado a lado esconderia a correção
     * no meio do que permaneceu.
     *
     * @return array<int, array<string, string|null>>
     */
    private function compararCom(Atendimento $original, Atendimento $retificacao): array
    {
        return collect(self::SECOES)
            ->filter(fn (string $rotulo, string $chave) => $original->{$chave} !== $retificacao->{$chave})
            ->map(fn (string $rotulo, string $chave) => [
                'chave' => $chave,
                'rotulo' => $rotulo,
                'antes' => $original->{$chave},
                'depois' => $retificacao->{$chave},
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function secoes(Atendimento $atendimento): array
    {
        return collect(self::SECOES)
            // A seção sem conteúdo não é desenhada vazia nem preenchida com
            // "não informado": o diagnóstico que ficou pendente de exame está
            // dito na conduta, e um rótulo órfão só ocuparia a tela.
            ->filter(fn (string $rotulo, string $chave) => filled($atendimento->{$chave}))
            ->map(fn (string $rotulo, string $chave) => [
                'chave' => $chave,
                'rotulo' => $rotulo,
                'texto' => $atendimento->{$chave},
            ])
            ->values()
            ->all();
    }

    /**
     * RF32c — o anexo sai com o endereço da rota que confere a autorização a
     * cada pedido, nunca com o caminho no armazenamento. `disponivel` diz se o
     * arquivo responde; quando não responde, T08 avisa naquele item e mantém o
     * resto do prontuário legível.
     *
     * @return array<int, array<string, mixed>>
     */
    private function anexos(Animal $animal, Atendimento $atendimento): array
    {
        return $atendimento->anexos
            ->map(fn (AnexoAtendimento $anexo) => [
                'id' => $anexo->id,
                'descricao' => $anexo->descricao,
                'tipo' => $anexo->tipo,
                'exame_em' => $anexo->exame_em?->toDateString(),
                'url' => $this->caminhoDe($animal, $atendimento)."/anexos/{$anexo->id}",
                'disponivel' => $anexo->disponivel(),
            ])
            ->all();
    }

    /**
     * O resumo de duas linhas da entrada na linha do tempo (T07). No
     * atendimento é o motivo da consulta — o que levou o tutor até lá, que é
     * também como ele reencontra o registro depois. Na retificação é o motivo
     * da correção, seguido do que não muda em nenhuma delas.
     */
    private function resumir(Atendimento $atendimento): string
    {
        if (! $atendimento->ehRetificacao()) {
            return $atendimento->motivo;
        }

        return trim($atendimento->motivo_retificacao ?? '').' As duas versões continuam visíveis.';
    }

    /**
     * @return array<string, string|null>
     */
    private function responsavel(Atendimento $atendimento): array
    {
        return [
            'nome' => $atendimento->profissional_nome,
            'crmv' => $atendimento->profissional_crmv,
            'prestador' => $atendimento->prestador->nome,
        ];
    }

    private function caminhoDe(Animal $animal, Atendimento $atendimento): string
    {
        return "/api/animais/{$animal->codigo}/atendimentos/{$atendimento->id}";
    }
}
