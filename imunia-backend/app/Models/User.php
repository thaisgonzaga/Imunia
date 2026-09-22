<?php

namespace App\Models;

use App\Notifications\ConfirmacaoDeEmail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'admin_plataforma' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Prestador, User>
     */
    public function prestadores(): BelongsToMany
    {
        return $this->belongsToMany(Prestador::class, 'prestador_usuario')
            ->withPivot('id', 'papel', 'crmv', 'crmv_uf', 'encerrado_em')
            ->withTimestamps();
    }

    /**
     * @return HasOne<Tutor, User>
     */
    public function tutor(): HasOne
    {
        return $this->hasOne(Tutor::class);
    }

    /**
     * @return HasMany<EmailVerificationToken, User>
     */
    public function tokensDeVerificacao(): HasMany
    {
        return $this->hasMany(EmailVerificationToken::class);
    }

    /**
     * Papéis acumuláveis do usuário (RN05): o vínculo com prestador é o que
     * confere `veterinario` e `admin_prestador`; o registro em `tutores`, o
     * papel `tutor`. Um mesmo usuário pode ter os três.
     *
     * @return list<string>
     */
    public function papeis(): array
    {
        $papeis = $this->vinculosVigentes()
            ->map(fn (Prestador $prestador) => (string) $prestador->pivot->papel)
            ->all();

        if ($this->tutor()->exists()) {
            $papeis[] = 'tutor';
        }

        // `admin_plataforma` (RF23, RF24) não vem de vínculo com prestador
        // nem de registro de tutor — é coluna do próprio usuário, porque o
        // catálogo e os protocolos que ele administra são globais.
        if ($this->admin_plataforma) {
            $papeis[] = 'admin_plataforma';
        }

        return array_values(array_unique($papeis));
    }

    /**
     * Os vínculos que ainda valem. RF10a — encerrado o vínculo, "o profissional
     * não acessa dado algum do prestador": a linha permanece no pivô para
     * preservar a autoria dos registros que ele produziu (RF10b), mas deixa de
     * conferir o que quer que seja.
     *
     * Todo caminho que decide acesso a partir do vínculo passa por aqui. É
     * deliberado que passe: espalhar a checagem de `encerrado_em` por cada
     * chamador criaria tantos lugares de onde esquecê-la quanto há telas, e o
     * esquecimento devolveria a um profissional desligado o prontuário inteiro
     * da clínica.
     *
     * @return EloquentCollection<int, Prestador>
     */
    public function vinculosVigentes(): EloquentCollection
    {
        return $this->prestadores->filter(
            fn (Prestador $prestador) => $prestador->pivot->encerrado_em === null,
        )->values();
    }

    /**
     * Prestadores em que o usuário atua como médico-veterinário — o conjunto
     * entre os quais o contexto ativo alterna (RF09b). Quem não tem nenhum não
     * tem o ambiente clínico, e é isso que o painel de V01 verifica antes de
     * qualquer consulta.
     *
     * @return EloquentCollection<int, Prestador>
     */
    public function prestadoresComoVeterinario(): EloquentCollection
    {
        return $this->vinculosVigentesComo('veterinario');
    }

    /**
     * Prestadores cuja conta o usuário administra (A01 a A03). Papel separado
     * do de veterinário de propósito: quem administra a conta não alcança dado
     * de tutor, animal ou registro clínico (RN08).
     *
     * @return EloquentCollection<int, Prestador>
     */
    public function prestadoresComoAdministrador(): EloquentCollection
    {
        return $this->vinculosVigentesComo('admin_prestador');
    }

    /**
     * O CRMV com que este profissional assina no prestador informado. Fica no
     * vínculo, e não no usuário, porque a inscrição é por conselho regional: o
     * mesmo veterinário pode atuar com registros diferentes em estados
     * diferentes (RF09, RN09).
     *
     * A busca é entre os vínculos **de veterinário**, e não entre todos os do
     * prestador: quem cadastrou a clínica em P03 tem duas linhas no pivô para o
     * mesmo estabelecimento — a de `admin_prestador`, sem CRMV algum, e a de
     * `veterinario`, que é a que assina. Procurar só pelo id do prestador
     * devolveria a primeira, e o profissional apareceria sem inscrição no
     * `ProvenanceChip` de todo registro que produzisse.
     *
     * E **não** filtra vínculo encerrado, ao contrário de tudo o mais que lê o
     * pivô. RF10 separa duas coisas que seria cômodo confundir: encerrar o
     * vínculo remove o acesso, não a autoria. O livro de acessos de T14 resolve
     * o CRMV do autor por aqui, ao vivo, porque `registros_de_acesso` não
     * guarda retrato dele; filtrar aqui apagaria a inscrição de quem foi
     * desligado depois, e é justamente essa permanência que RF10b exige e que
     * A03 promete em texto na tela — "encerrar vínculo remove o acesso, não a
     * autoria". Decisão de acesso passa por `vinculosVigentes()`; atribuição de
     * autoria, por aqui.
     */
    public function crmvEm(Prestador $prestador): ?string
    {
        $vinculo = $this->prestadores->first(
            fn (Prestador $candidato) => $candidato->id === $prestador->id
                && $candidato->pivot->papel === 'veterinario',
        );

        if ($vinculo === null || $vinculo->pivot->crmv === null) {
            return null;
        }

        return "CRMV-{$vinculo->pivot->crmv_uf} {$vinculo->pivot->crmv}";
    }

    /**
     * Se esta pessoa é o responsável técnico do prestador — a inscrição que
     * consta do cadastro (RF07c) é a dela.
     *
     * O par CRMV e UF é o critério, e não o nome: o nome do responsável técnico
     * é texto do cadastro, digitado por quem preencheu o formulário, e "Dr.
     * Marcelo" não casaria com "Marcelo Andrade Filho". A inscrição, essa, é a
     * mesma dos dois lados.
     *
     * Passa por `prestadoresComoVeterinario()`, e não por `crmvEm()`, porque a
     * pergunta aqui é de **poder** e não de autoria: quem teve o vínculo
     * encerrado deixou de responder tecnicamente pelo estabelecimento, ainda
     * que continue assinando os registros que fez (ver `crmvEm()`).
     */
    public function ehResponsavelTecnicoDe(Prestador $prestador): bool
    {
        if ($prestador->responsavel_tecnico_crmv === null) {
            return false;
        }

        $vinculo = $this->prestadoresComoVeterinario()->firstWhere('id', $prestador->id);

        return $vinculo !== null
            && $vinculo->pivot->crmv === $prestador->responsavel_tecnico_crmv
            && $vinculo->pivot->crmv_uf === $prestador->responsavel_tecnico_crmv_uf;
    }

    /**
     * @return EloquentCollection<int, Prestador>
     */
    private function vinculosVigentesComo(string $papel): EloquentCollection
    {
        return $this->vinculosVigentes()->filter(
            fn (Prestador $prestador) => $prestador->pivot->papel === $papel,
        )->values();
    }

    /**
     * Grava o prestador em que o usuário está trabalhando, para que a próxima
     * tela — e a próxima sessão — abra onde ele parou (RF09b). Memória, não
     * decisão: quem lê é o resolvedor de contexto, que confere o vínculo
     * vigente antes de honrá-la e ignora o id que já não vale.
     *
     * A escrita é direta na tabela, sem eventos nem `updated_at`: lembrar onde
     * alguém estava não é alteração do cadastro dele.
     */
    public function lembrarContexto(Prestador $prestador): void
    {
        if ((int) $this->ultimo_prestador_id === $prestador->id) {
            return;
        }

        $this->ultimo_prestador_id = $prestador->id;

        self::whereKey($this->id)->toBase()->update(['ultimo_prestador_id' => $prestador->id]);
    }

    /**
     * Painel de destino após a autenticação (RF01a). Quem acumula papéis cai no
     * ambiente de registro, o de uso diário; o alternador de papel fica na
     * interface. O contexto de prestador, esse sim, é lembrado entre sessões —
     * ver `lembrarContexto()`.
     */
    public function rotaInicial(): string
    {
        $papeis = $this->papeis();

        if (in_array('veterinario', $papeis, true)) {
            return '/clinica/painel';
        }

        if (in_array('admin_prestador', $papeis, true)) {
            return '/prestador';
        }

        if (in_array('admin_plataforma', $papeis, true)) {
            return '/plataforma/catalogo';
        }

        return '/inicio';
    }

    /**
     * A ligação de confirmação aponta para o SPA, não para a API: quem abre o
     * e-mail precisa cair numa tela, e não num JSON (P08).
     */
    public function sendEmailVerificationNotification(): void
    {
        $token = EmailVerificationToken::emitirPara($this);

        $this->notify(new ConfirmacaoDeEmail($token));
    }
}
