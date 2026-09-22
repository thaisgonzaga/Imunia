<?php

namespace App\Services;

use App\Models\Atendimento;
use App\Models\Prestador;
use App\Models\User;
use App\Models\Vacinacao;
use App\Rules\ValidadeMesAno;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * V09 — retificar registro clínico (RF33, RN26, RN27).
 *
 * A única escrita do sistema sobre um registro clínico que já existe — e ela
 * não escreve sobre ele. Cria um registro novo, com o conteúdo corrigido, que
 * aponta para o que corrige; o original permanece exatamente como foi
 * confirmado. Não há aqui `update()` nem `delete()` de coisa alguma, e a
 * ausência é o requisito: RF33a exige que **nenhum** caminho da aplicação
 * permita sobrescrever ou excluir registro clínico confirmado.
 *
 * As duas espécies de registro clínico do sistema — a aplicação de vacina
 * (RF25) e o prontuário do atendimento (RF31) — retificam-se pela mesma regra e
 * por isso moram no mesmo serviço. O que muda entre elas é a lista de campos
 * corrigíveis e o nome da coluna que guarda o autor; o resto — quem pode, o que
 * se copia, o que se recalcula — é idêntico, e escrever duas vezes seria criar
 * dois lugares onde a regra pode divergir.
 */
class RetificacaoDeRegistroService
{
    public function __construct(private readonly RegistroDeAtendimentoService $registro) {}

    /**
     * O que a retificação de uma aplicação pode corrigir (RF33).
     *
     * Fora da lista, e por decisão: o **imunobiológico** e a **espécie do
     * registro**. Trocar a vacina não é corrigir um campo — refaz o protocolo
     * aplicável (RN32), a ordem sugerida, o alerta de validade expirada (RF25c)
     * e a conferência de espécie (RN30), que é o formulário inteiro de V07. Uma
     * aplicação lançada com a vacina errada se corrige registrando a certa e
     * retificando esta para dizê-lo na observação; o dia em que houver caminho
     * melhor, ele será uma fatia, não um campo a mais aqui.
     */
    public const CAMPOS_DE_VACINACAO = [
        'fabricante' => 'Fabricante',
        'lote' => 'Lote',
        'validade' => 'Validade',
        'via_administracao' => 'Via de administração',
        'sitio_anatomico' => 'Local anatômico',
        'aplicado_em' => 'Data e hora da aplicação',
        'ordem_dose' => 'Ordem da dose',
        'observacao' => 'Observação',
    ];

    /**
     * RN27 — a retificação é privativa do profissional autor do registro, no
     * âmbito do prestador que o produziu. "Nenhum papel edita registro alheio":
     * não há aqui exceção para quem administra a conta da clínica, nem para o
     * mesmo profissional atuando por outro vínculo.
     *
     * A terceira condição não está em RN27 e decorre de RN26: um registro que
     * já foi retificado não se retifica de novo. Quem quiser corrigir a
     * correção retifica **a correção**, e a cadeia continua sendo uma linha —
     * duas retificações do mesmo original seriam duas versões vigentes ao mesmo
     * tempo, e nenhuma resposta para qual delas vale.
     */
    public function podeRetificarAtendimento(
        User $profissional,
        Prestador $prestador,
        Atendimento $atendimento,
    ): bool {
        return $atendimento->profissional_user_id === $profissional->id
            && $atendimento->prestador_id === $prestador->id
            && $atendimento->retificacao === null;
    }

    /**
     * A mesma regra, para a aplicação de vacina. O histórico pregresso (RF29)
     * não passa por aqui e não precisa de cláusula própria: ele não tem
     * aplicador, porque não houve ato clínico a atribuir a ninguém (RN25), e um
     * `aplicador_user_id` nulo nunca é o id de quem está pedindo.
     */
    public function podeRetificarVacinacao(
        User $profissional,
        Prestador $prestador,
        Vacinacao $vacinacao,
    ): bool {
        return $vacinacao->aplicador_user_id === $profissional->id
            && $vacinacao->prestador_id === $prestador->id
            && $vacinacao->retificacao === null;
    }

