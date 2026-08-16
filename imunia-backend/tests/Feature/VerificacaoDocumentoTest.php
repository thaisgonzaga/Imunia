<?php

namespace Tests\Feature;

use App\Models\Exportacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * P09 — RF47, RN01, RN47.
 */
class VerificacaoDocumentoTest extends TestCase
{
    use RefreshDatabase;

    private function emitir(array $atributos = []): Exportacao
    {
        return Exportacao::factory()->create($atributos);
    }

    /**
     * RF47 — o desfecho feliz, e o teto do que a rota pode dizer.
     */
    public function test_documento_emitido_e_reconhecido_como_autentico(): void
    {
        $exportacao = $this->emitir([
            'codigo' => '9F2C4A81D7E05B33',
            'animal_nome' => 'Théo',
            'animal_especie' => 'cao',
            'emitido_em' => Carbon::parse('2026-01-12 09:30:00'),
        ]);

        $this->getJson('/api/documentos/9F2C4A81D7E05B33')
            ->assertOk()
            ->assertJson([
                'situacao' => 'autentico',
                'emitido_em' => '2026-01-12',
                'animal' => ['nome' => 'Théo', 'especie' => 'cao'],
                'resumo' => $exportacao->resumoAbreviado(),
            ]);
    }

    /**
     * RF47a e RN47 — a resposta é toda a informação que sai daqui. Nada de
     * conteúdo clínico, de tutor ou de prestador atravessa a rota aberta.
     */
    public function test_a_resposta_nao_carrega_nada_alem_do_que_prova_a_autenticidade(): void
    {
        $this->emitir(['codigo' => '9F2C4A81D7E05B33']);

        $resposta = $this->getJson('/api/documentos/9F2C4A81D7E05B33')->assertOk();

        $this->assertSame(
            ['situacao', 'emitido_em', 'animal', 'resumo'],
            array_keys($resposta->json()),
        );
        $this->assertSame(['nome', 'especie'], array_keys($resposta->json('animal')));
    }

    /**
     * O código é conferido como o conferente o lê no papel: em quatro grupos,
     * em qualquer caixa.
     */
    public function test_codigo_digitado_com_espacos_e_em_minusculas_confere(): void
    {
        $this->emitir(['codigo' => '9F2C4A81D7E05B33']);

        $this->getJson('/api/documentos/'.rawurlencode('9f2c 4a81 d7e0 5b33'))
            ->assertOk()
            ->assertJsonPath('situacao', 'autentico');
    }

    /**
     * RF47c — o código inexistente responde 200, como o existente. Fosse 404, o
     * status por si só já denunciaria quais códigos existem.
     */
    public function test_codigo_inexistente_responde_como_nao_localizado_sem_distinguir_o_status(): void
    {
        $this->getJson('/api/documentos/9F2C4A81D7E05B00')
            ->assertOk()
            ->assertExactJson(['situacao' => 'nao_localizado']);
    }

    /**
     * RF47b — o resumo vem do QR Code. Não batendo, o arquivo em mãos não é
     * mais o documento registrado sob aquele código.
     */
    public function test_resumo_divergente_denuncia_alteracao_do_documento(): void
    {
        $exportacao = $this->emitir(['codigo' => '9F2C4A81D7E05B33']);

        $this->getJson('/api/documentos/9F2C4A81D7E05B33?resumo=0000000000000000')
            ->assertOk()
            ->assertJson([
                'situacao' => 'divergente',
                'resumo' => $exportacao->resumoAbreviado(),
            ]);
    }

    public function test_resumo_conferente_mantem_o_documento_autentico(): void
    {
        $exportacao = $this->emitir(['codigo' => '9F2C4A81D7E05B33']);

        $this->getJson('/api/documentos/9F2C4A81D7E05B33?resumo='.strtolower($exportacao->resumoAbreviado()))
            ->assertOk()
            ->assertJsonPath('situacao', 'autentico');
    }

    /**
     * Erro de digitação não vira consulta: a tela já barra o formato, e o
     * servidor confirma a barreira sem gastar uma tentativa útil de quem
     * enumera.
     */
    public function test_codigo_de_comprimento_errado_e_recusado_antes_da_consulta(): void
    {
        $this->getJson('/api/documentos/9F2C4A81')
            ->assertStatus(422)
            ->assertJsonValidationErrors('codigo');
    }

    /**
     * RF47c — a pausa incide sobre a origem, não sobre o código, e por isso
     * alcança quem varre a base trocando de código a cada tentativa.
     */
    public function test_verificacoes_seguidas_de_codigos_diferentes_esbarram_na_mesma_pausa(): void
    {
        for ($tentativa = 0; $tentativa < 10; $tentativa++) {
            $this->getJson(sprintf('/api/documentos/9F2C4A81D7E0%04d', $tentativa))->assertOk();
        }

        $limitado = $this->getJson('/api/documentos/9F2C4A81D7E05B33');

        $limitado->assertStatus(429);
        $this->assertGreaterThan(0, $limitado->json('segundos_restantes'));
    }

    /**
     * A pausa não abre exceção para o documento que existe: fosse assim, o
     * próprio 429 viraria o sinal que RF47c manda esconder.
     */
    public function test_a_pausa_alcanca_tambem_o_documento_existente(): void
    {
        $this->emitir(['codigo' => '9F2C4A81D7E05B33']);

        for ($tentativa = 0; $tentativa < 10; $tentativa++) {
            $this->getJson(sprintf('/api/documentos/9F2C4A81D7E0%04d', $tentativa))->assertOk();
        }

        $this->getJson('/api/documentos/9F2C4A81D7E05B33')->assertStatus(429);
    }

    /**
     * RN01 — a ressalva é desta rota, e só dela.
     */
    public function test_a_rota_dispensa_autenticacao(): void
    {
        $this->emitir(['codigo' => '9F2C4A81D7E05B33']);

        $this->assertGuest();
        $this->getJson('/api/documentos/9F2C4A81D7E05B33')->assertOk();
    }
}
