<?php

namespace App\Models;

use App\Services\CalendarioVacinalService;
use App\Support\CodigoDoAnimal;
use Database\Factories\AnimalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Os campos de caracterização — raça, pelagem, situação reprodutiva e
 * micro-chip — são privativos do veterinário (RF19, RN18) e entraram com a
 * fatia de V05, que construiu o fluxo que os escreve. Estão no preenchimento
 * em massa porque quem barra o tutor não é esta lista: é a FormRequest de T03,
 * que recusa o pedido inteiro quando qualquer um deles chega (RF16e). O peso
 * não tem coluna aqui de propósito — é medição datada, vinculada ao
 * atendimento em que foi aferida (`atendimentos.peso_aferido`, observação de
 * RF19). `codigo` segue fora: é do sistema (RF17).
 */
#[Fillable([
    'tutor_id',
    'nome',
    'especie',
    'sexo',
    'nascimento_em',
    'nascimento_exato',
    'foto_caminho',
    'raca',
    'pelagem',
    'situacao_reprodutiva',
    'microchip',
    'caracterizado_em',
    'caracterizado_por_user_id',
])]
class Animal extends Model
{
    /** @use HasFactory<AnimalFactory> */
    use HasFactory;

    protected $table = 'animais';

    /**
     * RN20 — a fotografia é dado de identificação, e não registro clínico: fica
     * no disco público, ao contrário do anexo de prontuário, e o tutor a
     * substitui quando quiser. O que ela observa são as restrições abaixo.
     *
     * A conferência é do conteúdo (`mimetypes`), não da extensão. O limite é
     * menor que o do anexo porque aqui não há documento a preservar: é a foto
     * que o tutor tira do próprio animal, e 5 MB comportam qualquer celular.
     */
    public const DISCO_DA_FOTO = 'public';

    public const PASTA_DA_FOTO = 'animais/fotos';

    /** @var list<string> */
    public const MIMES_DA_FOTO = ['image/jpeg', 'image/png', 'image/webp'];