    /**
     * RF33 — a correção do prontuário, como registro novo vinculado ao
     * original.
     *
     * O que **não** é copiado do original merece nota. `atendido_em` é copiado:
     * a consulta aconteceu quando aconteceu, e uma retificação com a data de
     * hoje inventaria um atendimento que ninguém prestou — a data da correção é
     * o `created_at`, e é ele que a tela exibe. A autoria é reescrita a partir
     * de quem retifica, e não copiada: é a mesma pessoa (RN27 garante), mas o
     * CRMV é o que vale hoje, e é ele que responde por esta versão.
     *
     * @param  array<string, mixed>  $dados
     */
    public function retificarAtendimento(
        User $profissional,
        Prestador $prestador,
        Atendimento $original,
        array $dados,
    ): Atendimento {
        return DB::transaction(function () use ($profissional, $prestador, $original, $dados) {
            $this->recusarSegundaRetificacao($original);

            return $original->animal->atendimentos()->create([
                'prestador_id' => $original->prestador_id,
                'retifica_atendimento_id' => $original->id,
                'motivo_retificacao' => $dados['motivo_retificacao'],

                'atendido_em' => $original->atendido_em,

                // Deriva do motivo corrigido pela mesma regra de V08: o título
                // nomeia o atendimento em uma linha, e um motivo corrigido que
                // deixasse o título antigo faria a linha do tempo continuar
                // chamando o registro pelo que ele já não diz.
                'titulo' => $this->tituloDe($dados['motivo'] ?? $original->motivo),

                ...collect(AtendimentoService::SECOES)
                    ->map(fn (string $rotulo, string $chave) => $dados[$chave] ?? $original->{$chave})
                    ->all(),

                // Fora do que V09 corrige, e por isso copiado como está: o peso
                // aferido é medição da consulta, o retorno programado já gerou
                // lembrete ao tutor, e os anexos pertencem ao registro original
                // (RF32) — desvinculá-los dele seria perder a consulta que os
                // pediu.
                'peso_kg' => $original->peso_kg,
                'retorno_em' => $original->retorno_em,
                'retorno_finalidade' => $original->retorno_finalidade,

                'profissional_user_id' => $profissional->id,
                'profissional_nome' => $profissional->name,
                'profissional_crmv' => $profissional->crmvEm($prestador),
            ]);
        });
    }

    /**
     * RF33 — a correção da aplicação de vacina.
     *
     * Dois valores são recalculados em vez de copiados. `validade_expirada_
     * confirmada` (RF25c) porque a retificação pode mexer justamente na
     * validade ou na data: manter a marca antiga deixaria no registro uma
     * afirmação que os seus próprios campos desmentem. `ordem_dose_sugerida`
     * (RF27b) **não** é recalculada, e é o caso oposto: ela é o retrato do que
     * o sistema sugeriu no dia do registro, e recalculá-la hoje apagaria a
     * divergência que RF27b existe para tornar auditável.
     *
     * @param  array<string, mixed>  $dados
     */
    public function retificarVacinacao(
        User $profissional,
        Prestador $prestador,
        Vacinacao $original,
        array $dados,
    ): Vacinacao {
        return DB::transaction(function () use ($profissional, $prestador, $original, $dados) {
            $this->recusarSegundaRetificacao($original);

            $validade = array_key_exists('validade', $dados)
                ? ValidadeMesAno::interpretar($dados['validade'])
                : $original->validade;

            $aplicadoEm = array_key_exists('aplicado_em', $dados)
                ? Carbon::parse($dados['aplicado_em'])
                : $original->aplicado_em;

            return $original->animal->vacinacoes()->create([
                'retifica_vacinacao_id' => $original->id,
                'motivo_retificacao' => $dados['motivo_retificacao'],

                'prestador_id' => $original->prestador_id,
                'imunobiologico_id' => $original->imunobiologico_id,

                // RN32 — a versão do protocolo continua sendo a que valia à
                // época da aplicação. A correção de um número de lote não é
                // motivo para reabrir o cálculo sob diretriz que ainda não
                // existia quando a dose foi dada.
                'protocolo_vacinal_id' => $original->protocolo_vacinal_id,

                'origem' => $original->origem,
                'fabricante' => $dados['fabricante'] ?? $original->fabricante,
                'lote' => $dados['lote'] ?? $original->lote,
                'validade' => $validade,
                'via_administracao' => $dados['via_administracao'] ?? $original->via_administracao,
                'sitio_anatomico' => $dados['sitio_anatomico'] ?? $original->sitio_anatomico,
                'local_aplicacao' => $original->local_aplicacao,
                'aplicado_em' => $aplicadoEm,
                'data_aproximada' => $original->data_aproximada,

                'ordem_dose' => $dados['ordem_dose'] ?? $original->ordem_dose,
                'ordem_dose_sugerida' => $original->ordem_dose_sugerida,
                'justificativa_conduta' => $original->justificativa_conduta,
                'observacao' => $dados['observacao'] ?? $original->observacao,

                'aplicador_nome' => $profissional->name,
                'aplicador_crmv' => $profissional->crmvEm($prestador),
                'aplicador_user_id' => $profissional->id,

                'validade_expirada_confirmada' => $this->venceuAntesDaAplicacao($validade, $aplicadoEm),

                'lancado_por_user_id' => $original->lancado_por_user_id,
            ]);
        });
    }

    /**
     * Os campos alterados entre duas versões de uma aplicação, na ordem em que
     * a tela os desenha — e só os alterados, pela mesma razão de T08: repetir
     * os iguais lado a lado esconderia a correção no meio do que permaneceu.
     *
     * A comparação é entre os valores **como são exibidos**, e não como estão
     * gravados. Uma validade guardada em 30/04/2027 e outra em 30/04/2027 são o
     * mesmo "04/2027" na tela; marcar como alterado o que a tela mostra igual
     * seria acusar de correção o que ninguém corrigiu.
     *
     * @return list<array<string, string|null>>
     */
    public function compararVacinacoes(Vacinacao $original, Vacinacao $retificacao): array
    {
        return collect(self::CAMPOS_DE_VACINACAO)
            ->map(fn (string $rotulo, string $chave) => [
                'chave' => $chave,
                'rotulo' => $rotulo,
                'antes' => $this->valorExibido($original, $chave),
                'depois' => $this->valorExibido($retificacao, $chave),
            ])
            ->filter(fn (array $campo) => $campo['antes'] !== $campo['depois'])
            ->values()
            ->all();
    }

