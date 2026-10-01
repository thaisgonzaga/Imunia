<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\Prestador;
use App\Models\RegistroDeAcesso;
use App\Models\Tutor;
use App\Models\User;
use App\Support\FiltroDeAcessos;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * O livro de acessos lido pelo lado de quem tem direito a lê-lo (T14, RF53).
 *
 * A escrita destas linhas está espalhada por V03 e V06 — cada tela que revela
 * algo registra o que revelou (RF52b) —, e é de propósito que a leitura esteja
 * num lugar só: o tutor faz uma pergunta única, "quem olhou o quê", e o
 * caminho que a responde não pode depender de por qual porta o profissional
 * entrou.
 *
 * A clínica atende sem pedir licença ao tutor; a transparência é a contrapartida.
 * Por isso cada linha diz também se aquela clínica acompanha hoje o animal — o
 * vínculo de `animal_prestador`, criado pelo cadastro ou pelo atendimento —, e
 * o recorte traz a relação das clínicas que acompanham.
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

        $vinculos = $this->vinculosDoTutor($tutor);
        $registros = $this->registros($tutor, $filtro, $escolhido);

        return [
            'filtro' => $filtro->paraResposta(),
            'periodos' => FiltroDeAcessos::opcoesDePeriodo(),
            'animais' => $animais
                ->map(fn (Animal $animal) => $this->cartaoDoAnimal($animal))
                ->values()
                ->all(),

            // O nome do prestador do filtro. Vem à parte da lista de acessos: o
            // filtro pode não encontrar linha alguma, e ainda assim a tela
            // precisa dizer de quem está falando ao oferecer a remoção dele.
            'prestador' => $this->prestadorDoFiltro($tutor, $filtro),

            'total' => $registros->count(),

            // Distinguir "nunca ninguém acessou" de "este recorte não achou
            // nada" é o que separa os dois vazios do desenho — um é confirmação
            // positiva, o outro é convite a alargar o período (§5.1).
            'algum_acesso' => $this->algumAcesso($tutor),

            // Quem acompanha os animais do recorte — o apoio do vazio filtrado:
            // há quem pudesse ter acessado e não acessou.
            'clinicas' => $this->clinicasDoRecorte($vinculos, $escolhido),

            'dias' => $this->porDia($registros, $vinculos),
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
     * Os vínculos clínica↔animal dos animais deste tutor. São poucos, e trazê-los
     * de uma vez evita uma consulta por linha do log.
     *
     * @return Collection<int, object{animal_id: int, prestador_id: int}>
     */
    private function vinculosDoTutor(Tutor $tutor): Collection
    {
        return DB::table('animal_prestador')
            ->join('animais', 'animais.id', '=', 'animal_prestador.animal_id')
            ->where('animais.tutor_id', $tutor->id)
            ->select('animal_prestador.animal_id', 'animal_prestador.prestador_id')
            ->get();
    }

    /**
     * @param  EloquentCollection<int, RegistroDeAcesso>  $registros
     * @param  Collection<int, object{animal_id: int, prestador_id: int}>  $vinculos
     * @return list<array<string, mixed>>
     */
    private function porDia(EloquentCollection $registros, Collection $vinculos): array
    {
        return $registros
            ->groupBy(fn (RegistroDeAcesso $registro) => $registro->ocorrido_em->toDateString())
            ->map(fn (Collection $doDia, string $data) => [
                'data' => $data,
                'acessos' => $doDia
                    ->map(fn (RegistroDeAcesso $registro) => $this->linha($registro, $vinculos))
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Uma linha da auditoria — o `AccessLogRow` do desenho.
     *
     * @param  Collection<int, object{animal_id: int, prestador_id: int}>  $vinculos
     * @return array<string, mixed>
     */
    private function linha(RegistroDeAcesso $registro, Collection $vinculos): array
    {
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

            // A clínica acompanha hoje este animal? Sem animal na linha (a
            // busca pelo CPF), vale qualquer animal do tutor.
            'acompanha' => $vinculos->contains(
                fn (object $vinculo) => $vinculo->prestador_id === $registro->prestador_id
                    && ($registro->animal_id === null || $vinculo->animal_id === $registro->animal_id),
            ),
        ];
    }

    /**
     * RF52 — "natureza do dado acessado", dita a quem tem de entendê-la sem
     * conhecer o vocabulário do sistema.
     *
     * As buscas e a ficha são fatos diferentes, e o texto os separa: procurar
     * pelo CPF do tutor ou pelo código do animal é chegar ao cadastro; ler o
     * histórico produzido por outra clínica é o acesso que RF52 nomeia. Achatar os três em "consultou seus dados"
     * pouparia palavras e apagaria justamente a distinção que o registro existe
     * para preservar.
     */
    private function descrever(RegistroDeAcesso $registro): string
    {
        $animal = $registro->animal?->nome;

        return match ($registro->natureza) {
            RegistroDeAcesso::BUSCA_POR_CPF => 'Pesquisou o seu CPF e encontrou o seu cadastro',
            RegistroDeAcesso::BUSCA_POR_NOME => 'Pesquisou pelo seu nome e viu que existe cadastro',
            RegistroDeAcesso::BUSCA_POR_CODIGO => $animal === null
                ? 'Pesquisou pelo código de um animal seu'
                : "Pesquisou pelo código de {$animal}",
            RegistroDeAcesso::BUSCA_POR_MICROCHIP => $animal === null
                ? 'Pesquisou pelo micro-chip de um animal seu'
                : "Pesquisou pelo micro-chip de {$animal}",
            // Natureza que não se grava mais, mas que as linhas antigas trazem.
            RegistroDeAcesso::FICHA_SEM_AUTORIZACAO => $animal === null
                ? 'Abriu a ficha de um animal seu'
                : "Abriu a ficha de {$animal}",
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
            RegistroDeAcesso::FICHA_SEM_AUTORIZACAO => 'ficha do animal',
            RegistroDeAcesso::HISTORICO_DE_OUTRO_PRESTADOR => 'histórico de outras clínicas',
            RegistroDeAcesso::ALERTA_DE_DUPLICIDADE => 'alerta de cadastro parecido',
            RegistroDeAcesso::EXPORTACAO_DE_REGISTRO_ALHEIO => 'exportação em PDF',
            default => 'dados do tutor',
        };
    }

    /**
     * As clínicas que acompanham os animais do recorte.
     *
     * @param  Collection<int, object{animal_id: int, prestador_id: int}>  $vinculos
     * @return list<array{id: int, nome: string}>
     */
    private function clinicasDoRecorte(Collection $vinculos, ?Animal $escolhido): array
    {
        $ids = $vinculos
            ->when($escolhido !== null, fn (Collection $todos) => $todos->where('animal_id', $escolhido->id))
            ->pluck('prestador_id')
            ->unique();

        return Prestador::query()
            ->whereIn('id', $ids)
            ->orderBy('nome')
            ->get()
            ->map(fn (Prestador $prestador) => ['id' => $prestador->id, 'nome' => $prestador->nome])
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function prestadorDoFiltro(Tutor $tutor, FiltroDeAcessos $filtro): ?array
    {
        if ($filtro->prestador === null) {
            return null;
        }

        // Só o prestador que já figura no livro deste tutor tem nome a dizer
        // aqui: T14 não é caminho para descobrir prestadores.
        $figura = RegistroDeAcesso::query()
            ->where('tutor_id', $tutor->id)
            ->where('prestador_id', $filtro->prestador)
            ->exists()
            || DB::table('animal_prestador')
                ->join('animais', 'animais.id', '=', 'animal_prestador.animal_id')
                ->where('animais.tutor_id', $tutor->id)
                ->where('animal_prestador.prestador_id', $filtro->prestador)
                ->exists();

        $prestador = $figura ? Prestador::find($filtro->prestador) : null;

        return $prestador === null ? null : $this->cartaoDoPrestador($prestador);
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
