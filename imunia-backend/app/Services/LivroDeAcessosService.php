<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\Autorizacao;
use App\Models\Prestador;
use App\Models\RegistroDeAcesso;
use App\Models\Tutor;
use App\Models\User;
use App\Support\FiltroDeAcessos;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * O livro de acessos lido pelo lado de quem tem direito a lê-lo (T14, RF53).
 *
 * A escrita destas linhas está espalhada por V03 e V06 — cada tela que revela
 * algo registra o que revelou (RF52b) —, e é de propósito que a leitura esteja
 * num lugar só: o tutor faz uma pergunta única, "quem olhou o quê", e o
 * caminho que a responde não pode depender de por qual porta o profissional
 * entrou.
 *
 * Duas informações que a tabela não guarda são montadas aqui, e as duas são
 * exigência de RF53:
 *
 * - **sob qual autorização** o acesso aconteceu. Não há coluna para isso, e não
 *   deveria haver: gravá-la congelaria, na linha do log, uma relação que o
 *   próprio tutor pode desfazer depois. A autorização é reconstruída pelo
 *   momento — a que valia para aquele prestador, naquele animal, na hora do
 *   acesso —, e a ausência dela é informação, não lacuna: é o acesso que
 *   aconteceu sem autorização alguma (RF18b, RF52c).
 * - **o que ainda dá para revogar**. A autorização de então pode já ter
 *   expirado, e a de hoje pode ser outra, nascida de renovação. Quem lê a linha
 *   quer encerrar o acesso que existe agora, não o que existia naquele dia.
 */
class LivroDeAcessosService
{
    /**
     * A auditoria inteira do recorte em vigor, agrupada por dia.
     *
     * O agrupamento sai do servidor porque ele é o eixo da tela e não um arranjo
     * dela: T14 se lê por dia, com cabeçalho fixo, e deixar que o navegador
     * reagrupe faria duas partes do sistema concordarem sobre o que é "um dia"
     * — concordância que o fuso horário desfaz na primeira madrugada.
     *
     * @return array<string, mixed>
     */
    public function listar(Tutor $tutor, FiltroDeAcessos $filtro): array
    {
        $animais = $tutor->animais()->orderBy('nome')->get();
        $escolhido = $animais->firstWhere('codigo', $filtro->animal);

        $autorizacoes = $this->autorizacoesDoTutor($tutor);
        $registros = $this->registros($tutor, $filtro, $escolhido);

        return [
            'filtro' => $filtro->paraResposta(),
            'periodos' => FiltroDeAcessos::opcoesDePeriodo(),
            'animais' => $animais
                ->map(fn (Animal $animal) => $this->cartaoDoAnimal($animal))
                ->values()
                ->all(),

            // O nome do prestador que T12 mandou destacar. Vem do próprio
            // recorte e não da lista de acessos: o filtro pode não encontrar
            // linha alguma, e ainda assim a tela precisa dizer de quem ela
            // está falando ao oferecer a remoção da etiqueta.
            'prestador' => $this->prestadorDoFiltro($filtro, $autorizacoes),

            'total' => $registros->count(),

            // Distinguir "nunca ninguém acessou" de "este recorte não achou
            // nada" é o que separa os dois vazios do desenho — um é confirmação
            // positiva, o outro é convite a alargar o período (§5.1).
            'algum_acesso' => $this->algumAcesso($tutor),

            // O apoio do vazio filtrado: há quem pudesse ter acessado e não
            // acessou. Sem isso, "nenhum acesso" pareceria falha de registro.
            'vigentes' => $this->vigentesDoRecorte($autorizacoes, $escolhido),

            'dias' => $this->porDia($registros, $autorizacoes),
        ];
    }

    /**
     * As linhas do recorte, da mais recente para a mais antiga.
     *
     * O âmbito é `tutor_id`, e não a titularidade do animal: quem gravou a
     * linha guardou o titular de então (ver a migração), e é ele quem tem
     * direito de saber o que foi revelado a seu respeito — mesmo que o animal
     * tenha mudado de mãos depois (RF21).
     *
     * @return EloquentCollection<int, RegistroDeAcesso>
     */
    private function registros(
        Tutor $tutor,
        FiltroDeAcessos $filtro,
        ?Animal $escolhido,
    ): EloquentCollection {
        $consulta = RegistroDeAcesso::query()
            ->where('tutor_id', $tutor->id)
            ->with(['prestador', 'profissional', 'animal'])
            ->orderByDesc('ocorrido_em')
            ->orderByDesc('id');

        if ($filtro->animal !== null) {
            // Código que não é de animal deste tutor não é erro a explicar: é
            // recorte que não encontra nada, e a tela já sabe dizer isso.
            $consulta->where('animal_id', $escolhido?->id ?? 0);
        }

        if ($filtro->prestador !== null) {
            $consulta->where('prestador_id', $filtro->prestador);
        }

        $desde = $filtro->desde();

        if ($desde !== null) {
            $consulta->where('ocorrido_em', '>=', $desde);
        }

        return $consulta->get();
    }

