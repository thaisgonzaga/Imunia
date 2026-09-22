<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Prestador;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * A porta de entrada da administração do prestador, comum às três telas do
 * §8.4 do briefing (A01, A02, A03): há vínculo de administrador? qual conta
 * está sendo administrada?
 *
 * Espelha `ResolvePrestadorAtivo`, e a semelhança é intencional — são as
 * mesmas três decisões, tomadas sobre um papel diferente. O que muda é o que
 * cada uma abre: `ResolvePrestadorAtivo` dá acesso ao ambiente clínico, e este
 * dá acesso ao cadastro da conta e à equipe, e a mais nada. Nenhuma rota que
 * passe por aqui alcança tutor, animal ou registro clínico (RN08); é por isso
 * que A01 não tem indicador algum a exibir, e é esse o argumento de projeto
 * que as três telas carregam.
 */
trait ResolvePrestadorAdministrado
{
    /**
     * A porta das três telas quando elas apenas **mostram** a conta.
     *
     * As telas do prestador respondem sobre o estabelecimento em que a pessoa
     * está, e não sobre o que ela administra: quem atende numa clínica precisa
     * poder ver onde trabalha e quem são os colegas, e ver isso não é
     * administrar. O que a administração acrescenta é o poder de mudar — o
     * terceiro elemento devolvido —, e é ele que decide se a tela desenha as
     * ações. Toda escrita continua entrando por `contextoAdministrativo()`, que
     * recusa quem não administra: esconder o botão é conveniência da interface,
     * e a recusa é do servidor.
     *
     * Nada aqui alcança tutor, animal ou registro clínico (RN08); o que se abre
     * é o cadastro do prestador e a lista da equipe, ambos dados do próprio
     * estabelecimento.
     *
     * @return array{0: User, 1: Prestador, 2: bool}
     */
    protected function contextoDoPrestador(Request $request): array
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $vinculos = $usuario->vinculosVigentes();

        abort_if($vinculos->isEmpty(), 403, 'Esta área é de quem tem vínculo com um prestador.');

        $prestador = $this->prestadorDoContexto($request, $usuario, $vinculos);

        abort_if($prestador === null, 403, 'Você não tem vínculo com este prestador.');

        if ($request->integer('prestador') !== 0) {
            $usuario->lembrarContexto($prestador);
        }

        $administra = $usuario->prestadoresComoAdministrador()->contains('id', $prestador->id);

