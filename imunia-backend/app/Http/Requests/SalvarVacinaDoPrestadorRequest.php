<?php

namespace App\Http\Requests;

use App\Http\Controllers\Concerns\ResolvePrestadorAdministrado;
use App\Models\Imunobiologico;
use App\Models\Prestador;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A04 — cadastro e edição de uma vacina do acervo próprio da clínica (RF23).
 *
 * Uma classe só para criar e para editar, ao contrário de X01, onde a segunda
 * existe apenas para deixar a chave de fora: aqui nem a criação a pede, porque
 * ela é gerada a partir do nome com o prestador por prefixo.
 *
 * A classificação em essencial ou não essencial também não é campo. Classificar
 * é leitura de diretriz (RN31), e a diretriz não examinou o que a clínica
 * cadastrou — o serviço grava "não essencial" e a tela explica por quê.
 */
class SalvarVacinaDoPrestadorRequest extends FormRequest
{
    use ResolvePrestadorAdministrado;

    public ?Prestador $prestador = null;

    /**
     * Resolve o âmbito aqui, e não no controlador, pela ordem das respostas: a
     * validação do corpo roda depois desta autorização, e quem não administra a
     * conta precisa receber 403 antes de qualquer 422 sobre os campos.
     */
    public function authorize(): bool
    {
        /** @var User $usuario */
        [$usuario, $this->prestador] = $this->contextoAdministrativo($this);

        $item = $this->route('imunobiologico');

        if ($item instanceof Imunobiologico) {
            $this->exigirVacinaDaClinica($item, $this->prestador);
        }

        return true;
    }

    /**
     * As duas recusas são diferentes de propósito.
     *
     * O item da plataforma responde 403 **dizendo por quê**: ele está na lista
     * que a tela acabou de mostrar, e esconder o motivo mandaria a pessoa
     * procurar o que ela já achou. O de outra clínica responde 404, porque para
     * esta conta ele não existe — e dizer "existe, mas não é seu" contaria que
     * alguma outra clínica cadastrou alguma coisa.
     */
    public function exigirVacinaDaClinica(Imunobiologico $item, Prestador $prestador): void
    {
        abort_if(
            $item->daPlataforma(),
            403,
            'Este item é do catálogo da plataforma, mantido a partir das diretrizes da WSAVA. '
            .'A clínica não o edita nem o inativa: o cálculo dele responde por todas as clínicas.',
        );

        abort_if($item->prestador_id !== $prestador->id, 404, 'Vacina não encontrada.');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'repete' => $this->boolean('repete'),
            'primeiro_reforco_diferente' => $this->boolean('primeiro_reforco_diferente'),
        ]);

        // Campo em branco é ausência, e não cadeia vazia: `agentes_cobertos` é
        // opcional, e o serviço tem um padrão para quando ninguém informou.
        if (is_string($this->input('agentes_cobertos')) && trim($this->input('agentes_cobertos')) === '') {
            $this->merge(['agentes_cobertos' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nome_comercial' => ['required', 'string', 'max:255'],
            'fabricante' => ['required', 'string', 'max:255'],
            'agentes_cobertos' => ['nullable', 'string', 'max:500'],
            'especie_destino' => ['required', 'in:cao,gato,ambas'],
            'via_administracao_usual' => ['required', 'in:Subcutânea,Intramuscular'],

            // O agendamento, no vocabulário de quem preenche. A tradução para
            // as dez colunas de `protocolos_vacinais` é de
            // `CatalogoDoPrestador::parametrosDoAgendamento()`.
            'doses_primeira_vez' => ['required', 'integer', 'min:1', 'max:3'],

            // Semanas, e não meses: duas a quatro semanas entre doses da série
            // é a grandeza real (RN34), e ela não cabe em meses. O prazo em
            // meses e anos é o do reforço, que é o que gera o lembrete.
            'intervalo_semanas' => ['nullable', 'required_unless:doses_primeira_vez,1', 'integer', 'in:2,3,4'],

            'repete' => ['required', 'boolean'],
            'periodicidade_valor' => ['nullable', 'required_if:repete,true', 'integer', 'min:1', 'max:10'],
            'periodicidade_unidade' => ['nullable', 'required_if:repete,true', 'in:meses,anos'],

            'primeiro_reforco_diferente' => ['boolean'],
            'primeiro_reforco_valor' => ['nullable', 'required_if:primeiro_reforco_diferente,true', 'integer', 'min:1', 'max:60'],
            'primeiro_reforco_unidade' => ['nullable', 'required_if:primeiro_reforco_diferente,true', 'in:meses,anos'],
        ];
    }

    public function withValidator(Validator $validador): void
    {
        $validador->after(function (Validator $validador) {
            // `periodicidade_valor` e `primeiro_reforco_valor` cabem cada um no
            // seu teto, mas o par pode ficar sem sentido: um primeiro reforço
            // mais distante que a periodicidade faria a segunda revacinação
            // vir antes da primeira. É a única incoerência que esta tela
            // consegue produzir, e ela é barrada aqui — X02 tem a conferência
            // dela para os dez parâmetros da diretriz.
            if (! $this->boolean('repete') || ! $this->boolean('primeiro_reforco_diferente')) {
                return;
            }

            $periodicidade = $this->emMeses('periodicidade_valor', 'periodicidade_unidade');
            $reforco = $this->emMeses('primeiro_reforco_valor', 'primeiro_reforco_unidade');

            if ($periodicidade === null || $reforco === null || $reforco <= $periodicidade) {
                return;
            }

            $validador->errors()->add(
                'primeiro_reforco_valor',
                'O primeiro reforço não pode ser mais distante que a periodicidade seguinte: '
                .'a revacinação seguinte cairia antes dele.',
            );
        });
    }

    private function emMeses(string $campoValor, string $campoUnidade): ?int
    {
        $valor = $this->input($campoValor);

        if (! is_numeric($valor)) {
            return null;
        }

        return $this->input($campoUnidade) === 'anos' ? ((int) $valor) * 12 : (int) $valor;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nome_comercial' => 'nome da vacina',
            'fabricante' => 'fabricante',
            'agentes_cobertos' => 'contra o quê',
            'especie_destino' => 'espécie',
            'via_administracao_usual' => 'via de aplicação',
            'doses_primeira_vez' => 'número de doses da primeira vez',
            'intervalo_semanas' => 'intervalo entre as doses',
            'periodicidade_valor' => 'prazo da revacinação',
            'primeiro_reforco_valor' => 'prazo do primeiro reforço',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'intervalo_semanas.required_unless' => 'Informe de quanto em quanto tempo as doses da série são aplicadas.',
            'intervalo_semanas.in' => 'O intervalo entre as doses da série é de 2, 3 ou 4 semanas.',
            'periodicidade_valor.required_if' => 'Informe de quanto em quanto tempo a vacina se repete.',
            'periodicidade_unidade.required_if' => 'Escolha se o prazo é em meses ou em anos.',
            'primeiro_reforco_valor.required_if' => 'Informe em quanto tempo cai o primeiro reforço.',
            'primeiro_reforco_unidade.required_if' => 'Escolha se o prazo do primeiro reforço é em meses ou em anos.',
        ];
    }
}
