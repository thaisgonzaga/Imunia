<?php

namespace App\Models;

use Database\Factories\SolicitacaoAcessoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pedido de autorização feito por um prestador ao tutor de um animal (RF38).
 *
 * O que este modelo *não* faz é a sua característica mais importante: nada aqui
 * confere acesso. A existência de uma linha pendente não muda o âmbito de RN48
 * em coisa alguma, e o prestador que pede continua sem ver nome de vacina,
 * atendimento ou anexo (RF38a). O que a linha produz é uma pergunta na tela do
 * tutor — e a resposta dela, quando afirmativa, é uma autorização, que é outra
 * tabela.
 *
 * Como na autorização, a situação não é coluna: é o que as datas dizem.
 */
#[Fillable([
    'animal_id',
    'prestador_id',
    'solicitada_por_user_id',
    'mensagem',
    'solicitada_em',
    'expira_em',
    'recusada_em',
    'atendida_em',
])]
class SolicitacaoAcesso extends Model
{
    /** @use HasFactory<SolicitacaoAcessoFactory> */
    use HasFactory;

    protected $table = 'solicitacoes_acesso';

    /**
     * RF38b — o prazo em que o pedido espera resposta. Sete dias, o mesmo do
     * convite de ativação: é quanto dura, no tutor, a lembrança do atendimento
     * em que o pedido foi feito. Passado isso, o pedido não some — caduca, e a
     * tela o mostra esmaecido, porque saber que alguém pediu continua sendo
     * informação do titular.
     */
    public const PRAZO_DIAS = 7;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'solicitada_em' => 'datetime',
            'expira_em' => 'datetime',
            'recusada_em' => 'datetime',
            'atendida_em' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Animal, SolicitacaoAcesso>
     */
    public function animal(): BelongsTo
    {
        return $this->belongsTo(Animal::class);
    }

    /**
     * @return BelongsTo<Prestador, SolicitacaoAcesso>
     */
    public function prestador(): BelongsTo
    {
        return $this->belongsTo(Prestador::class);
    }

    /**
     * O veterinário que pediu. RF38c manda dizer ao tutor quem solicitou, e o
     * estabelecimento sozinho não responde a isso: quem pratica ato no sistema
     * é pessoa identificada.
     *
     * @return BelongsTo<User, SolicitacaoAcesso>
     */
    public function solicitadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitada_por_user_id');
    }

    /**
     * Pendente: sem resposta do tutor, sem baixa por concessão e dentro do
     * prazo. As quatro condições juntas em um lugar só, porque é este recorte
     * que o contador da moldura anuncia e que a tela cobra resposta.
     *
     * @param  Builder<SolicitacaoAcesso>  $consulta
     */
    #[Scope]
    protected function pendente(Builder $consulta): void
    {
        $consulta->whereNull('recusada_em')
            ->whereNull('atendida_em')
            ->where('expira_em', '>', now());
    }

    public function estaPendente(): bool
    {
        return $this->recusada_em === null
            && $this->atendida_em === null
            && $this->expira_em->isFuture();
    }

    /**
     * Quantos dias faltam para o pedido caducar. Negativo depois disso, que é o
     * que permite dizer há quanto tempo ele caiu sem uma segunda conta.
     */
    public function diasRestantes(): int
    {
        return (int) Carbon::today()->diffInDays($this->expira_em, absolute: false);
    }

    /**
     * A situação que a tela desenha. Quatro, e cada uma pede coisa diferente do
     * tutor: responder, nada (já respondeu), nada (o pedido caiu) e nada (ele
     * mesmo autorizou). As três últimas existem porque o histórico de quem
     * pediu acesso aos seus animais é informação do titular, e apagá-la da tela
     * seria devolver ao prestador o direito de perguntar de novo sem que o
     * tutor lembrasse da primeira vez.
     *
     * @return 'pendente'|'atendida'|'recusada'|'expirada'
     */
    public function situacao(): string
    {
        if ($this->atendida_em !== null) {
            return 'atendida';
        }

        if ($this->recusada_em !== null) {
            return 'recusada';
        }

        return $this->expira_em->isFuture() ? 'pendente' : 'expirada';
    }
}
