<?php

namespace App\Services;

use App\Models\AnexoAtendimento;
use App\Models\Animal;
use App\Models\Atendimento;
use App\Models\Prestador;
use App\Models\RegistroDeAcesso;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * V08 — registrar atendimento (RF31, RF32, RF34).
 *
 * O par de escrita do `AtendimentoService`, que só lê. A separação repete a de
 * V07 (`RegistroDeVacinacaoService` ao lado de `CalendarioVacinalService`), e
 * pelo mesmo motivo: ler o prontuário e escrevê-lo são atos distintos, e um
 * arquivo que faz os dois é um arquivo em que a imutabilidade de RN26 depende
 * de ninguém chamar o método errado.
 *
 * Três decisões governam este serviço:
 *
 * 1. **O anexo é enviado antes da confirmação, e só vira registro com ela.**
 *    Enquanto o profissional escreve, o arquivo vive no rascunho — disco
 *    privado, pasta do próprio usuário, sem linha em `anexos_atendimento`.
 *    Isso é o que permite recusar formato e tamanho (RN28) no ato do envio,
 *    mostrar progresso por arquivo e preservar o que já subiu quando a
 *    gravação falha. E é o que impede que um anexo exista sem o registro
 *    clínico a que RN28 manda que ele se vincule.
 *
 * 2. **O título não é campo do formulário.** A coluna existe porque a linha do
 *    tempo (RF35) e o cabeçalho de T08 precisam nomear o atendimento em uma
 *    linha; pedi-lo ao profissional seria um campo a mais numa tela que já é
 *    seis campos de texto longo. Deriva-se do motivo da consulta, que é como o
 *    tutor reencontra o registro depois — nunca do diagnóstico, que muitas
 *    vezes é a pendência ("Aguardando resultado do raspado") e não o nome do
 *    que aconteceu.
 *
 * 3. **Nada aqui altera registro existente.** Não há método de atualização, e a
 *    ausência é a implementação de RN26: a correção é a retificação vinculada
 *    de V09, que cria outro atendimento apontando para este.
 */
class RegistroDeAtendimentoService
{
    /**
     * Janela em que um segundo pedido idêntico é lido como repetição, e não
     * como segunda consulta. O mesmo número e o mesmo argumento de V07: o
     * registro é imutável (RN26) e V09 ainda não existe, de modo que um
     * reenvio da rede — em que ninguém tocou em nada duas vezes — deixaria dois
     * prontuários permanentes onde houve uma consulta.
     */
    private const JANELA_DUPLICIDADE_MINUTOS = 10;

    /** Onde o anexo espera pela confirmação, separado por usuário. */
    private const PASTA_RASCUNHO = 'rascunhos/anexos';

    /** Onde ele passa a viver quando o atendimento é confirmado. */
    private const PASTA_ANEXOS = 'anexos';

    /**
     * Prazo do rascunho de anexo. Passado ele, o arquivo é apagado na próxima
     * abertura da tela pelo mesmo profissional: um anexo que nunca virou
     * registro é papel esquecido no balcão, e guardá-lo indefinidamente seria
     * manter dado clínico fora de todo controle de autorização.
     */
    private const HORAS_DE_VIDA_DO_RASCUNHO = 24;

