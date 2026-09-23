<?php

namespace App\Services;

use App\Models\Prestador;
use Illuminate\Support\Collection;

/**
 * O que falta configurar na conta do prestador (A01).
 *
 * A lista existe porque o cadastro de P04 é deliberadamente curto — pede o
 * indispensável para abrir a conta e deixa o resto para depois. "Depois" só
 * acontece se alguém cobrar, e é este serviço que cobra, no painel que o
 * administrador vê ao entrar.
 *
 * A distinção entre o bloqueio e as demais pendências é de natureza, não de
 * grau: sem responsável técnico o estabelecimento não pode registrar
 * informação clínica alguma (RF07c), e é a única condição aqui que impede o
 * sistema de funcionar. Por isso ela sai separada, para a tela poder anunciá-la
 * acima de tudo em vez de enfileirá-la entre cartões da barra lateral.
 *
 * Trabalha sobre a equipe já montada por `EquipeDoPrestador`, e não sobre o
 * banco: a situação de cada vínculo é decidida em um lugar só, e A01 não pode
 * chegar a uma conclusão sobre um convite diferente da de A03.
 */
class PendenciasDeConfiguracao
{
    /**
     * RF07c — o alerta bloqueante de A01, ou `null` quando não há o que
     * bloquear.
     *
     * @return array<string, mixed>|null
     */
    public function bloqueio(Prestador $prestador): ?array
    {
        if (! $prestador->semResponsavelTecnico()) {
            return null;
        }

        return [
            'chave' => 'sem_responsavel_tecnico',
            'titulo' => 'Informe o responsável técnico',
            'descricao' => 'Enquanto '.$this->denominacao($prestador).' não tiver responsável técnico '
                .'informado, nenhuma informação clínica pode ser registrada: sem CRMV vinculado ao '
                .'estabelecimento, não há responsabilidade técnica a atribuir a uma aplicação.',
            'complemento' => 'A equipe consegue entrar e ver a própria conta, mas as ações de registrar '
                .'vacinação e atendimento ficam indisponíveis até isso ser resolvido.',
            'acao' => ['tipo' => 'ir_para_dados', 'rotulo' => 'Informar responsável técnico'],
        ];
    }

    /**
     * As pendências da coluna lateral de A01 — as que valem a pena resolver,
     * mas não impedem o sistema de funcionar. Cada uma vem com a ação que a
     * resolve: uma pendência que só informa é uma cobrança sem saída.
     *
     * @param  list<array<string, mixed>>  $membros  a equipe montada por `EquipeDoPrestador`
     * @return list<array<string, mixed>>
     */
    public function listar(Prestador $prestador, array $membros): array
    {
        $equipe = collect($membros);

        return collect([
            $this->enderecoIncompleto($prestador),
            ...$this->convitesAExpirar($equipe),
            $this->convitesExpirados($equipe),
            $this->equipeSemVeterinario($equipe),
        ])->filter()->values()->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function enderecoIncompleto(Prestador $prestador): ?array
    {
        if ($prestador->cep !== null) {
            return null;
        }

        return [
            'chave' => 'endereco_incompleto',
            'icone' => 'building-2',
            'titulo' => 'Endereço incompleto',
            // RF08b, RF11 — o diretório é consultado por município, e sem CEP a
            // clínica continua aparecendo; o que se perde é a precisão de onde
            // ela fica. Dizer isso evita que a pendência pareça mais grave do
            // que é e acabe ignorada por descrédito.
            'descricao' => 'Falta o CEP. Sem ele, '.$this->denominacao($prestador)
                .' aparece no diretório apenas pelo município.',
            'acao' => ['tipo' => 'ir_para_dados', 'rotulo' => 'Completar endereço'],
        ];
    }

    /**
     * Um cartão por convite prestes a vencer, com o nome de quem espera. O nome
     * importa: "um convite expira em 2 dias" não diz a quem escrever, e o
     * administrador teria de abrir A03 para descobrir.
     *
     * @param  Collection<int, array<string, mixed>>  $equipe
     * @return list<array<string, mixed>>
     */
    private function convitesAExpirar(Collection $equipe): array
    {
        return $equipe
            ->filter(fn (array $membro) => $membro['dias_para_expirar'] !== null
                && $membro['dias_para_expirar'] <= EquipeDoPrestador::DIAS_PARA_AVISAR_EXPIRACAO)
            ->map(fn (array $membro) => [
                'chave' => 'convite_a_expirar',
                'icone' => 'clock-alert',
                'titulo' => 'Convite a expirar',
                'descricao' => 'O convite para '.$this->identificar($membro).' expira em '
                    .$membro['dias_para_expirar']
                    .($membro['dias_para_expirar'] === 1 ? ' dia.' : ' dias.'),
                'acao' => [
                    'tipo' => 'reenviar_convite',
                    'rotulo' => 'Reenviar convite',
                    'vinculo' => $membro['vinculo'],
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $equipe
     * @return array<string, mixed>|null
     */
    private function convitesExpirados(Collection $equipe): ?array
    {
        $expirados = $equipe->where('situacao', EquipeDoPrestador::CONVITE_EXPIRADO)->values();

        if ($expirados->isEmpty()) {
            return null;
        }

        $quantos = $expirados->count();

        return [
            'chave' => 'convite_expirado',
            'icone' => 'clock-alert',
            'titulo' => $quantos === 1 ? 'Convite expirado' : 'Convites expirados',
            'descricao' => $quantos === 1
                ? 'O convite para '.$this->identificar($expirados->first()).' venceu sem ser aceito.'
                : $quantos.' convites venceram sem serem aceitos.',
            'acao' => $quantos === 1
                ? [
                    'tipo' => 'reenviar_convite',
                    'rotulo' => 'Reenviar convite',
                    'vinculo' => $expirados->first()['vinculo'],
                ]
                : ['tipo' => 'ir_para_equipe', 'rotulo' => 'Ver a equipe'],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $equipe
     * @return array<string, mixed>|null
     */
    private function equipeSemVeterinario(Collection $equipe): ?array
    {
        if ($equipe->where('situacao', EquipeDoPrestador::ATIVO)->isNotEmpty()) {
            return null;
        }

        return [
            'chave' => 'equipe_sem_veterinario',
            'icone' => 'user-round-check',
            'titulo' => 'Nenhum veterinário ativo',
            'descricao' => 'Sua conta administra o cadastro, mas não registra atendimento. '
                .'Convide ao menos um médico-veterinário para que a clínica possa registrar vacinações.',
            'acao' => ['tipo' => 'convidar_veterinario', 'rotulo' => 'Convidar veterinário'],
        ];
    }

    /**
     * Enquanto o convidado não define o próprio nome, é pelo endereço que ele é
     * identificado — na pendência tanto quanto na linha da equipe.
     *
     * @param  array<string, mixed>  $membro
     */
    private function identificar(array $membro): string
    {
        return $membro['nome'] ?? $membro['email'];
    }

    /**
     * RF07a — o tipo determina os rótulos da interface. Quem atende em
     * domicílio não tem "a clínica" a que o texto se refira.
     */
    private function denominacao(Prestador $prestador): string
    {
        return match ($prestador->tipo) {
            'hospital' => 'o hospital',
            'autonomo' => 'o profissional',
            default => 'a clínica',
        };
    }
}
