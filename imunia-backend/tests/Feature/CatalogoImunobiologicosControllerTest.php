<?php

namespace Tests\Feature;

use App\Models\Imunobiologico;
use App\Models\Prestador;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * X01 — catálogo de imunobiológicos (RF23). Cobre o que a fatia introduz: o
 * papel `admin_plataforma` como porta de entrada, o cadastro com chave
 * gerada, a edição sem tocar em ativo/inativo, e a inativação que não
 * exclui (RF23b).
 */
class CatalogoImunobiologicosControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->adminPlataforma()->create();
    }

    private function payload(array $sobrescreve = []): array
    {
        return [...[
            'nome_comercial' => 'Quíntupla canina',
            'nome_tecnico' => 'Vacina polivalente canina (cinomose, parvovirose, leptospirose)',
            'fabricante' => 'Zoetis',
            'agentes_cobertos' => 'cinomose, parvovirose, leptospirose',
            'especie_destino' => 'cao',
            'classificacao' => 'essencial',
            'via_administracao_usual' => 'Subcutânea',
        ], ...$sobrescreve];
    }

    public function test_visitante_nao_autenticado_nao_acessa_o_catalogo(): void
    {
        $this->getJson('/api/plataforma/catalogo')->assertUnauthorized();
    }

    public function test_usuario_sem_o_papel_recebe_403_na_listagem(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/plataforma/catalogo')
            ->assertForbidden();
    }

    public function test_usuario_sem_o_papel_recebe_403_no_cadastro(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/plataforma/catalogo', $this->payload())
            ->assertForbidden();
    }

    public function test_admin_plataforma_lista_o_catalogo(): void
    {
        Imunobiologico::factory()->create(['nome_comercial' => 'V10 múltipla canina']);
        Imunobiologico::factory()->antirrabica()->create();

        $resposta = $this->actingAs($this->admin())->getJson('/api/plataforma/catalogo');

        $resposta->assertOk();
        $this->assertCount(2, $resposta->json('imunobiologicos'));
    }

    public function test_admin_plataforma_filtra_por_especie_e_classificacao(): void
    {
        Imunobiologico::factory()->create(['especie_destino' => 'cao', 'classificacao' => 'essencial']);
        Imunobiologico::factory()->create([
            'chave' => 'leucemia-felina',
            'especie_destino' => 'gato',
            'classificacao' => 'nao_essencial',
        ]);

        $resposta = $this->actingAs($this->admin())
            ->getJson('/api/plataforma/catalogo?especie=gato&classificacao=nao_essencial');

        $resposta->assertOk();
        $this->assertCount(1, $resposta->json('imunobiologicos'));
    }

    public function test_admin_plataforma_cadastra_um_item_com_chave_gerada(): void
    {
        $resposta = $this->actingAs($this->admin())
            ->postJson('/api/plataforma/catalogo', $this->payload());

        $resposta->assertCreated();
        $resposta->assertJsonPath('imunobiologico.chave', 'vacina-polivalente-canina-cinomose-parvovirose-leptospirose');
        $resposta->assertJsonPath('imunobiologico.ativo', true);

        $this->assertDatabaseHas('imunobiologicos', ['nome_comercial' => 'Quíntupla canina', 'fabricante' => 'Zoetis']);
    }

    public function test_cadastro_com_denominacao_tecnica_repetida_gera_chave_distinta(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/plataforma/catalogo', $this->payload())->assertCreated();

        $resposta = $this->actingAs($admin)->postJson('/api/plataforma/catalogo', $this->payload([
            'nome_comercial' => 'Quíntupla canina (outro fabricante)',
        ]));

        $resposta->assertCreated();
        $this->assertNotSame(
            Imunobiologico::where('nome_comercial', 'Quíntupla canina')->value('chave'),
            $resposta->json('imunobiologico.chave'),
        );
    }

    public function test_cadastro_exige_os_campos_do_catalogo(): void
    {
        $resposta = $this->actingAs($this->admin())->postJson('/api/plataforma/catalogo', []);

        $resposta->assertUnprocessable();
        $resposta->assertJsonValidationErrors([
            'nome_comercial', 'nome_tecnico', 'fabricante', 'agentes_cobertos',
            'especie_destino', 'classificacao', 'via_administracao_usual',
        ]);
    }

    public function test_admin_plataforma_edita_um_item_sem_alterar_a_chave(): void
    {
        $imunobiologico = Imunobiologico::factory()->create();

        $resposta = $this->actingAs($this->admin())
            ->postJson("/api/plataforma/catalogo/{$imunobiologico->id}", $this->payload([
                'nome_comercial' => 'V10 múltipla canina (revisada)',
            ]));

        $resposta->assertOk();
        $resposta->assertJsonPath('imunobiologico.nome_comercial', 'V10 múltipla canina (revisada)');
        $resposta->assertJsonPath('imunobiologico.chave', $imunobiologico->chave);
    }

    public function test_inativar_impede_novo_registro_mas_preserva_o_item(): void
    {
        $imunobiologico = Imunobiologico::factory()->create();

        $resposta = $this->actingAs($this->admin())
            ->postJson("/api/plataforma/catalogo/{$imunobiologico->id}/inativar");

        $resposta->assertOk();
        $resposta->assertJsonPath('imunobiologico.ativo', false);
        $this->assertDatabaseHas('imunobiologicos', ['id' => $imunobiologico->id, 'ativo' => false]);
    }

    public function test_reativar_devolve_o_item_a_escolha_de_novo_registro(): void
    {
        $imunobiologico = Imunobiologico::factory()->create(['ativo' => false]);

        $resposta = $this->actingAs($this->admin())
            ->postJson("/api/plataforma/catalogo/{$imunobiologico->id}/reativar");

        $resposta->assertOk();
        $resposta->assertJsonPath('imunobiologico.ativo', true);
    }

    /* O acervo próprio das clínicas, visto daqui (A04) ----------------------- */

    /**
     * Um item privado de uma clínica qualquer. A escrita do dono é forçada pelo
     * mesmo motivo que a produção o faz assim: `prestador_id` não está no
     * `#[Fillable]`, porque de quem é o item é decisão do servidor.
     */
    private function vacinaDeClinica(): Imunobiologico
    {
        $prestador = Prestador::factory()->create();

        $imunobiologico = Imunobiologico::factory()->create([
            'chave' => 'p'.$prestador->id.'-vacina-da-casa',
            'nome_comercial' => 'Vacina da casa',
        ]);

        $imunobiologico->forceFill(['prestador_id' => $prestador->id])->save();

        return $imunobiologico;
    }

    public function test_catalogo_da_plataforma_nao_lista_vacina_privada_de_clinica(): void
    {
        Imunobiologico::factory()->create(['chave' => 'triplice-felina']);
        $privada = $this->vacinaDeClinica();

        $resposta = $this->actingAs($this->admin())
            ->getJson('/api/plataforma/catalogo')
            ->assertOk();

        $chaves = collect($resposta->json('imunobiologicos'))->pluck('chave');

        $this->assertTrue($chaves->contains('triplice-felina'));
        $this->assertFalse($chaves->contains($privada->chave));
    }

    public function test_plataforma_nao_edita_vacina_privada_de_clinica(): void
    {
        $privada = $this->vacinaDeClinica();

        // 404 e não 403: o item não existe para esta tela, e dizer o contrário
        // contaria à plataforma qual clínica cadastrou o quê.
        $this->actingAs($this->admin())
            ->postJson("/api/plataforma/catalogo/{$privada->id}", [
                'nome_comercial' => 'Renomeada pela plataforma',
                'nome_tecnico' => 'Qualquer',
                'fabricante' => 'Qualquer',
                'agentes_cobertos' => 'qualquer',
                'especie_destino' => 'cao',
                'classificacao' => 'essencial',
                'via_administracao_usual' => 'Subcutânea',
            ])
            ->assertNotFound();

        $this->assertSame('Vacina da casa', $privada->fresh()->nome_comercial);
    }

    public function test_plataforma_nao_inativa_vacina_privada_de_clinica(): void
    {
        $privada = $this->vacinaDeClinica();

        $this->actingAs($this->admin())
            ->postJson("/api/plataforma/catalogo/{$privada->id}/inativar")
            ->assertNotFound();

        $this->assertTrue($privada->fresh()->ativo);
    }
}