    /**
     * A abertura da tela: o que o formulário precisa saber antes da primeira
     * tecla.
     *
     * @return array<string, mixed>
     */
    public function montar(User $profissional, Prestador $prestador, Animal $animal): array
    {
        $this->limparRascunhosVencidos($profissional);

        $atendimentos = $animal->atendimentos()->get();
        $deOutroPrestador = $this->temRegistroDeOutroPrestador($animal, $prestador);

        // RF52b — a gravação do log precede a exibição, como em V06 e V07. Não é
        // formalidade: a tela conta ao profissional quanto o animal pesava na
        // consulta anterior e que há um retorno em aberto, e esses dois fatos
        // podem ter sido escritos por outra clínica.
        if ($deOutroPrestador) {
            $this->registrarAcesso($profissional, $prestador, $animal);
        }

        return [
            'animal' => [
                'codigo' => $animal->codigo,
                'nome' => $animal->nome,
                'especie' => $animal->especie,
                'idade_em_meses' => $animal->idadeEmMeses(),
                'nascimento_exato' => $animal->nascimento_exato,
                'tutor' => $animal->tutor->nome,
            ],

            // RF31 — nome e CRMV de quem responde pelo registro. Vem do acesso,
            // não do corpo do pedido, e a tela o exibe sem campo editável.
            'profissional' => [
                'nome' => $profissional->name,
                'crmv' => $profissional->crmvEm($prestador),
            ],

            // RF31b — data e hora são do sistema. Viajam para que o cabeçalho
            // possa carimbar o momento em que a tela foi aberta, e não para que
            // o formulário as devolva: a gravação usa a hora do servidor.
            'momento' => Carbon::now()->toIso8601String(),

            'peso_anterior' => $this->pesoAnterior($atendimentos),
            'retorno_em_aberto' => $this->retornoEmAberto($atendimentos),
            'aviso_outro_prestador' => $deOutroPrestador,

            // RN28 — os limites saem daqui para que a tela os anuncie antes da
            // escolha do arquivo, com os mesmos números que a recusa cita.
            'limites_anexo' => [
                'tamanho_maximo_mb' => (int) (AnexoAtendimento::TAMANHO_MAXIMO_KB / 1024),
                'formatos' => ['PDF', 'JPG', 'PNG'],
                'quantidade_maxima' => 10,
            ],
        ];
    }

    /**
     * RF32 — o arquivo enviado antes da confirmação. Guardado na pasta do
     * próprio profissional, com nome sorteado e extensão derivada do formato
     * **conferido**, não do nome que o arquivo trazia: um "laudo.pdf.exe" não
     * escolhe como será guardado.
     *
     * @return array<string, mixed>
     */
    public function guardarRascunhoDeAnexo(User $profissional, UploadedFile $arquivo): array
    {
        $extensao = $this->extensaoDe($arquivo->getMimeType() ?? '');
        $token = (string) Str::uuid();

        $arquivo->storeAs(
            $this->pastaDoRascunho($profissional),
            "{$token}.{$extensao}",
            AnexoAtendimento::DISCO,
        );

        return [
            'token' => $token,
            'nome' => $arquivo->getClientOriginalName(),
            'tamanho_bytes' => $arquivo->getSize(),
            'tipo' => AnexoAtendimento::tipoDe($arquivo->getMimeType() ?? ''),
        ];
    }

    /**
     * O arquivo do rascunho, quando existir e for deste profissional. A posse
     * está no caminho: a pasta é do usuário, e um token de outra pessoa
     * simplesmente não é encontrado — não há aqui comparação a esquecer.
     */
    public function caminhoDoRascunho(User $profissional, string $token): ?string
    {
        if (! Str::isUuid($token)) {
            return null;
        }

        return collect(Storage::disk(AnexoAtendimento::DISCO)->files($this->pastaDoRascunho($profissional)))
            ->first(fn (string $caminho) => pathinfo($caminho, PATHINFO_FILENAME) === $token);
    }

    /**
     * Descartar o anexo antes de confirmar é operação legítima, e não fere
     * RN28: o que a regra torna imutável é o anexo do registro confirmado. Este
     * ainda não é anexo de coisa alguma.
     */
    public function descartarRascunhoDeAnexo(User $profissional, string $token): bool
    {
        $caminho = $this->caminhoDoRascunho($profissional, $token);

        if ($caminho === null) {
            return false;
        }

        return Storage::disk(AnexoAtendimento::DISCO)->delete($caminho);
    }

