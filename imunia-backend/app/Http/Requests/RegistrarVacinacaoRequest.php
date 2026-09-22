<?php

namespace App\Http\Requests;

use App\Http\Controllers\Concerns\ResolvePrestadorAtivo;
use App\Models\Animal;
use App\Models\Imunobiologico;
use App\Models\Prestador;
use App\Models\User;
use App\Rules\ValidadeMesAno;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * V07 — registro profissional de aplicação de vacina (RF25).
 *
 * A obrigatoriedade que a migration tirou do esquema está aqui. `vacinacoes`
 * deixa quase tudo nulo porque a mesma tabela recebe o histórico pregresso, em
 * que RF29 reduz a exigência a "os dados de que se disponha"; na origem
 * profissional, RN22 e a Resolução CFMV nº 1.321/2020 não admitem essa redução.
 *
 * Nada aqui recusa conduta clínica. Os campos que a tela pede diante de um
 * alerta — justificativa de conduta divergente, ordem da dose alterada — são
 * todos opcionais, porque RF27c e RN36 dizem que o sistema não impede o
 * registro de conduta divergente da que sugeriu.
 */
class RegistrarVacinacaoRequest extends FormRequest
{
    use ResolvePrestadorAtivo;

    public ?User $profissional = null;

    public ?Prestador $prestador = null;

    public ?Animal $animal = null;

