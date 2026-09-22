<?php

namespace App\Services;

use App\Models\ProtocoloVacinal;
use App\Models\VersaoProtocolo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ciclo de vida de uma versão de protocolo (RF24, X02): nasce rascunho a
 * partir da vigente, é editada enquanto rascunho, e publicar é o único ato que
 * a torna a versão dos cálculos novos.
 *
 * O que este serviço nunca faz é tão importante quanto o que ele faz: publicar
 * não percorre vacinação alguma. RN32 promete que datas já emitidas não são
 * recalculadas, e a forma de cumprir essa promessa é não ter, em lugar nenhum
 * do código, um caminho que as recalcule.
 */
class PublicacaoDeProtocoloService
{
    /**
     * Aproximação de mês em dias, usada só para comparar grandezas de unidades
     * diferentes na checagem de coerência. Não entra em cálculo de data alguma:
     * data de reforço se conta em meses de calendário (`addMonths`), e não em
     * múltiplos de trinta.
     */
    private const DIAS_POR_MES = 30;

    /**
     * A versão nova começa como cópia da vigente porque é assim que uma revisão
     * de diretriz acontece na prática: muda-se um parâmetro ou outro, não o
     * calendário inteiro. Copiar também garante que nenhum imunobiológico fique
     * sem parâmetros na versão nova por esquecimento.
     */
    public function criarRascunhoAPartirDaVigente(string $rotulo, ?string $base = null): VersaoProtocolo
    {
        if (VersaoProtocolo::where('situacao', VersaoProtocolo::RASCUNHO)->exists()) {
            throw ValidationException::withMessages([
                'rotulo' => 'Já existe um rascunho em edição. Publique-o ou descarte-o antes de começar outro.',
            ]);
        }

        $vigente = $this->vigente();

        return DB::transaction(function () use ($rotulo, $base, $vigente) {
            $rascunho = VersaoProtocolo::create([
                'rotulo' => $rotulo,
                'situacao' => VersaoProtocolo::RASCUNHO,
                'base' => $base,
                'publicado_em' => null,
                'encerrado_em' => null,
            ]);

            $vigente?->protocolos->each(function (ProtocoloVacinal $protocolo) use ($rascunho) {
                $copia = $protocolo->replicate(['versao_protocolo_id']);
                $copia->versao_protocolo_id = $rascunho->id;
                $copia->save();
            });

            return $rascunho->load('protocolos');
        });
    }

    /**
     * Os pares de parâmetros que se contradizem. Não impedem a edição — o
     * rascunho continua salvo, como diz a tela —, mas impedem a publicação:
     * uma versão publicada é usada em cálculo real, e um calendário que se
     * contradiz produziria datas que ninguém consegue justificar.
     *
     * @return array<int, array{protocolo_id: int, imunobiologico: string, campos: array<int, string>, mensagem: string}>
     */
    public function incoerencias(VersaoProtocolo $versao): array
    {
        $versao->loadMissing('protocolos.imunobiologico');

        return $versao->protocolos
            ->flatMap(fn (ProtocoloVacinal $protocolo) => $this->incoerenciasDe($protocolo))
            ->values()
            ->all();
    }

    /**
     * Publicar é um ato só: a versão nova passa a vigorar e a anterior se
     * encerra no mesmo instante, para que não exista momento algum — nem dentro
     * da transação — em que dois protocolos se digam vigentes ao mesmo tempo.
     */
    public function publicar(VersaoProtocolo $rascunho): VersaoProtocolo
    {
        if (! $rascunho->rascunho()) {
            throw ValidationException::withMessages([
                'versao' => 'Só um rascunho pode ser publicado. Versão publicada não se edita: cria-se uma nova a partir dela.',
            ]);
        }

        if ($this->incoerencias($rascunho) !== []) {
            throw ValidationException::withMessages([
                'versao' => 'Há parâmetros que se contradizem. Corrija-os antes de publicar.',
            ]);
        }

        return DB::transaction(function () use ($rascunho) {
            $agora = now();

            VersaoProtocolo::where('situacao', VersaoProtocolo::VIGENTE)
                ->update(['situacao' => VersaoProtocolo::ENCERRADA, 'encerrado_em' => $agora]);

            $rascunho->update([
                'situacao' => VersaoProtocolo::VIGENTE,
                'publicado_em' => $agora,
            ]);

            return $rascunho->fresh();
        });
    }

