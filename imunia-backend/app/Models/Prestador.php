<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tipo',
    'nome',
    'cnpj',
    'telefone',
    'endereco',
    'cep',
    'municipio',
    'uf',
    'responsavel_tecnico_nome',
    'responsavel_tecnico_crmv',
    'responsavel_tecnico_crmv_uf',
])]
class Prestador extends Model
{
    use HasFactory;

    protected $table = 'prestadores';

    /**
     * RF07a — o tipo determina os rótulos da interface sem alterar o modelo de
     * dados. A tabela fica no modelo, e não em cada tela, porque o diretório
     * (T10), a ficha do prestador e o rodapé do documento exportado precisam
     * dizer a mesma coisa sobre o mesmo estabelecimento.
     *
     * "Atendimento domiciliar" para o autônomo é o rótulo do desenho: ao tutor
     * que procura quem vai atender, o que distingue o profissional sem endereço
     * fixo não é o regime jurídico dele, é o fato de que ele vai até a casa.
     */
    private const ROTULOS_DE_TIPO = [
        'clinica' => 'Clínica veterinária',
        'hospital' => 'Hospital veterinário',
        'autonomo' => 'Atendimento domiciliar',
    ];

    public function tipoRotulo(): string
    {
        return self::ROTULOS_DE_TIPO[$this->tipo] ?? 'Estabelecimento veterinário';
    }

    /**
     * @return BelongsToMany<User, Prestador>
     */
    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'prestador_usuario')
            ->withPivot('papel', 'crmv', 'crmv_uf', 'encerrado_em')
            ->withTimestamps();
    }

    /**
     * A equipe de A03: os vínculos de médico-veterinário, encerrados inclusive.
     *
     * O encerrado vem junto de propósito — a linha esmaecida com a data é o
     * argumento de RF10 na tela, e some-la faria a equipe parecer que aquela
     * pessoa nunca atendeu ali. Quem precisa só de quem atende hoje filtra por
     * `encerrado_em`; quem precisa da história pega a lista inteira.
     *
     * `withPivot('id')` é o que dá à tela um identificador para a linha do
     * vínculo, já que a mesma pessoa pode figurar duas vezes no pivô (papel de
     * administrador e de veterinário) e o id do usuário não a distingue.
     *
     * @return BelongsToMany<User, Prestador>
     */
    public function equipe(): BelongsToMany
    {
        return $this->usuarios()
            ->wherePivot('papel', 'veterinario')
            ->withPivot('id')
            ->orderByPivot('encerrado_em')
            ->orderBy('users.name');
    }

    /**
     * Quem atende aqui hoje — o destinatário de todo aviso operacional do
     * prestador.
     *
     * Existe porque o laço sobre `usuarios()` acertava gente demais em dois
     * sentidos. Fura RN08, porque o administrador não pode receber aviso que
     * nomeia animal ou tutor: o papel dele é a conta, e a caixa de entrada não
     * é exceção ao que a tela recusa mostrar. E fura RF10a, porque o vínculo
     * encerrado continuaria rendendo notificação sobre a clínica que desligou o
     * profissional.
     *
     * De quebra resolve uma duplicidade: quem cadastrou a clínica em P03 tem
     * duas linhas no pivô e era notificado duas vezes.
     *
     * @return BelongsToMany<User, Prestador>
     */
    public function veterinariosAtivos(): BelongsToMany
    {
        return $this->usuarios()
            ->wherePivot('papel', 'veterinario')
            ->wherePivotNull('encerrado_em');
    }

    /**
     * @return HasMany<AlteracaoPrestador, Prestador>
     */
    public function alteracoes(): HasMany
    {
        return $this->hasMany(AlteracaoPrestador::class);
    }

    /**
     * O acervo próprio de vacinas (A04) — o que esta clínica usa e a diretriz
     * da WSAVA não nomeia. Não alcança o catálogo da plataforma, que não é de
     * prestador algum e por isso tem `prestador_id` nulo.
     *
     * @return HasMany<Imunobiologico, Prestador>
     */
    public function imunobiologicos(): HasMany
    {
        return $this->hasMany(Imunobiologico::class);
    }

    /**
     * RF07c — sem responsável técnico identificado não há a quem atribuir a
     * responsabilidade por uma aplicação, e nenhuma informação clínica pode
     * ser registrada. É a condição que A01 anuncia em alerta bloqueante.
     */
    public function semResponsavelTecnico(): bool
    {
        return $this->responsavel_tecnico_nome === null
            || $this->responsavel_tecnico_crmv === null;
    }

    /**
     * O CRMV do responsável técnico já composto para leitura ("CRMV-MG 12345"),
     * como o cartão de identificação de A01 e o rodapé do documento exportado
     * o exibem.
     */
    public function responsavelTecnicoCrmv(): ?string
    {
        if ($this->responsavel_tecnico_crmv === null) {
            return null;
        }

        return "CRMV-{$this->responsavel_tecnico_crmv_uf} {$this->responsavel_tecnico_crmv}";
    }
}
