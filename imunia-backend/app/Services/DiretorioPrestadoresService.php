<?php

namespace App\Services;

use App\Models\Autorizacao;
use App\Models\Prestador;
use App\Models\Tutor;
use App\Support\FiltroDoDiretorio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * O diretório de prestadores (T10, RF11) — o ponto de partida da autorização.
 *
 * A regra que governa esta classe é uma subtração: RF11a proíbe expor
 * quantidade de animais, de atendimentos ou qualquer dado operacional do
 * prestador. O cartão traz identificação e contato públicos, e nada mais. Não é
 * um comparador de clínicas; é uma lista para o tutor encontrar quem vai
 * atender o animal dele.
 *
 * O que a resposta acrescenta a esses dados públicos diz respeito ao próprio
 * tutor autenticado, nunca ao prestador: quais dos seus animais já têm
 * autorização vigente ali. Essa informação é dele, e é o que evita que ele
 * conceda duas vezes a mesma autorização.
 */
class DiretorioPrestadoresService
{
    /**
     * O diretório é lista para encontrar, não catálogo para percorrer. Passando
     * disto, o caminho é filtrar por município ou por nome — e a resposta diz
     * quantos ficaram de fora, para que a tela possa dizê-lo também.
     */
    private const RESULTADOS = 50;

    /**
     * @return array<string, mixed>
     */
    public function consultar(Tutor $tutor, FiltroDoDiretorio $filtro): array
    {
        $sugestao = $filtro->municipioEhExplicito() ? null : $this->municipioConhecido($tutor);
        $aplicado = $filtro->comSugestao($sugestao);

        $consulta = $this->restringir($aplicado);

        $total = (clone $consulta)->count();
        $prestadores = $consulta->orderBy('nome')->limit(self::RESULTADOS)->get();

        return [
            'filtro' => $aplicado->paraResposta(),
            'municipios' => $this->municipiosComEstabelecimento(),
            'total' => $total,
            'exibidos' => $prestadores->count(),
            'prestadores' => $this->cartoes($tutor, $prestadores),
        ];
    }

    /**
     * @return Builder<Prestador>
     */
    private function restringir(FiltroDoDiretorio $filtro): Builder
    {
        $consulta = Prestador::query();

        if ($filtro->nome !== null) {
            // `%` e `_` digitados pelo tutor são texto, e não curinga: sem o
            // escape, um caractere só percorreria a tabela inteira.
            $consulta->where('nome', 'like', '%'.addcslashes($filtro->nome, '%_\\').'%');
        }

        if ($filtro->municipio !== null) {
            $consulta->where('municipio', $filtro->municipio);
        }

        if ($filtro->uf !== null) {
            $consulta->where('uf', $filtro->uf);
        }

        return $consulta;
    }

    /**
     * O município que a tela pré-preenche "quando conhecido" (§6.1 do
     * briefing). O cadastro do tutor não guarda endereço — RF12 não o pede —,
     * então o único município que o sistema conhece a respeito dele é o do
     * estabelecimento que ele mesmo já autorizou alguma vez. É uma inferência
     * do próprio histórico do tutor, e não de dado de terceiro: o mais recente
     * ganha, porque é onde ele esteve por último.
     *
     * Autorização expirada ou revogada continua valendo para esta pergunta. A
     * vigência responde "quem pode ver"; aqui a pergunta é outra — "onde este
     * tutor costuma levar o animal".
     *
     * @return array{municipio: string, uf: string}|null
     */
    private function municipioConhecido(Tutor $tutor): ?array
    {
        $ultima = Autorizacao::query()
            ->whereHas('animal', fn (Builder $animal) => $animal->where('tutor_id', $tutor->id))
            ->with('prestador:id,municipio,uf')
            ->latest('concedida_em')
            ->first();

        if ($ultima?->prestador === null) {
            return null;
        }

        return [
            'municipio' => $ultima->prestador->municipio,
            'uf' => $ultima->prestador->uf,
        ];
    }