    /**
     * Descartar só alcança rascunho, e é a única exclusão que existe neste
     * módulo. Versão publicada não se apaga nem se sobrescreve: é dela que uma
     * data antiga continua explicável (RN32).
     */
    public function descartar(VersaoProtocolo $rascunho): void
    {
        if (! $rascunho->rascunho()) {
            throw ValidationException::withMessages([
                'versao' => 'Versão publicada não é descartada — os registros calculados sob ela guardam a referência.',
            ]);
        }

        DB::transaction(function () use ($rascunho) {
            $rascunho->protocolos()->delete();
            $rascunho->delete();
        });
    }

    public function vigente(): ?VersaoProtocolo
    {
        return VersaoProtocolo::with('protocolos')
            ->where('situacao', VersaoProtocolo::VIGENTE)
            ->first();
    }

    /**
     * @return array<int, array{protocolo_id: int, imunobiologico: string, campos: array<int, string>, mensagem: string}>
     */
    private function incoerenciasDe(ProtocoloVacinal $protocolo): array
    {
        $nome = $protocolo->imunobiologico?->nome_comercial ?? 'imunobiológico';
        $achados = [];

        if ($protocolo->intervalo_minimo_dias > $protocolo->intervalo_maximo_dias) {
            $achados[] = [
                'campos' => ['intervalo_minimo_dias', 'intervalo_maximo_dias'],
                'mensagem' => "O intervalo mínimo entre doses ({$protocolo->intervalo_minimo_dias} dias) é maior que o máximo ({$protocolo->intervalo_maximo_dias} dias). A janela ficaria vazia, e nenhuma data caberia nela.",
            ];
        }

        $reforcoMeses = $protocolo->primeiroReforcoMeses();
        $intervalo = $protocolo->intervaloPrevistoDias();

        // O prazo é nulo só no agendamento próprio de uma clínica, que não vive
        // em versão publicada e portanto nunca chega até aqui. O guard existe
        // porque este é o método que decide se uma versão pode ser publicada:
        // um TypeError nele travaria X02 inteira.
        if ($reforcoMeses !== null && $protocolo->numero_doses_serie_primaria > 1 && $intervalo > $reforcoMeses * self::DIAS_POR_MES) {
            $achados[] = [
                'campos' => ['intervalo_maximo_dias', 'reforco_inicial_meses'],
                'mensagem' => "O intervalo entre doses da série ({$intervalo} dias) é maior que o prazo do primeiro reforço ({$reforcoMeses} meses). Com esses valores, a série primária nunca terminaria antes do reforço.",
            ];
        }

        if (
            $protocolo->idade_minima_dose_final_semanas !== null
            && $protocolo->idade_minima_primeira_dose_semanas !== null
            && $protocolo->idade_minima_dose_final_semanas < $protocolo->idade_minima_primeira_dose_semanas
        ) {
            $achados[] = [
                'campos' => ['idade_minima_dose_final_semanas', 'idade_minima_primeira_dose_semanas'],
                'mensagem' => "A idade mínima da dose final ({$protocolo->idade_minima_dose_final_semanas} semanas) é menor que a da primeira ({$protocolo->idade_minima_primeira_dose_semanas} semanas). A série terminaria antes de poder começar.",
            ];
        }

        return array_map(fn (array $achado) => [
            'protocolo_id' => $protocolo->id,
            'imunobiologico' => $nome,
            ...$achado,
        ], $achados);
    }
}