    public const TAMANHO_MAXIMO_DA_FOTO_KB = 5120;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nascimento_em' => 'date',
            'nascimento_exato' => 'boolean',
            'caracterizado_em' => 'datetime',
            'obito_em' => 'date',
        ];
    }

    protected static function booted(): void
    {
        // RF17c — o código nasce com o cadastro, seja qual for a origem dele.
        static::creating(function (Animal $animal): void {
            $animal->codigo ??= CodigoDoAnimal::gerar();
        });
    }

    /**
     * @return BelongsTo<Tutor, Animal>
     */
    public function tutor(): BelongsTo
    {
        return $this->belongsTo(Tutor::class);
    }

    /**
     * Registros de aplicação (RF25) e de histórico pregresso (RF29) deste
     * animal. É a base sobre a qual a carteira (T05) e o cálculo do
     * calendário (RF26) são construídos.
     *
     * @return HasMany<Vacinacao, Animal>
     */
    public function vacinacoes(): HasMany
    {
        return $this->hasMany(Vacinacao::class);
    }

    /**
     * Atendimentos registrados para este animal (RF31), de qualquer prestador —
     * inclusive as retificações (RF33), que são atendimentos como os demais e
     * por isso não têm relação própria: o que as distingue é apontarem para o
     * registro que corrigem.
     *
     * @return HasMany<Atendimento, Animal>
     */
    public function atendimentos(): HasMany
    {
        return $this->hasMany(Atendimento::class);
    }

    /**
     * Autorizações de acesso concedidas pelo tutor sobre este animal (RF36) —
     * todas elas, inclusive as expiradas e as revogadas, porque RN41 manda
     * conservá-las. Quem quer saber quem pode ver o histórico agora filtra por
     * `vigente()`, e o escopo abaixo é o caminho curto para isso.
     *
     * @return HasMany<Autorizacao, Animal>
     */
    public function autorizacoes(): HasMany
    {
        return $this->hasMany(Autorizacao::class);
    }

    /**
     * RN48 — o âmbito de toda consulta agregada do prestador. Fica aqui, e não
     * repetido em cada consulta do painel, porque esquecer este filtro em um só
     * lugar já é expor o histórico de um animal que ninguém autorizou.
     *
     * @param  Builder<Animal>  $consulta
     */
    #[Scope]
    protected function sobAutorizacaoVigenteDe(Builder $consulta, Prestador $prestador): void
    {
        $consulta->whereHas(
            'autorizacoes',
            fn (Builder $autorizacoes) => $autorizacoes->where('prestador_id', $prestador->id)->vigente(),
        );
    }

    /**
     * O profissional que registrou o óbito (RF22). Preservado como a autoria de
     * qualquer registro clínico (RN27): a ficha o nomeia, e nem o encerramento
     * do vínculo dele com o prestador apaga a linha.
     *
     * @return BelongsTo<User, Animal>
     */
    public function obitoRegistradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'obito_registrado_por_user_id');
    }

    /**
     * O prestador em cujo âmbito o óbito foi registrado (V12). É o que dá
     * procedência à entrada da linha do tempo (P1) e o que delimita a
     * retificação futura: privativa do autor, dentro do prestador que produziu
     * o registro (RN27).
     *
     * @return BelongsTo<Prestador, Animal>
     */
    public function obitoPrestador(): BelongsTo
    {
        return $this->belongsTo(Prestador::class, 'obito_prestador_id');
    }

    /**
     * O veterinário que caracterizou o animal (RF19c) — autor, com a data em
     * `caracterizado_em`. Atualizado a cada manutenção da caracterização,
     * porque o requisito registra toda alteração, não só a primeira.
     *
     * @return BelongsTo<User, Animal>
     */
    public function caracterizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caracterizado_por_user_id');
    }

    /**
     * RN17 — o cadastro iniciado pelo tutor permanece preliminar até que um
     * médico-veterinário o complete. A condição é visível em todas as telas
     * (RF16d), e é daqui que ela sai.
     */
    public function preliminar(): bool
    {
        return $this->caracterizado_em === null;
    }

    /**
     * RF22a — registrado o óbito, cessam o cálculo do calendário e os
     * lembretes. A ficha clínica (V06) usa a mesma resposta para suprimir as
     * ações de registro: não há vacinação nem atendimento a marcar para um
     * animal que morreu, e a única escrita que resta é a retificação de um
     * registro já existente (RF33).
     */
    public function inativo(): bool
    {
        return $this->obito_em !== null;
    }

    /**
     * Há ato clínico registrado sobre este animal — vacinação (RF25), histórico
     * pregresso (RF29), atendimento (RF31) ou óbito (RF22). É o que trava a
     * espécie: RF19d a declara inalterável depois do primeiro registro clínico,
     * porque protocolo, dose e calendário foram calculados para a espécie que
     * constava ali.
     *
     * O óbito entra na conta ainda que não haja vacinação nem atendimento: é
     * registro de veterinário como os demais (RN27), e corrigir a espécie de um
     * animal falecido não é correção de cadastro — é reescrever o que alguém
     * assinou.
     */
    public function possuiRegistroClinico(): bool
    {
        return $this->obito_em !== null
            || $this->vacinacoes()->exists()
            || $this->atendimentos()->exists();
    }

    /**
     * Idade em meses completos, ou nulo quando não se sabe a data. Quem exibe o
     * resultado precisa exibir junto `nascimento_exato`: RN14 manda propagar a
     * natureza da informação a todo cálculo dela derivado, e "18 meses" a
     * partir de estimativa não é a mesma afirmação que "18 meses" a partir de
     * data confirmada por veterinário.
     */
    public function idadeEmMeses(): ?int
    {
        if ($this->nascimento_em === null) {
            return null;
        }

        return (int) $this->nascimento_em->diffInMonths(now());
    }

    /**
     * RN20 — a fotografia é dado de identificação e é servida por rota própria,
     * nunca por endereço direto do armazenamento.
     */
    public function fotoUrl(): ?string
    {
        if ($this->foto_caminho === null) {
            return null;
        }

        $disco = Storage::disk(self::DISCO_DA_FOTO);

        // Em produção o disco é um bucket privado (config/filesystems.php): o
        // endereço é assinado e expira, e quem o copiar da tela não leva a
        // foto consigo. As telas pedem o perfil de novo a cada visita, e cada
        // pedido traz um endereço novo.
        if ($disco->providesTemporaryUrls()) {
            return $disco->temporaryUrl($this->foto_caminho, now()->addHours(2));
        }

        return $disco->url($this->foto_caminho);
    }

    /**
     * Representação usada por toda tela que lista o animal — o painel (T01) e a
     * relação de animais (T02). Um lugar só, para que "preliminar" e a idade
     * declarada signifiquem o mesmo nos dois lugares em que aparecem.
     *
     * @return array<string, mixed>
     */
    public function paraListagem(): array
    {
        return [
            'codigo' => $this->codigo,
            'nome' => $this->nome,
            'especie' => $this->especie,
            'idade_em_meses' => $this->idadeEmMeses(),
            'nascimento_exato' => $this->nascimento_exato, // RN14
            'foto_url' => $this->fotoUrl(),
            'preliminar' => $this->preliminar(), // RN17

            // Situação da dose mais urgente, que o cartão exibe como etiqueta.
            // Nula enquanto não houver vacinação registrada (RF26) — nula é o
            // que a tela precisa receber para não desenhar etiqueta nenhuma.
            'situacao' => app(CalendarioVacinalService::class)->situacaoGeral($this),
        ];
    }

    /**
     * Representação do perfil do animal (T04). Estende `paraListagem()` com o
     * que só a tela dedicada precisa: o resumo da situação vacinal e o bloco
     * de caracterização.
     *
     * @return array<string, mixed>
     */
    public function paraPerfil(): array
    {
        $listagem = $this->paraListagem();

        return [
            ...$listagem,
            'caracterizacao' => $this->caracterizacao(),

            // Identificação como o formulário de edição (T04a) precisa relê-la.
            // O sexo e o nascimento viajam crus, e não só a idade derivada: é o
            // que o tutor escreveu, e é o que ele volta para corrigir.
            'sexo' => $this->sexo,

            // O mês e o ano, no formato que o campo aceita. Quem declarou só o
            // ano recebe de volta `01/aaaa`, porque a coluna guarda o primeiro
            // dia do período (RN14, `DataAproximada`) e o dia não distingue as
            // duas declarações. Reenviar o que vem aqui grava a mesma data.
            'nascimento' => $this->nascimento_em?->format('m/Y'),

            // RF19d — a tela precisa saber antes de oferecer o campo, e não
            // depois de o servidor recusar.
            'especie_alteravel' => ! $this->possuiRegistroClinico(),

            // Mesmo vocabulário do painel (T01) — 'sem_registros' enquanto não
            // houver vacinação, para não afirmar "em dia" sobre o que o
            // sistema simplesmente ainda não sabe.
            'situacao_vacinal' => $listagem['situacao'] === null ? 'sem_registros' : 'com_registros',
        ];
    }

    /**
     * O bloco de caracterização (RF19), ou nulo enquanto o cadastro é
     * preliminar — é o nulo que faz T04 desenhar o `EmptyState` que explica a
     * divisão de responsabilidade. Sexo e nascimento não entram: são
     * identificação (RF16) e já viajam soltos; o que este bloco carrega é o
     * que só o veterinário escreve, com autor e data (RF19c).
     *
     * @return array<string, mixed>|null
     */
    public function caracterizacao(): ?array
    {
        if ($this->preliminar()) {
            return null;
        }

        return [
            'raca' => $this->raca,
            'pelagem' => $this->pelagem,
            'situacao_reprodutiva' => $this->situacao_reprodutiva,
            'microchip' => $this->microchip,
            'caracterizado_em' => $this->caracterizado_em?->toDateString(),
            'caracterizado_por' => $this->caracterizadoPor?->name,
        ];
    }
}
