<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\Exportacao;
use App\Models\Prestador;
use App\Models\RegistroDeAcesso;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * T15 — emissão do histórico em PDF verificável (RF46).
 *
 * O serviço não calcula conteúdo clínico nenhum: a carteira vem de
 * `CalendarioVacinalService` e a linha do tempo de `HistoricoConsolidadoService`,
 * pelos mesmos métodos que alimentam T05 e T07. O documento impresso e a tela
 * dizem, por construção, as mesmas palavras sobre os mesmos registros — um PDF
 * que dissesse outra coisa desmentiria a tela que o gerou.
 *
 * O que é próprio daqui é a emissão: o identificador, o resumo criptográfico do
 * conteúdo, a linha que a verificação pública (RF47) consultará e o arquivo
 * guardado sob o mesmo código. O resumo é do conteúdo, e não dos bytes do
 * arquivo — o PDF carrega o próprio resumo no rodapé, e um hash dos bytes
 * teria de estar dentro do arquivo cujos bytes resume.
 */
class ExportacaoDeHistoricoService
{
    /**
     * Os dois recortes que T15 oferece: a carteira de vacinação como está e a
     * linha do tempo completa.
     */
    public const CONTEUDOS = ['carteira', 'historico'];

    /**
     * Recortes de período do histórico, em meses. Nulo é o período inteiro.
     */
    public const PERIODOS_EM_MESES = [6, 12];

    public function __construct(
        private readonly CalendarioVacinalService $calendario,
        private readonly HistoricoConsolidadoService $historico,
    ) {}

    /**
     * Emite o documento: monta o conteúdo, grava a linha da emissão e guarda o
     * PDF. Devolve nulo quando o recorte pedido não tem registro algum — sem
     * conteúdo não há o que resumir, e uma emissão vazia seria um documento
     * autêntico afirmando coisa nenhuma.
     */
    public function emitir(Animal $animal, User $autor, string $conteudo, ?int $meses): ?Exportacao
    {
        $documento = $this->montarDocumento($animal, $conteudo, $meses);

        if ($documento === null) {
            return null;
        }

        return $this->registrarEmissao($animal, $autor, $documento);
    }

    /**
     * A mesma emissão, pelo ambiente clínico (RF46 nomeia o veterinário como
     * ator). O que muda não é o documento — mesmo conteúdo, mesmo resumo,
     * mesma verificação pública —, é a prestação de contas: quando o recorte
     * emitido leva registro de outro prestador, a linha de RN49 é gravada
     * **antes** de a emissão existir, pela mesma ordem de RF52b na ficha — a
     * gravação é condição do ato, não um segundo momento dele.
     *
     * O âmbito (autorização vigente, não titularidade) é julgado no
     * controlador, que é a porta; aqui já se está dentro.
     */
    public function emitirPelaClinica(
        Animal $animal,
        User $profissional,
        Prestador $prestador,
        string $conteudo,
        ?int $meses,
    ): ?Exportacao {
        $documento = $this->montarDocumento($animal, $conteudo, $meses);

        if ($documento === null) {
            return null;
        }

        if ($this->documentoLevaRegistroAlheio($animal, $prestador, $conteudo, $documento)) {
            RegistroDeAcesso::create([
                'prestador_id' => $prestador->id,
                'user_id' => $profissional->id,
                'tutor_id' => $animal->tutor_id,
                'animal_id' => $animal->id,
                'natureza' => RegistroDeAcesso::EXPORTACAO_DE_REGISTRO_ALHEIO,
                'ocorrido_em' => now(),
            ]);
        }

        return $this->registrarEmissao($animal, $profissional, $documento);
    }

    /**
     * @param  array<string, mixed>  $documento
     */
    private function registrarEmissao(Animal $animal, User $autor, array $documento): Exportacao
    {
        $exportacao = DB::transaction(fn () => Exportacao::create([
            'codigo' => $this->gerarCodigo(),
            // O resumo é calculado sobre a serialização canônica do conteúdo
            // (RN47). Reemitir o mesmo recorte com os mesmos registros produz o
            // mesmo resumo sob outro código — duas emissões do mesmo documento,
            // que é o que RF46a descreve.
            'resumo' => hash('sha256', json_encode(
                $documento,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            )),
            'animal_id' => $animal->id,
            // Retrato do momento, e não referência: é o que o papel na mão do
            // conferente diz, e o que a verificação confirma mesmo que o
            // cadastro mude depois.
            'animal_nome' => $animal->nome,
            'animal_especie' => $animal->especie,
            'emitido_por' => $autor->id,
            'emitido_em' => now(),
        ]));

        Storage::disk('local')->put(
            $exportacao->caminhoDoArquivo(),
            $this->gerarPdf($documento, $exportacao),
        );

        return $exportacao;
    }

    /**
     * O endereço público que confirma a emissão (RF47). Vai impresso no rodapé,
     * embutido no QR Code e devolvido à tela para o "Copiar link". O resumo
     * abreviado viaja junto para que a verificação possa acusar divergência —
     * na digitação manual ele não existe, e a comparação fica com o conferente.
     */
    public function linkDeVerificacao(Exportacao $exportacao): string
    {
        return rtrim(config('app.frontend_url'), '/')
            .'/verificar/'.$exportacao->codigo
            .'?resumo='.$exportacao->resumoAbreviado();
    }