    /**
     * O âmbito é resolvido aqui, e não no controlador, pela ordem das respostas:
     * a validação do corpo roda depois desta autorização, e um animal sem
     * autorização vigente precisa responder 403 antes de qualquer 422 — senão o
     * formato do erro já contaria que o pedido chegou a ser examinado.
     *
     * Usa o mesmo trait dos controladores clínicos de propósito. Reescrever as
     * verificações de vínculo, prestador ativo e CRMV aqui criaria um segundo
     * lugar de onde esquecê-las, e é por esse esquecimento que entraria registro
     * clínico sem responsável técnico.
     */
    public function authorize(): bool
    {
        [$this->profissional, $this->prestador] = $this->contextoClinico($this);

        // RN21 — só médico-veterinário com CRMV registrado cria registro clínico.
        $this->crmvExigido($this->profissional, $this->prestador);

        $this->animal = Animal::query()->where('codigo', $this->route('codigo'))->first();

        abort_if($this->animal === null, 404, 'Animal não encontrado.');

        // RF35c, RN37 — sem autorização vigente do tutor não há registro a
        // começar. A recusa nomeia o caminho (V10), porque a resposta certa para
        // o profissional aqui não é desistir: é pedir acesso.
        abort_if(
            ! $this->animal->autorizacoes()->where('prestador_id', $this->prestador->id)->vigente()->exists(),
            403,
            'Este animal não está sob autorização vigente do tutor. Solicite o acesso antes de registrar.',
        );

        // RF22a — registrado o óbito, o calendário se encerra e não há nova
        // aplicação a lançar. A única escrita que resta é a retificação (RF33).
        abort_if(
            $this->animal->inativo(),
            403,
            'Este animal tem óbito registrado. Não há nova aplicação a lançar.',
        );

        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(
            collect($this->only(['sitio_anatomico', 'justificativa_conduta', 'observacao']))
                ->map(fn (mixed $valor) => is_string($valor) && trim($valor) === '' ? null : $valor)
                ->all()
        );

        // O `<select>` da tela devolve cadeia de caracteres, e a coluna é
        // inteira. A conversão é aqui para que o serviço receba o mesmo tipo
        // que grava em `ordem_dose_sugerida`, e a auditoria de RF27b compare
        // dois inteiros — não um inteiro e um texto.
        if ($this->has('ordem_dose') && is_numeric($this->input('ordem_dose'))) {
            $this->merge(['ordem_dose' => (int) $this->input('ordem_dose')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A chave, não o id: é o identificador estável do catálogo (RF23), e
            // é o que a tela recebeu em `catalogo`.
            'imunobiologico' => [
                'required',
                'string',
                Rule::exists('imunobiologicos', 'chave')->where('ativo', true),
                fn (string $atributo, mixed $valor, \Closure $falhar) => $this->conferirEspecie($valor, $falhar),
            ],

            // RN22 — a Resolução CFMV manda registrar fabricante, lote, validade
            // e via. RF25a destaca lote e validade porque são os que o
            // profissional digita; os outros dois chegam pré-preenchidos, o que
            // não os torna dispensáveis.
            'fabricante' => ['required', 'string', 'max:120'],
            'lote' => ['required', 'string', 'max:60'],
            'validade' => ['required', new ValidadeMesAno],
            'via_administracao' => ['required', 'string', 'max:60'],

            'sitio_anatomico' => ['nullable', 'string', 'max:120'],

            'aplicado_em' => ['required', 'date', 'before_or_equal:now'],
            'ordem_dose' => ['required', 'integer', 'min:1', 'max:20'],

            // Não é o que grava a marca — quem a grava é o serviço, comparando as
            // datas. É o consentimento explícito que RF25c exige antes disso.
            'validade_expirada_confirmada' => ['boolean'],

            'justificativa_conduta' => ['nullable', 'string', 'max:2000'],
            'observacao' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validador): void
    {
        $validador->after(function (Validator $validador) {
            $this->conferirConfirmacaoDeValidade($validador);
            $this->conferirDataContraNascimento($validador);
        });
    }

    /**
     * RN30 — a vacina tem de servir à espécie do animal e estar ao alcance de
     * quem registra. A conferência é aqui, e não em `Rule::exists`, porque
     * depende do animal e do prestador, que só existem depois de resolvido o
     * âmbito. O catálogo que a tela recebeu já vem filtrado; esta é a mesma
     * regra do lado que não depende de a tela ter obedecido.
     *
     * As duas recusas são separadas de propósito. Uma chave de outra clínica
     * também não passa pelo escopo, e dizer a quem tentou usá-la que "não é
     * aplicada nesta espécie" o mandaria conferir a espécie do animal — que
     * está certa. Cada motivo se nomeia.
     */
    private function conferirEspecie(mixed $chave, \Closure $falhar): void
    {
        if (! is_string($chave) || $this->animal === null) {
            return;
        }

        $servePara = Imunobiologico::query()
            ->where('chave', $chave)
            ->whereIn('especie_destino', [$this->animal->especie, 'ambas'])
            ->exists();

        if (! $servePara) {
            $falhar('Esta vacina não é aplicada nesta espécie. Escolha uma da lista.');

            return;
        }

        $aoAlcance = Imunobiologico::query()
            ->where('chave', $chave)
            ->paraEspecieDe($this->animal, $this->prestador)
            ->exists();

        if (! $aoAlcance) {
            $falhar('Esta vacina não está no catálogo desta clínica. Escolha uma da lista.');
        }
    }

    /**
     * RF25c — vacina com validade expirada na data da aplicação exige
     * confirmação explícita. A comparação é de dia contra dia: um lote com
     * validade 04/2027 vale até o fim de 30/04/2027, e exigir confirmação de
     * quem o aplicou naquele dia seria acusar de vencido o que estava no prazo.
     */
    private function conferirConfirmacaoDeValidade(Validator $validador): void
    {
        $validade = ValidadeMesAno::interpretar($this->input('validade'));
        $aplicadoEm = $this->dataDeAplicacao();

        if ($validade === null || $aplicadoEm === null) {
            return;
        }

        if (! $validade->copy()->startOfDay()->lt($aplicadoEm->copy()->startOfDay())) {
            return;
        }

        if (! $this->boolean('validade_expirada_confirmada')) {
            $validador->errors()->add(
                'validade_expirada_confirmada',
                'Este lote venceu antes da data da aplicação. Confirme que o aplicou assim mesmo, '
                .'e o registro ficará marcado — o sistema não impede a aplicação.',
            );
        }
    }

    /**
     * Uma aplicação anterior ao nascimento do animal não é conduta divergente:
     * é dado impossível, e o cálculo do calendário derivaria idades negativas
     * dele (RN33).
     */
    private function conferirDataContraNascimento(Validator $validador): void
    {
        $aplicadoEm = $this->dataDeAplicacao();

        if ($aplicadoEm === null || $this->animal?->nascimento_em === null) {
            return;
        }

        if ($aplicadoEm->copy()->startOfDay()->lt($this->animal->nascimento_em->copy()->startOfDay())) {
            $validador->errors()->add(
                'aplicado_em',
                'A data da aplicação é anterior ao nascimento do animal. Confira a data.',
            );
        }
    }

    private function dataDeAplicacao(): ?Carbon
    {
        try {
            return Carbon::parse($this->input('aplicado_em'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'imunobiologico.required' => 'Escolha a vacina do catálogo.',
            'imunobiologico.exists' => 'Esta vacina não está no catálogo. Escolha uma da lista.',
            'lote.required' => 'O número do lote é obrigatório. Confira no frasco antes de confirmar.',
            'validade.required' => 'A validade é obrigatória. Confira no frasco antes de confirmar.',
            'fabricante.required' => 'Informe o fabricante do imunobiológico.',
            'via_administracao.required' => 'Informe a via de administração.',
            'aplicado_em.before_or_equal' => 'A aplicação não pode ser registrada com data futura.',
        ];
    }
}