    /**
     * Os municípios que o seletor oferece: onde há estabelecimento cadastrado,
     * sem quantos há em cada um. A lista responde "o Imunia chega aqui?", que é
     * a pergunta do estado vazio de T10 — e para respondê-la não é preciso
     * dizer o tamanho de nenhuma praça.
     *
     * @return list<array{municipio: string, uf: string, rotulo: string}>
     */
    private function municipiosComEstabelecimento(): array
    {
        return Prestador::query()
            ->select('municipio', 'uf')
            ->distinct()
            ->orderBy('uf')
            ->orderBy('municipio')
            ->get()
            ->map(fn (Prestador $prestador) => [
                'municipio' => $prestador->municipio,
                'uf' => $prestador->uf,
                'rotulo' => "{$prestador->municipio}, {$prestador->uf}",
            ])
            ->all();
    }

    /**
     * @param Collection<int, Prestador> $prestadores
     * @return list<array<string, mixed>>
     */
    private function cartoes(Tutor $tutor, Collection $prestadores): array
    {
        $vigentes = $this->autorizacoesVigentes($tutor, $prestadores->pluck('id')->all());
        $animais = $tutor->animais()->count();

        return $prestadores->map(function (Prestador $prestador) use ($vigentes, $animais) {
            /** @var Collection<int, Autorizacao> $doPrestador */
            $doPrestador = $vigentes->get($prestador->id, new Collection);

            return [
                'id' => $prestador->id,

                // RF11a — daqui não sai nada além de identificação e contato
                // públicos. Nenhuma contagem, nenhum indicador de movimento,
                // nenhum nome de profissional da equipe.
                'nome' => $prestador->nome,
                'tipo' => $prestador->tipo,
                'tipo_rotulo' => $prestador->tipoRotulo(),
                'municipio' => $prestador->municipio,
                'uf' => $prestador->uf,
                'telefone' => $prestador->telefone,

                'autorizacao' => $this->autorizacaoDoTutor($doPrestador, $animais),
            ];
        })->all();
    }

    /**
     * @param list<int> $prestadores
     * @return Collection<int, Collection<int, Autorizacao>>
     */
    private function autorizacoesVigentes(Tutor $tutor, array $prestadores): Collection
    {
        if ($prestadores === []) {
            return new Collection;
        }

        return Autorizacao::query()
            ->vigente()
            ->whereIn('prestador_id', $prestadores)
            ->whereHas('animal', fn (Builder $animal) => $animal->where('tutor_id', $tutor->id))
            ->with('animal:id,nome')
            ->get()
            ->groupBy('prestador_id');
    }

    /**
     * O estado "prestador já autorizado" de T10, que troca o botão por uma
     * etiqueta com prazo.
     *
     * A autorização é nominal por animal (RF36a), e a etiqueta precisa dizer
     * isso quando o tutor tem mais de um: "autorizado" sem qualificação, num
     * cadastro com Théo e Nina, seria lido como se ambos estivessem cobertos —
     * e a Nina não estaria. Por isso a resposta traz os animais alcançados e
     * quantos ficaram de fora: com pendentes, a tela mantém o caminho de
     * conceder ao lado do de consultar.
     *
     * @param Collection<int, Autorizacao> $doPrestador
     * @return array<string, mixed>|null
     */
    private function autorizacaoDoTutor(Collection $doPrestador, int $animais): ?array
    {
        if ($doPrestador->isEmpty()) {
            return null;
        }

        // A mais distante: é até ela que o prestador enxerga alguma coisa, e é
        // a data que a etiqueta anuncia.
        $expiraEm = $doPrestador->max('expira_em');

        $alcancados = $doPrestador
            ->map(fn (Autorizacao $autorizacao) => $autorizacao->animal?->nome)
            ->filter()
            ->unique()
            ->values();

        return [
            'expira_em' => $expiraEm->toDateString(),
            'animais' => $alcancados->all(),
            'pendentes' => max(0, $animais - $alcancados->count()),
        ];
    }
}