    /**
     * O conteúdo do documento, na forma que o resumo criptográfico assina e o
     * PDF imprime. Nulo quando o recorte não tem registros.
     *
     * Público porque é a fronteira que os testes de RF46b percorrem: o que
     * este método diz sobre um registro é o que o papel dirá.
     *
     * @return array<string, mixed>|null
     */
    public function montarDocumento(Animal $animal, string $conteudo, ?int $meses): ?array
    {
        $identificacao = [
            'tipo' => $conteudo,
            'animal' => [
                'codigo' => $animal->codigo,
                'nome' => $animal->nome,
                'especie' => $animal->especie,
                'nascimento' => $animal->nascimento_em?->toDateString(),
                'nascimento_exato' => $animal->nascimento_exato,
                'preliminar' => $animal->preliminar(),
            ],
            'tutor' => $animal->tutor->nome,
        ];

        if ($conteudo === 'carteira') {
            $carteira = $this->calendario->montarCarteira($animal);

            if ($carteira['grupos'] === []) {
                return null;
            }

            return [...$identificacao, 'carteira' => $carteira];
        }

        $historico = $this->historico->montar($animal);
        $corte = $meses === null ? null : now()->subMonths($meses)->toDateString();

        // O recorte de período fica fora quando não pode afirmar pertencimento:
        // uma entrada sem data não está "nos últimos 12 meses" — está sem data,
        // e só o período inteiro a inclui.
        $entradas = collect($historico['entradas'])
            ->filter(fn (array $entrada) => $corte === null
                || ($entrada['data'] !== null && $entrada['data'] >= $corte))
            ->values()
            ->all();

        if ($entradas === []) {
            return null;
        }

        return [
            ...$identificacao,
            'periodo' => $meses,
            'entradas' => $entradas,
        ];
    }

    /**
     * RN49 fala em "registro clínico originado de outro prestador", e a
     * pergunta é sobre o **documento emitido**, não sobre o animal: um
     * histórico recortado aos últimos 6 meses pode deixar de fora o único
     * atendimento alheio, e uma linha de log sobre o que não saiu diria ao
     * tutor, em T14, algo que não aconteceu.
     *
     * A carteira não carrega id de prestador nas aplicações — só o nome, que é
     * o que ela imprime —, e por isso responde-se com a mesma consulta de V06:
     * a carteira contém todas as aplicações do animal, e havendo vacinação
     * alheia, ela está lá. O pregresso fica fora nas duas contas: não vem de
     * prestador algum (RN24).
     *
     * @param  array<string, mixed>  $documento
     */
    private function documentoLevaRegistroAlheio(
        Animal $animal,
        Prestador $prestador,
        string $conteudo,
        array $documento,
    ): bool {
        if ($conteudo === 'carteira') {
            return $animal->vacinacoes()
                ->whereNotNull('prestador_id')
                ->where('prestador_id', '!=', $prestador->id)
                ->exists();
        }

        return collect($documento['entradas'])->contains(
            fn (array $entrada) => ! in_array(
                $entrada['prestador']['chave'],
                ['sem-prestador', "prestador-{$prestador->id}"],
                strict: true,
            ),
        );
    }

    /**
     * Identificador próprio da emissão (RN47): 16 caracteres hexadecimais,
     * sorteados de novo no caso raríssimo de colisão com emissão existente.
     */
    private function gerarCodigo(): string
    {
        do {
            $codigo = Str::upper(bin2hex(random_bytes(Exportacao::COMPRIMENTO_CODIGO / 2)));
        } while (Exportacao::query()->where('codigo', $codigo)->exists());

        return $codigo;
    }

    /**
     * @param  array<string, mixed>  $documento
     */
    private function gerarPdf(array $documento, Exportacao $exportacao): string
    {
        return Pdf::loadView('pdf.exportacao', [
            'documento' => $documento,
            'exportacao' => $exportacao,
            'linkVerificacao' => $this->linkDeVerificacao($exportacao),
            'qrCode' => $this->gerarQrCode($this->linkDeVerificacao($exportacao)),
        ])->output();
    }

    /**
     * O QR Code do rodapé, como imagem embutida: o dompdf não sai para a rede,
     * e o documento não pode depender dela — ele existe justamente para valer
     * fora do domínio da plataforma (RN46).
     */
    private function gerarQrCode(string $conteudo): string
    {
        $opcoes = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'outputBase64' => true,
            'scale' => 4,
        ]);

        return (new QRCode($opcoes))->render($conteudo);
    }

    /**
     * As datas do documento, no formato em que a tela as mostra: o arquivo é
     * lido por gente, não por outro sistema.
     */
    public static function dataPorExtenso(?string $data): string
    {
        return $data === null ? 'data não informada' : Carbon::parse($data)->format('d/m/Y');
    }
}