    /**
     * RF31 — a gravação do prontuário. A data, a hora e a autoria são do
     * sistema (RF31b); o prestador é o ativo no momento da criação (RF31c).
     *
     * @param  array<string, mixed>  $dados
     */
    public function registrar(User $profissional, Prestador $prestador, Animal $animal, array $dados): Atendimento
    {
        return DB::transaction(function () use ($profissional, $prestador, $animal, $dados) {
            $repetido = $this->repeticaoRecente($animal, $prestador, $profissional, $dados['motivo']);

            if ($repetido !== null) {
                return $repetido;
            }

            $atendimento = $animal->atendimentos()->create([
                'prestador_id' => $prestador->id,
                'retifica_atendimento_id' => null,
                'motivo_retificacao' => null,

                // RF31b — do relógio do servidor, nunca do formulário.
                'atendido_em' => Carbon::now(),

                'titulo' => $this->tituloDe($dados['motivo']),
                'motivo' => $dados['motivo'],
                'anamnese' => $dados['anamnese'],
                'exame_fisico' => $dados['exame_fisico'],
                'hipoteses_diagnosticas' => $dados['hipoteses_diagnosticas'],
                'diagnostico' => $dados['diagnostico'] ?? null,
                'conduta' => $dados['conduta'],
                'peso_kg' => $dados['peso_kg'] ?? null,

                // RF31 — identificação do responsável, congelada no registro: o
                // prontuário não muda porque o cadastro do veterinário mudou
                // depois. O vínculo com `users` é o que RN27 consulta em V09.
                'profissional_user_id' => $profissional->id,
                'profissional_nome' => $profissional->name,
                'profissional_crmv' => $profissional->crmvEm($prestador),

                // RF34 — data prevista e finalidade descrita, ou nem uma nem
                // outra: meio retorno seria compromisso sem o que cumprir.
                'retorno_em' => $dados['retorno_em'] ?? null,
                'retorno_finalidade' => $dados['retorno_finalidade'] ?? null,
            ]);

            $this->vincularAnexos($profissional, $atendimento, $dados['anexos'] ?? []);

            return $atendimento;
        });
    }

    /**
     * RF32 — os anexos do rascunho passam a ser do registro. A movimentação
     * acontece dentro da transação: se um arquivo não puder ser movido, o
     * atendimento não é gravado, e o profissional reenvia o que escreveu com os
     * anexos ainda no lugar. Prontuário que afirma ter laudo sem ter o arquivo
     * é pior do que a falha que o causou.
     *
     * @param  array<int, array<string, mixed>>  $anexos
     */
    private function vincularAnexos(User $profissional, Atendimento $atendimento, array $anexos): void
    {
        foreach ($anexos as $anexo) {
            $origem = $this->caminhoDoRascunho($profissional, $anexo['token']);

            if ($origem === null) {
                continue;
            }

            $extensao = pathinfo($origem, PATHINFO_EXTENSION);
            $destino = self::PASTA_ANEXOS.'/'.Str::uuid().'.'.$extensao;
            $disco = Storage::disk(AnexoAtendimento::DISCO);

            $tamanho = $disco->size($origem);
            $disco->move($origem, $destino);

            $mime = $this->mimeDe($extensao);

            $atendimento->anexos()->create([
                'descricao' => $anexo['descricao'],
                'exame_em' => $anexo['exame_em'] ?? null,
                'tipo' => AnexoAtendimento::tipoDe($mime),

                // O formato é o que foi conferido no envio, e a extensão do
                // arquivo guardado foi escolhida a partir dele — por isso um
                // deriva do outro sem reabrir o arquivo para adivinhar.
                'mime' => $mime,
                'tamanho_bytes' => $tamanho,
                'caminho' => $destino,
            ]);
        }
    }

    /**
     * O segundo pedido idêntico dentro da janela. Compara motivo, e não o
     * prontuário inteiro: dois envios do mesmo formulário trazem o mesmo texto,
     * e duas consultas de verdade ao mesmo animal, no mesmo prestador, pelo
     * mesmo profissional e em dez minutos não têm o mesmo motivo.
     */
    private function repeticaoRecente(
        Animal $animal,
        Prestador $prestador,
        User $profissional,
        string $motivo,
    ): ?Atendimento {
        return $animal->atendimentos()
            ->where('prestador_id', $prestador->id)
            ->where('profissional_user_id', $profissional->id)
            ->where('motivo', $motivo)
            ->whereNull('retifica_atendimento_id')
            ->where('atendido_em', '>=', Carbon::now()->subMinutes(self::JANELA_DUPLICIDADE_MINUTOS))
            ->latest('atendido_em')
            ->first();
    }

    /**
     * O nome do atendimento em uma linha: a primeira oração do motivo da
     * consulta, sem a pontuação final e limitada ao que cabe numa célula da
     * linha do tempo.
     *
     * Pública desde V09, que precisa nomear a retificação pelo motivo
     * corrigido: duas regras de titulação fariam a correção aparecer na linha
     * do tempo com um nome que o original nunca teria recebido.
     */
    public function tituloDe(string $motivo): string
    {
        $primeira = preg_split('/(?<=[.;!?])\s+/u', trim($motivo), 2)[0] ?? $motivo;

        return Str::limit(rtrim(trim($primeira), " \t\n\r.;!?"), 80);
    }

