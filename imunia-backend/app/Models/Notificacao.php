<?php

namespace App\Models;

use Database\Factories\NotificacaoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma comunicação já emitida ao tutor sobre uma dose prevista (RF42) ou um
 * retorno programado (RF43), conservada porque RN43 exige registro persistente
 * do que já se enviou.
 *
 * Quem escreve estas linhas é `LembretesAoTutorService`, e quem as lê são duas
 * telas: V02 exibe a última notificação de cada pendência (RF49), que é o dado
 * que decide se a rechamada por telefone é necessária ou redundante; T17
 * mostra ao tutor tudo o que lhe foi enviado (RF45).
 */
#[Fillable(['animal_id', 'tutor_id', 'destinatario', 'imunobiologico_id', 'atendimento_id', 'tipo', 'referente_a', 'enviada_em', 'situacao'])]
class Notificacao extends Model
{
    /** @use HasFactory<NotificacaoFactory> */
    use HasFactory;

    protected $table = 'notificacoes';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'referente_a' => 'date',
            'enviada_em' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Animal, Notificacao>
     */
    public function animal(): BelongsTo
    {
        return $this->belongsTo(Animal::class);
    }

    /**
     * @return BelongsTo<Tutor, Notificacao>
     */
    public function tutor(): BelongsTo
    {
        return $this->belongsTo(Tutor::class);
    }

    /**
     * @return BelongsTo<Imunobiologico, Notificacao>
     */
    public function imunobiologico(): BelongsTo
    {
        return $this->belongsTo(Imunobiologico::class);
    }

    /**
     * O atendimento que marcou o retorno — nulo em toda notificação de dose.
     *
     * @return BelongsTo<Atendimento, Notificacao>
     */
    public function atendimento(): BelongsTo
    {
        return $this->belongsTo(Atendimento::class);
    }

    /**
     * Como V02 anuncia esta notificação na coluna que responde "já foi
     * avisado?". A situação vem junto da data porque uma sem a outra não
     * responde à pergunta: "19/07/2026" não diz se a mensagem chegou.
     *
     * @return array{em: string, situacao: string, texto: string}
     */
    public function paraColuna(): array
    {
        $data = $this->enviada_em->format('d/m/Y');

        return [
            'em' => $this->enviada_em->toDateString(),
            'situacao' => $this->situacao,
            'texto' => "{$data} · ".self::descreverSituacao($this->situacao),
        ];
    }

    /**
     * RN44 — as comunicações de uma dose, e a do retorno (RF43), com os nomes
     * que T16 dá aos interruptores de descadastro. O tutor que desliga "Alerta
     * de atraso" numa tela precisa reconhecer a mesma mensagem na outra.
     *
     * "Aviso na data prevista" não é mais emitido, mas continua nomeado: o
     * histórico de quem o recebeu antes de 01/10/2026 não muda de sentido.
     */
    public static function descreverTipo(string $tipo): string
    {
        return match ($tipo) {
            'aviso_previo' => 'Lembrete de dose prevista',
            'aviso_na_data' => 'Aviso na data prevista',
            'alerta_atraso' => 'Alerta de atraso',
            'lembrete_retorno' => 'Lembrete de retorno',
            default => 'Lembrete de vacina',
        };
    }

    /**
     * A situação em palavras. É a mesma para V02 e T17: o veterinário que lê
     * "sem confirmação" na rechamada e o tutor que lê o mesmo fato no próprio
     * histórico estão falando da mesma mensagem.
     */
    public static function descreverSituacao(string $situacao): string
    {
        return match ($situacao) {
            'entregue' => 'entregue',
            'falhou' => 'não entregue',
            default => 'sem confirmação',
        };
    }
}
