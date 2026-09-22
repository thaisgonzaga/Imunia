<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\Notificacao;
use App\Models\Tutor;
use App\Support\EnderecoDeEmail;

/**
 * O histórico de notificações lido pelo tutor (T17, RF45).
 *
 * A tela responde à pergunta que, no cenário, ninguém conseguia responder —
 * este tutor já foi avisado, e quando? —, agora feita pelo próprio tutor. Por
 * isso cada linha diz, além do quê e do quando, para onde a mensagem foi e se
 * ela chegou: "enviada em 10/09" não serve a quem está procurando o e-mail na
 * caixa de entrada.
 */
class HistoricoDeNotificacoesService
{
    /**
     * @return array<string, mixed>
     */
    public function listar(Tutor $tutor): array
    {
        $usuario = $tutor->user;

        // O âmbito é `tutor_id`, e não a titularidade do animal: a mensagem foi
        // para quem era o tutor quando ela saiu. Depois de uma transferência
        // (RF21), o antigo tutor continua sabendo o que recebeu, e o novo não
        // passa a ver lembretes que nunca lhe foram enviados.
        $notificacoes = Notificacao::query()
            ->where('tutor_id', $tutor->id)
            ->with(['animal', 'imunobiologico'])
            ->orderByDesc('enviada_em')
            ->orderByDesc('id')
            ->get();

        return [
            // RN42 — sem endereço verificado nenhum lembrete sai, e o histórico
            // vazio precisa poder dizer o porquê.
            'conta' => [
                'email' => $usuario->email,
                'email_verificado' => $usuario->hasVerifiedEmail(),
            ],
            'total' => $notificacoes->count(),
            'notificacoes' => $notificacoes
                ->map(fn (Notificacao $notificacao) => $this->linha($notificacao, $tutor, $usuario->email))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function linha(Notificacao $notificacao, Tutor $tutor, string $enderecoAtual): array
    {
        return [
            'id' => $notificacao->id,
            'enviada_em' => $notificacao->enviada_em->toIso8601String(),
            'data' => $notificacao->enviada_em->toDateString(),
            'hora' => $notificacao->enviada_em->format('H:i'),
            'tipo' => $notificacao->tipo,
            'tipo_rotulo' => Notificacao::descreverTipo($notificacao->tipo),
            'animal' => $this->cartaoDoAnimal($notificacao->animal, $tutor),

            // A dose de que o aviso tratava. Nulo quando a aplicação de origem
            // não identifica a vacina (RF29) — e a tela diz isso, em vez de
            // deixar o campo em branco.
            'vacina' => $notificacao->imunobiologico?->nome_comercial,
            'referente_a' => $notificacao->referente_a->toDateString(),

            // O endereço mascarado, como na confirmação de e-mail: basta para o
            // tutor se reconhecer, e a tela não precisa repetir por inteiro o
            // dado que ele já sabe.
            'destinatario' => EnderecoDeEmail::mascarar($notificacao->destinatario),

            // A mensagem foi para um endereço que a conta já não usa. É o que
            // explica, ao lado de um "não entregue", que os próximos lembretes
            // não têm por que falhar do mesmo jeito.
            'endereco_anterior' => strcasecmp($notificacao->destinatario, $enderecoAtual) !== 0,

            'situacao' => $notificacao->situacao,
            'situacao_rotulo' => ucfirst(Notificacao::descreverSituacao($notificacao->situacao)),
            'explicacao' => $this->explicar($notificacao->situacao),
        ];
    }

    /**
     * O briefing pede "falha de envio com explicação em linguagem simples", e
     * as outras duas situações pedem a mesma coisa pelo motivo oposto: quem
     * abre o detalhe de uma mensagem entregue quase sempre está procurando por
     * ela e não a achou.
     */
    private function explicar(string $situacao): string
    {
        return match ($situacao) {
            'entregue' => 'O seu provedor de e-mail confirmou que recebeu a mensagem. Se ela não está na caixa de entrada, procure na pasta de spam ou de promoções.',
            'falhou' => 'O seu provedor de e-mail recusou a mensagem, e ela não chegou até você. Isso costuma acontecer quando o endereço tem um erro de digitação, foi desativado ou está com a caixa cheia.',
            default => 'A mensagem saiu do Imunia, mas o seu provedor de e-mail não confirmou o recebimento. Isso é comum e não quer dizer que ela se perdeu: procure na caixa de entrada e na pasta de spam.',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function cartaoDoAnimal(Animal $animal, Tutor $tutor): array
    {
        return [
            'codigo' => $animal->codigo,
            'nome' => $animal->nome,
            'especie' => $animal->especie,

            // A carteira é da titularidade (RF28): depois de uma transferência,
            // oferecer o caminho até ela levaria o antigo tutor a uma recusa.
            'do_tutor' => $animal->tutor_id === $tutor->id,
        ];
    }
}