    /**
     * A medição anterior, para que a tela possa dizer que o peso de hoje não
     * sobrescreve nada (RF19, observação de modelagem). Vem com a data porque é
     * ela que faz do número uma série, e não um valor atual.
     *
     * @param  Collection<int, Atendimento>  $atendimentos
     * @return array<string, string>|null
     */
    private function pesoAnterior(Collection $atendimentos): ?array
    {
        /** @var Atendimento|null $ultimo */
        $ultimo = $atendimentos
            ->filter(fn (Atendimento $atendimento) => $atendimento->peso_kg !== null)
            ->sortByDesc('atendido_em')
            ->first();

        if ($ultimo === null) {
            return null;
        }

        return [
            'valor' => (string) $ultimo->peso_kg,
            'em' => $ultimo->atendido_em->toDateString(),
        ];
    }

    /**
     * RN29 — o retorno em aberto, pela mesma conta que a ficha clínica faz: um
     * retorno cuja data já foi ultrapassada por atendimento posterior está
     * cumprido, e ninguém precisou marcá-lo como tal.
     *
     * Vai junto se **este** registro o encerrará, que é o que o diálogo de
     * confirmação precisa dizer: confirmar o atendimento tem essa consequência
     * fora da tela, no painel de pendências e no lembrete da tutora.
     *
     * @param  Collection<int, Atendimento>  $atendimentos
     * @return array<string, mixed>|null
     */
    private function retornoEmAberto(Collection $atendimentos): ?array
    {
        $ultimoAtendimento = $atendimentos->max('atendido_em');

        /** @var Atendimento|null $retorno */
        $retorno = $atendimentos
            ->filter(fn (Atendimento $atendimento) => $atendimento->retorno_em !== null)
            ->reject(fn (Atendimento $atendimento) => $ultimoAtendimento?->gt($atendimento->retorno_em) ?? false)
            ->sortBy('retorno_em')
            ->first();

        if ($retorno === null) {
            return null;
        }

        return [
            'em' => $retorno->retorno_em->toDateString(),
            'finalidade' => $retorno->retorno_finalidade,
            'encerrado_por_este' => $retorno->retorno_em->lte(Carbon::today()),
        ];
    }

    private function temRegistroDeOutroPrestador(Animal $animal, Prestador $prestador): bool
    {
        $vacinacaoAlheia = $animal->vacinacoes()
            ->whereNotNull('prestador_id')
            ->where('prestador_id', '!=', $prestador->id)
            ->exists();

        return $vacinacaoAlheia || $animal->atendimentos()
            ->where('prestador_id', '!=', $prestador->id)
            ->exists();
    }

    private function registrarAcesso(User $profissional, Prestador $prestador, Animal $animal): void
    {
        RegistroDeAcesso::create([
            'prestador_id' => $prestador->id,
            'user_id' => $profissional->id,
            'tutor_id' => $animal->tutor_id,
            'animal_id' => $animal->id,
            'natureza' => RegistroDeAcesso::HISTORICO_DE_OUTRO_PRESTADOR,
            'ocorrido_em' => Carbon::now(),
        ]);
    }

    private function limparRascunhosVencidos(User $profissional): void
    {
        $disco = Storage::disk(AnexoAtendimento::DISCO);
        $limite = Carbon::now()->subHours(self::HORAS_DE_VIDA_DO_RASCUNHO)->getTimestamp();

        foreach ($disco->files($this->pastaDoRascunho($profissional)) as $caminho) {
            if ($disco->lastModified($caminho) < $limite) {
                $disco->delete($caminho);
            }
        }
    }

    private function pastaDoRascunho(User $profissional): string
    {
        return self::PASTA_RASCUNHO.'/'.$profissional->id;
    }

    private function extensaoDe(string $mime): string
    {
        return match ($mime) {
            'application/pdf' => 'pdf',
            'image/png' => 'png',
            default => 'jpg',
        };
    }

    private function mimeDe(string $extensao): string
    {
        return match ($extensao) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            default => 'image/jpeg',
        };
    }
}
