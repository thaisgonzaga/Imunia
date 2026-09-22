<?php

namespace App\Models;

use Database\Factories\ImunobiologicoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catálogo de imunobiológicos (RF23), em dois acervos que convivem na mesma
 * tabela e se distinguem por `prestador_id`.
 *
 * O acervo da plataforma (`prestador_id` nulo) é mantido por X01 a partir das
 * diretrizes da WSAVA e vale para todas as clínicas — é o que RN30 restringe e
 * o que desenha o rótulo e a classificação de cada grupo da carteira (T05).
 * O acervo próprio de cada clínica (A04) é o que a diretriz não nomeia, e sobre
 * o qual a plataforma não tem o que dizer: invisível às demais na escolha de
 * uma aplicação nova, e visível a qualquer um na leitura do histórico — a dose
 * é do animal, e quem a leu autorizado tem de saber o nome do que foi aplicado.
 *
 * `prestador_id` fica fora do `#[Fillable]` pelo mesmo motivo que
 * `admin_plataforma` fica fora do usuário: de quem é o item é decisão do
 * servidor, e nunca campo de formulário.
 */
#[Fillable(['chave', 'nome_comercial', 'nome_tecnico', 'fabricante', 'agentes_cobertos', 'especie_destino', 'classificacao', 'via_administracao_usual', 'ativo'])]
class Imunobiologico extends Model
{
    /** @use HasFactory<ImunobiologicoFactory> */
    use HasFactory;

    protected $table = 'imunobiologicos';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
        ];
    }

    /**
     * O que pode ser aplicado neste animal, por quem está aplicando (RN30): só
     * o que está em uso, só o que serve à espécie dele — "ambas" cobre a
     * antirrábica — e só o que está ao alcance de quem registra.
     *
     * Vive aqui, e não em cada serviço, porque são dois os formulários que
     * oferecem catálogo — o pregresso do tutor (T09) e a aplicação profissional
     * (V07) — e uma segunda cópia da regra seria um segundo lugar de onde
     * esquecê-la. A *apresentação* continua sendo de cada serviço: V07 precisa
     * de fabricante e via usual para pré-preencher o formulário, T09 não.
     *
     * O prestador é parâmetro obrigatório, e nulo é resposta legítima: nulo é
     * "só o acervo da plataforma", que é o caso de T09 — o tutor relata o que
     * já aconteceu, e o acervo próprio de cada clínica não é lista que se
     * ofereça a ele. Obrigar cada chamada a declarar a sua posição por escrito
     * é o que impede que uma nova esqueça a decisão e vaze o acervo de uma
     * clínica para as demais.
     */
    #[Scope]
    protected function paraEspecieDe(Builder $consulta, Animal $animal, ?Prestador $prestador): void
    {
        $consulta->where('ativo', true)
            ->whereIn('especie_destino', [$animal->especie, 'ambas'])
            // O grupo aninhado não é estilo: sem ele, a precedência de AND
            // sobre OR soltaria o segundo ramo, e todo item deste prestador
            // entraria na lista — inclusive o inativado e o de outra espécie.
            ->where(fn (Builder $consulta) => $consulta
                ->whereNull('prestador_id')
                ->orWhere('prestador_id', $prestador?->id));
    }

    /**
     * @return BelongsTo<Prestador, Imunobiologico>
     */
    public function prestador(): BelongsTo
    {
        return $this->belongsTo(Prestador::class);
    }

    /** O item do acervo oficial — o que X01 mantém e A04 exibe travado. */
    public function daPlataforma(): bool
    {
        return $this->prestador_id === null;
    }

    /**
     * A primeira chave livre a partir de uma base, porque `chave` é única e
     * global. Vive aqui porque são dois os formulários que cadastram — X01 e
     * A04 —, e a colisão que um resolve o outro tem de resolver igual.
     */
    public static function chaveDisponivel(string $base): string
    {
        $chave = $base;
        $sufixo = 2;

        while (static::query()->where('chave', $chave)->exists()) {
            $chave = "{$base}-{$sufixo}";
            $sufixo++;
        }

        return $chave;
    }

    /**
     * @return HasMany<ProtocoloVacinal, Imunobiologico>
     */
    public function protocolos(): HasMany
    {
        return $this->hasMany(ProtocoloVacinal::class);
    }

    /**
     * RN31, RN32 — o protocolo vigente é o único usado para calcular a
     * próxima dose de uma aplicação nova; versões anteriores permanecem
     * apenas como referência do que foi aplicado sob elas.
     *
     * A vigência é da versão, não da linha de parâmetros: publicar a 2026.1
     * troca de uma vez o protocolo de todos os imunobiológicos, que é o que
     * "a WSAVA revisou as diretrizes" significa na prática (X02).
     *
     * Uma consulta só serve aos dois acervos, e sem ambiguidade: item oficial
     * nunca tem linha sem versão, item próprio nunca tem linha com versão. O
     * `orderByDesc` é a cópia-ao-escrever de A04 — editar um agendamento cria
     * a linha seguinte em vez de alterar a anterior, para que nenhuma data já
     * mostrada ao tutor se recalcule em silêncio (RN32) —, e o índice único de
     * `(imunobiologico_id, versao_protocolo_id)` garante que ele nunca esteja
     * desempatando duas linhas da mesma versão publicada.
     */
    public function protocoloVigente(): ?ProtocoloVacinal
    {
        return $this->protocolos()
            ->where(fn (Builder $consulta) => $consulta
                ->whereNull('versao_protocolo_id')
                ->orWhereRelation('versaoProtocolo', 'situacao', VersaoProtocolo::VIGENTE))
            ->orderByDesc('id')
            ->first();
    }
}
