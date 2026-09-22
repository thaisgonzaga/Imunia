<?php

namespace App\Services;

use App\Models\AlteracaoPrestador;
use App\Models\Prestador;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A atualização do cadastro do prestador e o rastro que ela deixa (A02, RF08).
 *
 * Os avisos são calculados aqui, e não na tela, porque quem sabe o que de fato
 * mudou é quem comparou os valores. A tela que decidisse sozinha quando exibir
 * o aviso de denominação teria de refazer a comparação — e passaria a avisar
 * sobre alteração que não houve toda vez que o formulário fosse salvo sem
 * mudanças.
 */
class AtualizacaoDoPrestador
{
    /**
     * Os campos que o formulário de A02 governa. A lista é explícita para que
     * um campo novo na tabela não entre no cadastro por acidente.
     */
    private const CAMPOS = [
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
    ];

    /**
     * @param  array<string, mixed>  $dados
     * @return list<string> os avisos a exibir sobre o que a alteração produziu
     */
    public function aplicar(Prestador $prestador, User $autor, array $dados): array
    {
        $antes = $this->retrato($prestador);

        DB::transaction(function () use ($prestador, $autor, $dados, $antes) {
            $prestador->fill($dados)->save();

            AlteracaoPrestador::registrar($prestador, $autor, $antes, $this->retrato($prestador));
        });

        return $this->avisos($antes, $this->retrato($prestador));
    }

    /**
     * O bloco "Histórico de alterações" de A02, do mais recente para o mais
     * antigo. Limitado: o histórico serve para explicar por que um documento
     * antigo diz outra coisa, não para auditar a conta inteira desde o começo.
     *
     * @return list<array<string, mixed>>
     */
    public function historico(Prestador $prestador, int $limite = 20): array
    {
        return $prestador->alteracoes()
            ->with('autor')
            ->orderByDesc('ocorrido_em')
            ->orderByDesc('id')
            ->limit($limite)
            ->get()
            ->map(fn (AlteracaoPrestador $alteracao) => [
                'campo' => $alteracao->campo,
                'de' => $alteracao->de,
                'para' => $alteracao->para,
                // A conta de quem alterou pode ter sido apagada depois; a
                // alteração permanece, e a tela diz o que sabe.
                'autor' => $alteracao->autor?->name ?: null,
                'ocorrido_em' => Carbon::parse($alteracao->ocorrido_em)->format('d/m/Y H:i'),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $antes
     * @param  array<string, mixed>  $depois
     * @return list<string>
     */
    private function avisos(array $antes, array $depois): array
    {
        $avisos = [];

        // RF08a — a denominação vigente na emissão fica congelada no documento
        // exportado. Dizer isso no momento da troca evita a suspeita, meses
        // depois, de que um PDF antigo esteja errado.
        if ($antes['nome'] !== $depois['nome']) {
            $avisos[] = 'Documentos já exportados continuam exibindo o nome vigente na data de emissão. '
                .'A alteração vale para os próximos.';
        }

        // RF08b — o município alimenta o diretório consultado pelo tutor.
        if ($antes['municipio'] !== $depois['municipio'] || $antes['uf'] !== $depois['uf']) {
            $avisos[] = 'No diretório consultado pelos tutores, o estabelecimento passa a aparecer em '
                .$depois['municipio'].', '.$depois['uf'].'.';
        }

        // RF07c — a consequência de salvar sem responsável técnico não pode
        // chegar como surpresa na primeira tentativa de registrar vacinação.
        if ($antes['responsavel_tecnico_crmv'] !== null && $depois['responsavel_tecnico_crmv'] === null) {
            $avisos[] = 'Sem responsável técnico informado, nenhuma informação clínica pode ser '
                .'registrada por esta equipe até que outro seja indicado.';
        }

        return $avisos;
    }

    /**
     * @return array<string, mixed>
     */
    private function retrato(Prestador $prestador): array
    {
        return $prestador->only(self::CAMPOS);
    }
}
