<?php

namespace Tests\Feature;

use App\Models\Prestador;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A02 — dados cadastrais do prestador (RF08).
 */
class DadosPrestadorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * O CNPJ é fixo, e não o que a fábrica sortearia, porque os testes daqui o
     * afirmam por extenso: a asserção que lê o cadastro compara o valor letra a
     * letra, e um número sorteado a cada execução não teria como ser escrito.
     */
    private function clinica(array $atributos = []): Prestador
    {
        return Prestador::factory()->create(['cnpj' => '11222333000181', ...$atributos]);
    }

    private function administradora(Prestador $prestador): User
    {
        $usuario = User::factory()->create(['name' => 'Ana Lúcia Ferraz']);
        $usuario->prestadores()->attach($prestador, ['papel' => 'admin_prestador']);

        return $usuario;
    }

    /** O corpo íntegro do formulário, para o teste alterar só o que lhe interessa. */
    private function formulario(Prestador $prestador, array $mudancas = []): array
    {
        return [
            'tipo' => $prestador->tipo,
            'nome' => $prestador->nome,
            'cnpj' => $prestador->cnpj,
            'telefone' => $prestador->telefone,
            'endereco' => $prestador->endereco,
            'cep' => $prestador->cep,
            'municipio' => $prestador->municipio,
            'uf' => $prestador->uf,
            'responsavel_tecnico_nome' => $prestador->responsavel_tecnico_nome,
            'responsavel_tecnico_crmv' => $prestador->responsavel_tecnico_crmv,
            'responsavel_tecnico_crmv_uf' => $prestador->responsavel_tecnico_crmv_uf,
            ...$mudancas,
        ];
    }

    public function test_a_leitura_exige_sessao_administrativa(): void
    {
        $this->getJson('/api/prestador/dados')->assertUnauthorized();
    }

    public function test_a_leitura_traz_os_campos_sem_mascara(): void
    {
        $clinica = $this->clinica(['cnpj' => '11222333000181']);

        $this->actingAs($this->administradora($clinica))
            ->getJson('/api/prestador/dados')
            ->assertOk()
            ->assertJsonPath('prestador.cnpj', '11222333000181')
            ->assertJsonPath('opcoes.tipos.0.rotulo', 'Clínica veterinária')
            ->assertJsonCount(27, 'opcoes.ufs');
    }

    public function test_atualiza_a_denominacao_e_registra_a_alteracao_com_autor_e_momento(): void
    {
        $clinica = $this->clinica(['nome' => 'Clínica Vet Amigo']);
        $administradora = $this->administradora($clinica);

        $this->actingAs($administradora)
            ->postJson('/api/prestador/dados', $this->formulario($clinica, ['nome' => 'Clínica Vet Amigo Ltda.']))
            ->assertOk()
            ->assertJsonPath('prestador.nome', 'Clínica Vet Amigo Ltda.')
            ->assertJsonPath('historico.0.campo', 'Razão social ou nome')
            ->assertJsonPath('historico.0.de', 'Clínica Vet Amigo')
            ->assertJsonPath('historico.0.para', 'Clínica Vet Amigo Ltda.')
            ->assertJsonPath('historico.0.autor', 'Ana Lúcia Ferraz');

        $this->assertDatabaseHas('prestadores', ['id' => $clinica->id, 'nome' => 'Clínica Vet Amigo Ltda.']);
    }

    /** RF08a — o documento já emitido conserva a denominação da época. */
    public function test_alterar_a_denominacao_devolve_o_aviso_sobre_documentos_ja_emitidos(): void
    {
        $clinica = $this->clinica(['nome' => 'Clínica Vet Amigo']);

        $resposta = $this->actingAs($this->administradora($clinica))
            ->postJson('/api/prestador/dados', $this->formulario($clinica, ['nome' => 'Vet Amigo Ltda.']))
            ->assertOk();

        $this->assertStringContainsString(
            'nome vigente na data de emissão',
            implode(' ', $resposta->json('avisos')),
        );
    }

    /** RF08b — o município alimenta o diretório consultado pelo tutor. */
    public function test_alterar_o_municipio_devolve_o_aviso_sobre_o_diretorio(): void
    {
        $clinica = $this->clinica(['municipio' => 'Viçosa', 'uf' => 'MG']);

        $resposta = $this->actingAs($this->administradora($clinica))
            ->postJson('/api/prestador/dados', $this->formulario($clinica, ['municipio' => 'Belo Horizonte']))
            ->assertOk();

        $this->assertStringContainsString(
            'Belo Horizonte, MG',
            implode(' ', $resposta->json('avisos')),
        );
    }

    public function test_salvar_sem_mudanca_nao_gera_historico_nem_aviso(): void
    {
        $clinica = $this->clinica();

        $this->actingAs($this->administradora($clinica))
            ->postJson('/api/prestador/dados', $this->formulario($clinica))
            ->assertOk()
            ->assertJsonCount(0, 'historico')
            ->assertJsonCount(0, 'avisos');
    }

    public function test_cnpj_de_outro_prestador_e_rejeitado(): void
    {
        $clinica = $this->clinica(['cnpj' => '11222333000181']);
        $outra = $this->clinica(['nome' => 'Pet Center', 'cnpj' => '11444777000161']);

        $this->actingAs($this->administradora($clinica))
            ->postJson('/api/prestador/dados', $this->formulario($clinica, ['cnpj' => $outra->cnpj]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('cnpj');
    }

    public function test_cnpj_do_proprio_prestador_e_aceito(): void
    {
        $clinica = $this->clinica(['cnpj' => '11222333000181']);

        $this->actingAs($this->administradora($clinica))
            ->postJson('/api/prestador/dados', $this->formulario($clinica, ['nome' => 'Outro nome']))
            ->assertOk();
    }

    public function test_cep_com_menos_de_oito_digitos_e_rejeitado(): void
    {
        $clinica = $this->clinica();

        $this->actingAs($this->administradora($clinica))
            ->postJson('/api/prestador/dados', $this->formulario($clinica, ['cep' => '3657']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('cep');
    }

    public function test_crmv_com_prefixo_e_rejeitado(): void
    {
        $clinica = $this->clinica();

        $this->actingAs($this->administradora($clinica))
            ->postJson('/api/prestador/dados', $this->formulario($clinica, [
                'responsavel_tecnico_crmv' => 'CRMV-MG 20981',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('responsavel_tecnico_crmv');
    }

    public function test_cep_mascarado_e_guardado_em_digitos(): void
    {
        $clinica = $this->clinica();

        $this->actingAs($this->administradora($clinica))
            ->postJson('/api/prestador/dados', $this->formulario($clinica, ['cep' => '36570-000']))
            ->assertOk()
            ->assertJsonPath('prestador.cep', '36570000');
    }

    /**
     * RF07c — é este caminho que torna o estado bloqueante de A01 alcançável:
     * o responsável técnico sai da clínica antes de haver substituto.
     */
    public function test_responsavel_tecnico_pode_ser_limpo_por_inteiro(): void
    {
        $clinica = $this->clinica();

        $resposta = $this->actingAs($this->administradora($clinica))
            ->postJson('/api/prestador/dados', $this->formulario($clinica, [
                'responsavel_tecnico_nome' => null,
                'responsavel_tecnico_crmv' => null,
                'responsavel_tecnico_crmv_uf' => null,
            ]))
            ->assertOk()
            ->assertJsonPath('prestador.responsavel_tecnico_nome', null);

        $this->assertStringContainsString(
            'nenhuma informação clínica pode ser registrada',
            implode(' ', $resposta->json('avisos')),
        );
    }

    public function test_limpar_o_responsavel_tecnico_faz_o_painel_bloquear(): void
    {
        $clinica = $this->clinica();
        $administradora = $this->administradora($clinica);

        $this->actingAs($administradora)->postJson('/api/prestador/dados', $this->formulario($clinica, [
            'responsavel_tecnico_nome' => null,
            'responsavel_tecnico_crmv' => null,
            'responsavel_tecnico_crmv_uf' => null,
        ]))->assertOk();

        $this->actingAs($administradora)
            ->getJson('/api/prestador/painel')
            ->assertJsonPath('bloqueio.chave', 'sem_responsavel_tecnico');
    }

    /** RN09 — CRMV sem UF não é registro, e meio responsável técnico não identifica ninguém. */
    public function test_responsavel_tecnico_pela_metade_e_rejeitado(): void
    {
        $clinica = $this->clinica();

        $this->actingAs($this->administradora($clinica))
            ->postJson('/api/prestador/dados', $this->formulario($clinica, [
                'responsavel_tecnico_nome' => 'Marcelo Andrade',
                'responsavel_tecnico_crmv' => null,
                'responsavel_tecnico_crmv_uf' => null,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('responsavel_tecnico_nome');
    }

    public function test_veterinario_sem_papel_administrativo_nao_altera_os_dados(): void
    {
        $clinica = $this->clinica();
        $veterinario = User::factory()->create();
        $veterinario->prestadores()->attach($clinica, [
            'papel' => 'veterinario',
            'crmv' => '12345',
            'crmv_uf' => 'MG',
        ]);

        $this->actingAs($veterinario)
            ->postJson('/api/prestador/dados', $this->formulario($clinica, ['nome' => 'Invadida']))
            ->assertForbidden();

        $this->assertDatabaseMissing('prestadores', ['nome' => 'Invadida']);
    }

    /** O histórico é registro de auditoria: não há caminho de escrita que o altere (RF08). */
    public function test_o_historico_de_alteracoes_e_imutavel_por_esquema(): void
    {
        $this->assertFalse(Schema::hasColumn('alteracoes_prestador', 'updated_at'));
    }
}