    /**
     * Os campos da aplicação como o formulário de V09 os recebe: valor atual,
     * rótulo e a forma de entrada que cada um pede.
     *
     * `mes_ano` não é capricho de tipo: o rótulo do frasco traz "04/2027" e é
     * assim que o profissional confere, como V07 já decidira. Pedir um dia aqui
     * obrigaria a inventá-lo para conferir o que ninguém escreveu.
     *
     * @return list<array<string, mixed>>
     */
    public function camposDaVacinacao(Vacinacao $vacinacao): array
    {
        $entradas = [
            'fabricante' => 'texto',
            'lote' => 'texto',
            'validade' => 'mes_ano',
            'via_administracao' => 'texto',
            'sitio_anatomico' => 'texto',
            'aplicado_em' => 'data_hora',
            'ordem_dose' => 'numero',
            'observacao' => 'texto_longo',
        ];

        return collect(self::CAMPOS_DE_VACINACAO)
            ->map(fn (string $rotulo, string $chave) => [
                'chave' => $chave,
                'rotulo' => $rotulo,
                'entrada' => $entradas[$chave],
                'valor' => $this->valorDeFormulario($vacinacao, $chave),
                'exibido' => $this->valorExibido($vacinacao, $chave),
            ])
            ->values()
            ->all();
    }

    /**
     * O mesmo para o prontuário. Todos os campos são texto longo — é o que RF31
     * enumera —, e só o diagnóstico admite ficar vazio, pela razão clínica que
     * o esquema já registra: a consulta pode encerrar-se com ele pendente de
     * exame.
     *
     * @return list<array<string, mixed>>
     */
    public function camposDoAtendimento(Atendimento $atendimento): array
    {
        return collect(AtendimentoService::SECOES)
            ->map(fn (string $rotulo, string $chave) => [
                'chave' => $chave,
                'rotulo' => $rotulo,
                'entrada' => 'texto_longo',
                'valor' => (string) $atendimento->{$chave},
                'exibido' => $atendimento->{$chave},
                'obrigatorio' => $chave !== 'diagnostico',
            ])
            ->values()
            ->all();
    }

    /**
     * O clique duplo, a aba aberta duas vezes, o pedido reenviado por falha de
     * rede. Sem esta conferência, cada um deles deixaria uma segunda versão
     * permanente do mesmo registro — e RN26 não oferece caminho para desfazê-la.
     *
     * O índice único da coluna é a rede embaixo desta: ele responde ao que
     * escapar entre a leitura e a gravação, que é justamente o que uma
     * conferência em PHP não alcança.
     */
    private function recusarSegundaRetificacao(Model $original): void
    {
        abort_if(
            $original->retificacao()->exists(),
            409,
            'Este registro já foi retificado. Abra a versão corrigida — é ela que pode ser retificada agora.',
        );
    }

    /**
     * RF25c — a comparação é de dia contra dia, e não de data-e-hora contra
     * data: um lote com validade 04/2027 vale até o fim de 30/04/2027, e
     * marcar como vencida a dose aplicada às 14h daquele dia seria acusar de
     * expirado o que estava no prazo. Mesma regra de V07, pelo mesmo motivo.
     */
    private function venceuAntesDaAplicacao(?Carbon $validade, ?Carbon $aplicadoEm): bool
    {
        if ($validade === null || $aplicadoEm === null) {
            return false;
        }

        return $validade->copy()->startOfDay()->lt($aplicadoEm->copy()->startOfDay());
    }

    /**
     * A primeira oração do motivo, sem a pontuação final, limitada a 80
     * caracteres — a mesma regra de V08, e não uma segunda: dois modos de
     * nomear o mesmo atendimento fariam a retificação aparecer na linha do
     * tempo com um título que o original nunca teria recebido.
     */
    private function tituloDe(string $motivo): string
    {
        return $this->registro->tituloDe($motivo);
    }

    private function valorExibido(Vacinacao $vacinacao, string $chave): ?string
    {
        $valor = $vacinacao->{$chave};

        if ($valor === null || $valor === '') {
            return null;
        }

        return match ($chave) {
            'validade' => $valor->format('m/Y'),
            'aplicado_em' => $valor->format('d/m/Y H:i'),
            default => (string) $valor,
        };
    }

    private function valorDeFormulario(Vacinacao $vacinacao, string $chave): ?string
    {
        $valor = $vacinacao->{$chave};

        if ($valor === null || $valor === '') {
            return null;
        }

        return match ($chave) {
            'validade' => $valor->format('m/Y'),

            // O formato que `<input type="datetime-local">` lê e devolve. Sem
            // ele o campo abre vazio e a data corrigida seria digitada do zero
            // num registro que só queria trocar o lote.
            'aplicado_em' => $valor->format('Y-m-d\TH:i'),

            default => (string) $valor,
        };
    }
}