    /**
     * Existe alguma linha, em qualquer tempo e sobre qualquer animal? É a
     * pergunta do vazio positivo — "ninguém acessou ainda" —, e ela ignora o
     * recorte de propósito.
     */
    private function algumAcesso(Tutor $tutor): bool
    {
        return RegistroDeAcesso::query()->where('tutor_id', $tutor->id)->exists();
    }

    /**
     * Todas as autorizações que já alcançaram os animais deste tutor, vigentes
     * ou não. São poucas — dezenas, na vida de um tutor — e trazê-las de uma vez
     * evita uma consulta por linha do log para responder às duas perguntas que
     * cada linha faz: sob qual autorização isto aconteceu, e o que ainda dá para
     * revogar.
     *
     * @return EloquentCollection<int, Autorizacao>
     */
    private function autorizacoesDoTutor(Tutor $tutor): EloquentCollection
    {
        return Autorizacao::query()
            ->whereHas('animal', fn (Builder $animal) => $animal->where('tutor_id', $tutor->id))
            ->with('prestador')
            ->orderByDesc('concedida_em')
            ->get();
    }

    /**
     * @param  EloquentCollection<int, RegistroDeAcesso>  $registros
     * @param  EloquentCollection<int, Autorizacao>  $autorizacoes
     * @return list<array<string, mixed>>
     */
    private function porDia(EloquentCollection $registros, EloquentCollection $autorizacoes): array
    {
        return $registros
            ->groupBy(fn (RegistroDeAcesso $registro) => $registro->ocorrido_em->toDateString())
            ->map(fn (Collection $doDia, string $data) => [
                'data' => $data,
                'acessos' => $doDia
                    ->map(fn (RegistroDeAcesso $registro) => $this->linha($registro, $autorizacoes))
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Uma linha da auditoria — o `AccessLogRow` do desenho.
     *
     * @param  EloquentCollection<int, Autorizacao>  $autorizacoes
     * @return array<string, mixed>
     */
    private function linha(RegistroDeAcesso $registro, EloquentCollection $autorizacoes): array
    {
        $noMomento = $this->autorizacaoNoMomento($registro, $autorizacoes);
        $hoje = $this->autorizacaoVigente($registro, $autorizacoes);

        return [
            'id' => $registro->id,
            'ocorrido_em' => $registro->ocorrido_em->toIso8601String(),
            'hora' => $registro->ocorrido_em->format('H:i'),
            'prestador' => $this->cartaoDoPrestador($registro->prestador),
            'profissional' => $this->cartaoDoProfissional($registro->profissional, $registro->prestador),
            'animal' => $registro->animal === null
                ? null
                : $this->cartaoDoAnimal($registro->animal),
            'natureza' => $registro->natureza,
            'descricao' => $this->descrever($registro),
            'resumo' => $this->resumir($registro),

            // RF53 — "sob qual autorização". Nulo quer dizer nenhuma, e é o
            // fato mais grave que esta tela pode relatar (RF18b): olharam sem
            // que o tutor tivesse permitido, e por isso ficou registrado.
            'autorizacao' => $noMomento === null ? null : [
                'id' => $noMomento->id,
                'situacao' => $noMomento->situacao(),
                'concedida_em' => $noMomento->concedida_em->toDateString(),
                'expira_em' => $noMomento->expira_em->toDateString(),
            ],

            // RF53b — a revogação acionável da própria linha. Só aparece quando
            // há acesso a encerrar hoje: oferecê-la sobre autorização já
            // extinta prometeria ao tutor um efeito que o toque não teria.
            'revogavel' => $hoje?->id,
        ];
    }

    /**
     * A autorização que valia para aquele prestador, naquele animal, na hora do
     * acesso.
     *
     * A comparação é com o fim do dia de `expira_em` porque o prazo é contado
     * em dias (RN39): a autorização que expira em 12/11 vale o dia 12 inteiro,
     * e compará-la com a meia-noite faria o acesso das nove da manhã aparecer
     * como se não tivesse autorização alguma — acusação séria, e falsa.
     *
     * @param  EloquentCollection<int, Autorizacao>  $autorizacoes
     */
    private function autorizacaoNoMomento(
        RegistroDeAcesso $registro,
        EloquentCollection $autorizacoes,
    ): ?Autorizacao {
        if ($registro->animal_id === null) {
            return null;
        }

        return $autorizacoes->first(
            fn (Autorizacao $autorizacao) => $autorizacao->animal_id === $registro->animal_id
                && $autorizacao->prestador_id === $registro->prestador_id
                && $autorizacao->concedida_em->lessThanOrEqualTo($registro->ocorrido_em)
                && $autorizacao->expira_em->copy()->endOfDay()
                    ->greaterThanOrEqualTo($registro->ocorrido_em)
                && (
                    $autorizacao->revogada_em === null
                    || $autorizacao->revogada_em->greaterThan($registro->ocorrido_em)
                ),
        );
    }

    /**
     * O que ainda dá para revogar por causa desta linha: a autorização vigente
     * agora entre o mesmo prestador e o mesmo animal — que pode ser outra,
     * nascida de renovação (RF40c), e não a de então.
     *
     * @param  EloquentCollection<int, Autorizacao>  $autorizacoes
     */
    private function autorizacaoVigente(
        RegistroDeAcesso $registro,
        EloquentCollection $autorizacoes,
    ): ?Autorizacao {
        if ($registro->animal_id === null) {
            return null;
        }

        return $autorizacoes->first(
            fn (Autorizacao $autorizacao) => $autorizacao->animal_id === $registro->animal_id
                && $autorizacao->prestador_id === $registro->prestador_id
                && $autorizacao->estaVigente(),
        );
    }

    /**
     * RF52 — "natureza do dado acessado", dita a quem tem de entendê-la sem
     * conhecer o vocabulário do sistema.
     *
     * As buscas e a ficha são fatos diferentes, e o texto os separa: procurar
     * pelo CPF do tutor revela que existe cadastro; abrir a ficha sem
     * autorização é um passo além; ler o histórico produzido por outra clínica
     * é o acesso que RF52 nomeia. Achatar os três em "consultou seus dados"
     * pouparia palavras e apagaria justamente a distinção que o registro existe
     * para preservar.
     */
    private function descrever(RegistroDeAcesso $registro): string
    {
        $animal = $registro->animal?->nome;

        return match ($registro->natureza) {
            RegistroDeAcesso::BUSCA_POR_CPF => 'Pesquisou o seu CPF e viu que existe cadastro',
            RegistroDeAcesso::BUSCA_POR_NOME => 'Pesquisou pelo seu nome e viu que existe cadastro',
            RegistroDeAcesso::BUSCA_POR_CODIGO => $animal === null
                ? 'Pesquisou pelo código de um animal seu'
                : "Pesquisou pelo código de {$animal}",
            RegistroDeAcesso::BUSCA_POR_MICROCHIP => $animal === null
                ? 'Pesquisou pelo micro-chip de um animal seu'
                : "Pesquisou pelo micro-chip de {$animal}",
            RegistroDeAcesso::FICHA_SEM_AUTORIZACAO => $animal === null
                ? 'Abriu a ficha de um animal seu sem autorização'
                : "Abriu a ficha de {$animal} sem autorização",
            RegistroDeAcesso::HISTORICO_DE_OUTRO_PRESTADOR => $animal === null
                ? 'Consultou o histórico produzido por outras clínicas'
                : "Consultou o histórico de {$animal} produzido por outras clínicas",
            RegistroDeAcesso::ALERTA_DE_DUPLICIDADE => $animal === null
                ? 'Viu, ao cadastrar um animal, que já existe um parecido no seu cadastro'
                : "Viu, ao cadastrar um animal, que {$animal} já está no seu cadastro",
            RegistroDeAcesso::EXPORTACAO_DE_REGISTRO_ALHEIO => $animal === null
                ? 'Exportou em PDF o histórico de um animal seu, com registros de outras clínicas'
                : "Exportou em PDF o histórico de {$animal}, com registros de outras clínicas",
            default => 'Consultou dados seus',
        };
    }

    /**
     * A mesma natureza em duas ou três palavras, para a célula da tabela.
     *
     * As duas formas existem porque o desenho tem duas: no cartão do celular a
     * natureza é a frase inteira, com sujeito e animal; na linha de 1440 px ela
     * é uma coluna estreita ao lado de outras quatro, e a frase ali quebraria a
     * linha em cinco — desfazendo justamente a leitura vertical que a tabela
     * existe para permitir. O conteúdo é o mesmo; o que muda é quanto contexto
     * a vizinhança já fornece.
     */
    private function resumir(RegistroDeAcesso $registro): string
    {
        return match ($registro->natureza) {
            RegistroDeAcesso::BUSCA_POR_CPF => 'busca pelo seu CPF',
            RegistroDeAcesso::BUSCA_POR_NOME => 'busca pelo seu nome',
            RegistroDeAcesso::BUSCA_POR_CODIGO => 'busca pelo código',
            RegistroDeAcesso::BUSCA_POR_MICROCHIP => 'busca pelo micro-chip',
            RegistroDeAcesso::FICHA_SEM_AUTORIZACAO => 'ficha, sem autorização',
            RegistroDeAcesso::HISTORICO_DE_OUTRO_PRESTADOR => 'histórico de outras clínicas',
            RegistroDeAcesso::ALERTA_DE_DUPLICIDADE => 'alerta de cadastro parecido',
            RegistroDeAcesso::EXPORTACAO_DE_REGISTRO_ALHEIO => 'exportação em PDF',
            default => 'dados do tutor',
        };
    }

    /**
     * Quem tem acesso vigente ao recorte em vigor. É o que o vazio filtrado diz
     * ao tutor: a clínica pode ver, e não viu — o silêncio é do prestador, não
     * do registro.
     *
     * @param  EloquentCollection<int, Autorizacao>  $autorizacoes
     * @return list<array<string, mixed>>
     */
    private function vigentesDoRecorte(EloquentCollection $autorizacoes, ?Animal $escolhido): array
    {
        return $autorizacoes
            ->filter(fn (Autorizacao $autorizacao) => $autorizacao->estaVigente())
            ->when(
                $escolhido !== null,
                fn (Collection $vigentes) => $vigentes->where('animal_id', $escolhido->id),
            )
            ->unique('prestador_id')
            ->map(fn (Autorizacao $autorizacao) => [
                'id' => $autorizacao->prestador->id,
                'nome' => $autorizacao->prestador->nome,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  EloquentCollection<int, Autorizacao>  $autorizacoes
     * @return array<string, mixed>|null
     */
    private function prestadorDoFiltro(FiltroDeAcessos $filtro, EloquentCollection $autorizacoes): ?array
    {
        if ($filtro->prestador === null) {
            return null;
        }

        $autorizacao = $autorizacoes->firstWhere('prestador_id', $filtro->prestador);

        // Prestador que nunca alcançou animal deste tutor não tem nome a dizer
        // aqui — e não deve ter: T14 não é caminho para descobrir prestadores.
        return $autorizacao === null
            ? null
            : $this->cartaoDoPrestador($autorizacao->prestador);
    }

    /**
     * @return array<string, mixed>
     */
    private function cartaoDoPrestador(Prestador $prestador): array
    {
        return [
            // RF11a — identificação e localização públicas, nada de operação.
            'id' => $prestador->id,
            'nome' => $prestador->nome,
            'tipo_rotulo' => $prestador->tipoRotulo(),
            'municipio' => $prestador->municipio,
            'uf' => $prestador->uf,
        ];
    }

    /**
     * RF53a — a relação identifica o profissional, e não apenas o prestador. O
     * CRMV vem do vínculo com aquele prestador, que é onde ele existe: o mesmo
     * profissional atua em vários, e é o vínculo que responde pela habilitação.
     *
     * @return array<string, mixed>
     */
    private function cartaoDoProfissional(User $profissional, Prestador $prestador): array
    {
        return [
            'nome' => $profissional->name,
            'crmv' => $profissional->crmvEm($prestador),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cartaoDoAnimal(Animal $animal): array
    {
        return [
            'codigo' => $animal->codigo,
            'nome' => $animal->nome,
            'especie' => $animal->especie,
            'foto_url' => $animal->fotoUrl(),
        ];
    }
}
