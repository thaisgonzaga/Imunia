<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Autorizacao;
use App\Models\Prestador;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * A porta de entrada do ambiente clínico, comum a toda tela do veterinário: há
 * vínculo? qual prestador está ativo? (RF09b, RF48a).
 *
 * Nasceu compartilhado na fatia de V02, ao aparecer a segunda tela que precisava
 * exatamente das mesmas três decisões que V01 já tomava em métodos privados.
 * Duplicá-las seria criar dois lugares onde esquecer a verificação de vínculo, e
 * é esse esquecimento que expõe o histórico de um prestador a quem não tem
 * relação com ele.
 */
trait ResolvePrestadorAtivo
{
    /**
     * @return array{0: User, 1: Prestador}
     */
    protected function contextoClinico(Request $request): array
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $vinculos = $usuario->prestadoresComoVeterinario();

        // O ambiente clínico é de quem tem vínculo de veterinário. Sem nenhum não
        // há painel vazio a exibir — há tela errada (§3.2 dos requisitos).
        abort_if($vinculos->isEmpty(), 403, 'Esta área é do ambiente do veterinário.');

        $prestador = $this->prestadorAtivo($request, $usuario, $vinculos);

        // RF09b — o contexto ativo é escolha do profissional, e um pedido por
        // prestador com que ele não tem vínculo não é um pedido a corrigir: é
        // um pedido a recusar, do mesmo modo que o de um estranho.
        abort_if($prestador === null, 403, 'Você não tem vínculo com este prestador.');

        // A escolha fica lembrada para a próxima tela e a próxima sessão: os
        // destinos da barra lateral não carregam `?prestador=`, e sem memória
        // cada navegação devolveria o profissional ao primeiro vínculo — foi
        // assim que um animal recém-autorizado à clínica passou despercebido
        // de quem estava no contexto do consultório.
        //
        // Só a escolha, porém: o vínculo a que se chega por falta de outro não
        // é decisão de ninguém, e gravá-lo apagaria a decisão anterior — ver o
        // mesmo cuidado em `ResolvePrestadorAdministrado`.
        if ($request->integer('prestador') !== 0) {
            $usuario->lembrarContexto($prestador);
        }

        return [$usuario, $prestador];
    }

    /**
     * RN21 — só médico-veterinário com CRMV registrado no sistema cria registro
     * clínico. `contextoClinico()` sozinho não basta: ele responde "esta pessoa
     * pertence ao ambiente clínico?", que é a pergunta de quem vai **ler**. Quem
     * vai **escrever** precisa da inscrição, porque é ela que a Resolução CFMV
     * nº 1.321/2020 manda constar do registro (RN22) e é ela que responde pelo
     * ato depois.
     *
     * Fica no trait, e não no controlador de V07, porque V08 e V12 farão a mesma
     * pergunta — e um segundo lugar de onde esquecê-la seria um segundo lugar
     * por onde entraria registro clínico sem responsável técnico.
     */
    protected function crmvExigido(User $usuario, Prestador $prestador): string
    {
        $crmv = $usuario->crmvEm($prestador);

        abort_if(
            $crmv === null,
            403,
            'Registro clínico exige CRMV. Peça a quem administra a conta que informe a sua inscrição no vínculo com este prestador.',
        );

        return $crmv;
    }

    /**
     * RF09b — a faixa de contexto e o alternador saem daqui. Vem sempre, mesmo
     * com um vínculo só: é a tela que decide não desenhar a faixa quando não há
     * entre o que alternar (§5.2 do briefing).
     *
     * Cada vínculo diz quantos animais estão sob autorização vigente ali — o
     * sinal de que há trabalho esperando em outro contexto, sem nome nem código
     * de animal — e se o usuário também administra aquela conta, que é o que
     * decide se a barra lateral desenha os destinos da administração (A01-A03).
     *
     * @return list<array{id: int, nome: string, animais: int, admin: bool}>
     */
    protected function vinculosDe(User $usuario): array
    {
        $vinculos = $usuario->prestadoresComoVeterinario();

        // Distintos porque a renovação antecipada convive com a autorização
        // anterior ainda vigente — duas linhas, um animal.
        $animais = Autorizacao::query()
            ->whereIn('prestador_id', $vinculos->modelKeys())
            ->vigente()
            ->selectRaw('prestador_id, COUNT(DISTINCT animal_id) as total')
            ->groupBy('prestador_id')
            ->pluck('total', 'prestador_id');

        $administrados = $usuario->prestadoresComoAdministrador()->modelKeys();

        return $vinculos
            ->map(fn (Prestador $vinculo) => [
                'id' => $vinculo->id,
                'nome' => $vinculo->nome,
                'animais' => (int) ($animais[$vinculo->id] ?? 0),
                'admin' => in_array($vinculo->id, $administrados, true),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, Prestador>  $vinculos
     */
    private function prestadorAtivo(Request $request, User $usuario, Collection $vinculos): ?Prestador
    {
        $pedido = $request->integer('prestador');

        if ($pedido === 0) {
            // Sem escolha explícita, vale a última — se o vínculo dela ainda
            // vige. O primeiro vínculo é o ponto de partida de quem nunca
            // escolheu, não o destino de toda navegação.
            return $vinculos->firstWhere('id', $usuario->ultimo_prestador_id)
                ?? $vinculos->first();
        }

        return $vinculos->firstWhere('id', $pedido);
    }
}