        return [$usuario, $prestador, $administra];
    }

    /**
     * @return array{0: User, 1: Prestador}
     */
    protected function contextoAdministrativo(Request $request): array
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $vinculos = $usuario->prestadoresComoAdministrador();

        // Quem não administra conta alguma não tem aqui um painel vazio a ver —
        // tem a tela errada. Vale para o veterinário sem papel administrativo
        // tanto quanto para o estranho.
        abort_if($vinculos->isEmpty(), 403, 'Esta área é da administração do prestador.');

        $prestador = $this->prestadorAdministrado($request, $usuario, $vinculos);

        // Um pedido por prestador que o usuário não administra não é um pedido
        // a corrigir: é um pedido a recusar.
        abort_if($prestador === null, 403, 'Você não administra este prestador.');

        // A memória de contexto é uma só para os dois ambientes, de propósito:
        // quem clica em "Equipe" na barra lateral da clínica espera administrar
        // o prestador em que estava, e voltar ao ambiente clínico deve devolvê-lo
        // ao mesmo lugar. Por isso só a escolha explícita é lembrada: quem
        // atende numa clínica que não administra cai aqui na própria conta por
        // falta de alternativa, e gravar esse acaso como escolha devolvia o
        // profissional ao consultório quando ele voltava ao ambiente clínico.
        if ($request->integer('prestador') !== 0) {
            $usuario->lembrarContexto($prestador);
        }

        return [$usuario, $prestador];
    }

    /**
     * Alimenta o alternador de contexto da moldura administrativa, como
     * `vinculosDe` faz no ambiente clínico (RF09b). Vem sempre, mesmo com um
     * vínculo só: é a tela que decide não desenhar a faixa quando não há entre
     * o que alternar (§5.2 do briefing).
     *
     * @return list<array{id: int, nome: string}>
     */
    protected function vinculosAdministradosDe(User $usuario): array
    {
        return $usuario->prestadoresComoAdministrador()
            ->map(fn (Prestador $vinculo) => ['id' => $vinculo->id, 'nome' => $vinculo->nome])
            ->all();
    }

    /**
     * Se a pessoa também atende no prestador que está administrando — o que
     * decide qual moldura a tela veste.
     *
     * Quem administra a conta em que atende não deve trocar de casca para ver
     * a equipe: a barra da clínica já desenha os três destinos administrativos,
     * e substituí-la por outra ao clicar neles fazia parecer que a navegação
     * tinha saído do lugar. Para a administradora sem CRMV — que não tem
     * ambiente clínico — a moldura continua sendo a administrativa, com o
     * bloco de limitação de RN08 que é argumento de projeto.
     */
    protected function atendeNoPrestador(User $usuario, Prestador $prestador): bool
    {
        return $usuario->prestadoresComoVeterinario()->contains('id', $prestador->id);
    }

    /**
     * Os vínculos que o alternador da moldura clínica precisa nomear — e nada
     * além do nome.
     *
     * Deliberadamente **sem** a contagem de animais que `vinculosDe()` entrega
     * ao ambiente clínico: RN08 não distingue agregado de nominal, e este
     * payload é o do papel administrativo. A lista dos próprios vínculos não é
     * dado de tutor, animal ou registro; o número de animais em cada um seria.
     *
     * @return list<array{id: int, nome: string, admin: bool}>
     */
    protected function vinculosClinicosDe(User $usuario): array
    {
        $administrados = $usuario->prestadoresComoAdministrador()->modelKeys();

        return $usuario->prestadoresComoVeterinario()
            ->map(fn (Prestador $vinculo) => [
                'id' => $vinculo->id,
                'nome' => $vinculo->nome,
                // O que decide se a barra desenha os destinos da administração
                // para aquele contexto, como no ambiente clínico.
                'admin' => in_array($vinculo->id, $administrados, true),
            ])
            ->all();
    }

    /**
     * O prestador em que a pessoa atende, quando não é o que ela administra —
     * e nada quando os dois coincidem.
     *
     * É o que permite à moldura explicar por que esta tela mostra outra conta:
     * quem foi convidada por uma clínica e mantém o próprio consultório não
     * administra a clínica, e o painel abria no consultório em silêncio, como
     * se a troca de contexto tivesse sido desfeita.
     *
     * @return array{id: int, nome: string}|null
     */
    protected function contextoClinicoDivergente(User $usuario, Prestador $administrado): ?array
    {
        $clinico = $usuario->prestadoresComoVeterinario()
            ->firstWhere('id', $usuario->ultimo_prestador_id);

        if ($clinico === null || $clinico->id === $administrado->id) {
            return null;
        }

        return ['id' => $clinico->id, 'nome' => $clinico->nome];
    }

    /**
     * O prestador de que as telas falam quando basta ter vínculo: aquele em que
     * a pessoa está.
     *
     * A ordem da preferência é a que evita a pergunta "por que abriu noutro
     * lugar?": o contexto ativo primeiro, porque é onde a pessoa estava; depois
     * uma conta que ela administre, que é o que as telas oferecem de mais
     * completo; e só então o primeiro vínculo.
     *
     * @param  Collection<int, Prestador>  $vinculos
     */
    private function prestadorDoContexto(Request $request, User $usuario, Collection $vinculos): ?Prestador
    {
        $pedido = $request->integer('prestador');

        if ($pedido !== 0) {
            return $vinculos->firstWhere('id', $pedido);
        }

        return $vinculos->firstWhere('id', $usuario->ultimo_prestador_id)
            ?? $usuario->prestadoresComoAdministrador()->first()
            ?? $vinculos->first();
    }

    /**
     * @param  Collection<int, Prestador>  $vinculos
     */
    private function prestadorAdministrado(Request $request, User $usuario, Collection $vinculos): ?Prestador
    {
        $pedido = $request->integer('prestador');

        if ($pedido === 0) {
            // O último contexto vale aqui também — se o usuário o administra.
            // Quem estava na clínica como veterinário e abre a administração
            // administra a clínica, não o primeiro vínculo da lista.
            return $vinculos->firstWhere('id', $usuario->ultimo_prestador_id)
                ?? $vinculos->first();
        }

        return $vinculos->firstWhere('id', $pedido);
    }
}
