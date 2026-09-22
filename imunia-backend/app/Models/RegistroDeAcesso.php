<?php

namespace App\Models;

use Database\Factories\RegistroDeAcessoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma linha do livro de acessos (RF52, RN49): quem olhou o quê, e quando.
 *
 * É registro de auditoria, e por isso imutável (RF52a) — não há caminho de
 * escrita que atualize uma linha destas, e a tabela sequer tem `updated_at`
 * para tanto. A leitura pelo tutor é T14 (RF53), fatia própria.
 *
 * Nesta fatia grava-se apenas a consulta feita **sem autorização vigente**
 * (RF18b): a que devolve ao profissional só a existência do cadastro. A
 * visualização de histórico produzido por outro prestador, que é o outro caso
 * de RF52, entra com a fatia de V06.
 */
#[Fillable([
    'prestador_id',
    'user_id',
    'tutor_id',
    'animal_id',
    'natureza',
    'ocorrido_em',
])]
class RegistroDeAcesso extends Model
{
    /** @use HasFactory<RegistroDeAcessoFactory> */
    use HasFactory;

    protected $table = 'registros_de_acesso';

    /** RF52a — nada aqui é atualizado, e por isso não há o que carimbar. */
    public $timestamps = false;

    /**
     * O vocabulário de "natureza do dado acessado" (RF52). Os quatro termos
     * desta fatia dizem por qual chave se procurou, e é essa a informação que
     * T14 devolve ao tutor: "pesquisaram o seu CPF" e "pesquisaram o código do
     * Théo" são fatos diferentes para quem os lê.
     */
    public const BUSCA_POR_CPF = 'busca_por_cpf';

    public const BUSCA_POR_CODIGO = 'busca_por_codigo';

    public const BUSCA_POR_MICROCHIP = 'busca_por_microchip';

    public const BUSCA_POR_NOME = 'busca_por_nome';

    /**
     * Os dois termos da fatia de V06, que são o outro caso de RF52 — não mais
     * a busca que revela existência, e sim a ficha aberta.
     *
     * A distinção entre eles é a que RF52c manda o log fazer: abrir a ficha de
     * um animal que ninguém autorizou é fato diferente de ler, com autorização
     * vigente, o registro que outra clínica produziu. Quem lê T14 precisa poder
     * dizer qual dos dois aconteceu.
     */
    public const FICHA_SEM_AUTORIZACAO = 'ficha_sem_autorizacao';

    public const HISTORICO_DE_OUTRO_PRESTADOR = 'historico_de_outro_prestador';

    /**
     * O termo da fatia de V05: o alerta de duplicidade (RF20a) revelou, a quem
     * cadastrava um animal fora do próprio âmbito, que o tutor já tem um
     * parecido. É revelação de existência como a da busca — descobrir pelo
     * formulário é descobrir por busca com outro nome —, e por isso presta
     * contas do mesmo jeito (RF18b).
     */
    public const ALERTA_DE_DUPLICIDADE = 'alerta_de_duplicidade';

    /**
     * O termo da exportação pela clínica (RF46, ator veterinário): o documento
     * emitido levava registro de outro prestador, e levou-o para fora da
     * plataforma. Não é a mesma linha de `HISTORICO_DE_OUTRO_PRESTADOR` — ler
     * na tela e emitir um arquivo que circula são fatos diferentes para o
     * tutor que consulta T14, e RN46 é o motivo de a diferença importar.
     */
    public const EXPORTACAO_DE_REGISTRO_ALHEIO = 'exportacao_de_registro_alheio';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ocorrido_em' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Prestador, RegistroDeAcesso>
     */
    public function prestador(): BelongsTo
    {
        return $this->belongsTo(Prestador::class);
    }

    /**
     * O profissional que praticou o ato. RF53a manda identificá-lo ao tutor,
     * com o prestador — não basta dizer "a clínica consultou".
     *
     * @return BelongsTo<User, RegistroDeAcesso>
     */
    public function profissional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Tutor, RegistroDeAcesso>
     */
    public function tutor(): BelongsTo
    {
        return $this->belongsTo(Tutor::class);
    }

    /**
     * @return BelongsTo<Animal, RegistroDeAcesso>
     */
    public function animal(): BelongsTo
    {
        return $this->belongsTo(Animal::class);
    }
}
