<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\Imunobiologico;
use App\Models\User;
use App\Models\Vacinacao;
use App\Support\DataAproximada;
use Illuminate\Validation\ValidationException;

/**
 * T09 — lançamento de histórico pregresso não verificado (RF29).
 *
 * Este é o primeiro caminho de escrita de registro clínico do sistema, e a
 * sua regra central é negativa: o que ele produz **não** é ato clínico
 * (RN25). Nenhum prestador o carimba, nenhum profissional responde por ele,
 * e a marca de não verificado nasce com o registro e não sai mais (RN24).
 *
 * Por isso os campos de origem profissional não são apenas deixados em branco
 * — são atribuídos aqui, fixos, sem passar pelo que o cliente mandou. Um
 * `prestador_id` que chegasse pelo corpo do pedido emprestaria a um relato de
 * tutor a aparência de responsabilidade técnica de alguém.
 */
class HistoricoPregressoService
{
    /**
     * Catálogo oferecido ao formulário (RF23): só o que está em uso e só o que
     * serve à espécie do animal — "ambas" cobre a antirrábica.
     *
     * Prestador nulo, e de propósito: T09 é do tutor, que relata o que já
     * aconteceu em algum lugar. O acervo próprio de uma clínica (A04) é lista
     * de trabalho dela, e oferecê-lo aqui contaria ao tutor de uma vacina que
     * ele não tem como ter recebido — e a um tutor autorizado por duas
     * clínicas, contaria uma sobre a outra.
     *
     * @return array<int, array<string, string>>
     */
    public function catalogoPara(Animal $animal): array
    {
        return Imunobiologico::query()
            ->paraEspecieDe($animal, null)
            ->orderBy('nome_comercial')
            ->get()
            ->map(fn (Imunobiologico $imunobiologico) => [
                'chave' => $imunobiologico->chave,
                'nome' => $imunobiologico->nome_comercial,
                'classificacao' => $imunobiologico->classificacao,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $dados  já validados por StoreHistoricoPregressoRequest
     */
    public function lancar(Animal $animal, User $usuario, array $dados): Vacinacao
    {
        $imunobiologico = $this->imunobiologicoDoCatalogo($animal, $dados['imunobiologico'] ?? null);
        $aplicadoEm = isset($dados['data']) ? DataAproximada::interpretar($dados['data']) : null;

        return $animal->vacinacoes()->create([
            'imunobiologico_id' => $imunobiologico?->id,
            'fabricante' => $dados['fabricante'] ?? null,
            'lote' => $dados['lote'] ?? null,
            'local_aplicacao' => $dados['local_aplicacao'] ?? null,
            'aplicado_em' => $aplicadoEm,

            // RN24 — a origem não vem do cliente e não tem outro valor
            // possível neste caminho. É a marca permanente de RF29a.
            'origem' => 'pregresso',

            // RN25 — toda data lançada aqui é aproximada, inclusive quando o
            // tutor informou o mês: o que ele afirmou foi um período, e é o
            // período que a carteira vai exibir.
            'data_aproximada' => $aplicadoEm !== null,

            // RF29b — quem lançou; o "quando" é o `created_at` do registro.
            'lancado_por_user_id' => $usuario->id,

            // Ausências deliberadas, não omissões: não houve prestador que
            // aplicasse, protocolo que calculasse, nem profissional que
            // assinasse. Explicitá-las aqui é o que impede que uma futura
            // alteração deste método as preencha por descuido.
            'prestador_id' => null,
            'protocolo_vacinal_id' => null,
            'ordem_dose' => null,
            'aplicador_nome' => null,
            'aplicador_crmv' => null,
            'validade' => null,
            'via_administracao' => null,
            'validade_expirada_confirmada' => false,
        ]);
    }

    /**
     * A compatibilidade de espécie é verificada aqui, e não na FormRequest,
     * porque depende do animal — que só existe depois de resolvido o âmbito do
     * tutor. O catálogo que a tela recebeu já vem filtrado; esta é a mesma
     * regra do lado que não depende de a tela ter obedecido.
     */
    private function imunobiologicoDoCatalogo(Animal $animal, ?string $chave): ?Imunobiologico
    {
        if ($chave === null) {
            return null;
        }

        $imunobiologico = Imunobiologico::query()
            ->where('chave', $chave)
            // Nulo pelo mesmo motivo de `catalogoPara()`: o que a lista não
            // ofereceu, o servidor não aceita de volta.
            ->paraEspecieDe($animal, null)
            ->first();

        if ($imunobiologico === null) {
            throw ValidationException::withMessages([
                'imunobiologico' => 'Esta vacina não é aplicada nesta espécie. Escolha uma da lista ou "não sei informar".',
            ]);
        }

        return $imunobiologico;
    }
}
