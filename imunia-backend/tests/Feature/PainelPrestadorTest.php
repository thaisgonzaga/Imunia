<?php

namespace Tests\Feature;

use App\Models\Convite;
use App\Models\Prestador;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A01 — painel administrativo do prestador (RF07, RF08, RF09).
 */
class PainelPrestadorTest extends TestCase
{
    use RefreshDatabase;

    private function clinica(array $atributos = []): Prestador
    {
        return Prestador::factory()->create($atributos);
    }

    private function administradora(Prestador ...$prestadores): User
    {
        $usuario = User::factory()->create(['name' => 'Ana Lúcia Ferraz']);

        foreach ($prestadores as $prestador) {
            $usuario->prestadores()->attach($prestador, ['papel' => 'admin_prestador']);
        }

        return $usuario;
    }

    private function veterinario(Prestador $prestador, string $nome, string $crmv, array $pivo = []): User
    {
        $usuario = User::factory()->create(['name' => $nome]);

        $usuario->prestadores()->attach($prestador, [
            'papel' => 'veterinario',
            'crmv' => $crmv,
            'crmv_uf' => 'MG',
            ...$pivo,
        ]);

        return $usuario;
    }

    /** Um convite de equipe ainda não aceito, com o prazo que o teste precisar. */
    private function convidado(Prestador $prestador, string $email, int $expiraEmDias): User
    {
        $usuario = User::factory()->create(['name' => '', 'email' => $email, 'ativado_em' => null]);
        $usuario->prestadores()->attach($prestador, [
            'papel' => 'veterinario',
            'crmv' => '99999',
            'crmv_uf' => 'MG',
        ]);

        [$convite] = Convite::emitir($usuario, $prestador, 'veterinario');
        $convite->forceFill(['expira_em' => Carbon::now()->addDays($expiraEmDias)])->save();

        return $usuario;
    }

    public function test_o_painel_exige_sessao(): void
    {
        $this->getJson('/api/prestador/painel')->assertUnauthorized();
    }

    /** RN08 — quem não administra conta alguma está na tela errada, não numa tela vazia. */
    /**
     * Quem atende aqui vê onde trabalha, e não administra nada: o painel abre
     * sem a lista de pendências, que é a tarefa de quem pode resolvê-las.
     */
    public function test_veterinario_sem_papel_administrativo_ve_o_prestador_sem_administrar(): void
    {
        $clinica = $this->clinica(['nome' => 'Clínica Vet Amigo']);
        $veterinario = $this->veterinario($clinica, 'Marcelo Andrade', '12345');

        $this->actingAs($veterinario)
            ->getJson('/api/prestador/painel')
            ->assertOk()
            ->assertJsonPath('prestador.nome', 'Clínica Vet Amigo')
            ->assertJsonPath('pode_administrar', false)
            ->assertJsonPath('pendencias', [])
            ->assertJsonPath('vinculos', []);
    }

    /** Sem vínculo vigente algum não há prestador de que falar. */
    public function test_quem_nao_tem_vinculo_com_prestador_recebe_403(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/prestador/painel')
            ->assertForbidden();
    }

    public function test_o_painel_traz_a_identificacao_do_estabelecimento(): void
    {
        $clinica = $this->clinica(['nome' => 'Clínica Vet Amigo', 'municipio' => 'Viçosa', 'uf' => 'MG']);

        $this->actingAs($this->administradora($clinica))
            ->getJson('/api/prestador/painel')
            ->assertOk()
            ->assertJsonPath('prestador.nome', 'Clínica Vet Amigo')
            ->assertJsonPath('prestador.tipo_rotulo', 'Clínica veterinária')
            ->assertJsonPath('prestador.municipio', 'Viçosa')
            ->assertJsonPath('prestador.responsavel_tecnico_crmv', 'CRMV-MG 12345');
    }

    public function test_o_painel_conta_ativos_convites_pendentes_e_vinculos_encerrados(): void
    {
        $clinica = $this->clinica();
        $administradora = $this->administradora($clinica);

        $this->veterinario($clinica, 'Marcelo Andrade', '12345');
        $this->veterinario($clinica, 'Larissa Nogueira', '20981');
        $this->veterinario($clinica, 'Henrique Vaz', '09112', ['encerrado_em' => Carbon::now()->subMonth()]);
        $this->convidado($clinica, 'beatriz@example.com', 5);

        $this->actingAs($administradora)
            ->getJson('/api/prestador/painel')
            ->assertOk()
            ->assertJsonPath('equipe.ativos', 2)
            ->assertJsonPath('equipe.convites_pendentes', 1)
            ->assertJsonPath('equipe.encerrados', 1);
    }

    /**
     * RN08 — o painel administrativo não alcança dado clínico, e indicador
     * agregado continua sendo dado clínico: saber quantas vacinas a clínica
     * aplicou é saber algo sobre os animais atendidos ali.
     *
     * A ausência é o argumento de projeto destas telas, e é por isso que ela
     * tem um teste em vez de ficar por conta da boa memória de quem mexer aqui
     * depois.
     */
    public function test_o_painel_nao_devolve_indicador_clinico_algum(): void
    {
        $clinica = $this->clinica();

        $resposta = $this->actingAs($this->administradora($clinica))
            ->getJson('/api/prestador/painel')
            ->assertOk();

        foreach (['animais', 'atendimentos', 'vacinacoes', 'autorizacoes', 'pendencias_vacinais', 'tutores'] as $proibido) {
            $resposta->assertJsonMissingPath($proibido);
        }
    }

    /**
     * A moldura que a tela veste sai do servidor: quem administra a conta em
     * que atende permanece na do ambiente clínico, e não troca de casca ao
     * clicar em "Painel da conta".
     */
    public function test_o_painel_diz_se_a_pessoa_tambem_atende_no_prestador(): void
    {
        $clinica = $this->clinica();

        $this->actingAs($this->administradora($clinica))
            ->getJson('/api/prestador/painel')
            ->assertOk()
            ->assertJsonPath('atende_aqui', false)
            ->assertJsonPath('vinculos_clinicos', []);

        $veterinaria = $this->veterinario($clinica, 'Larissa Nogueira', '20981');
        $veterinaria->prestadores()->attach($clinica, ['papel' => 'admin_prestador']);

        $this->actingAs($veterinaria)
            ->getJson('/api/prestador/painel')
            ->assertOk()
            ->assertJsonPath('atende_aqui', true)
            ->assertJsonPath('vinculos_clinicos.0.nome', $clinica->nome)
            ->assertJsonPath('vinculos_clinicos.0.admin', true);
    }

    /**
     * RN08 na lista da moldura: os vínculos vêm com nome e nada mais. A
     * contagem de animais que o ambiente clínico exibe é dado agregado de
     * animal, e o payload administrativo não a carrega.
     */
    public function test_os_vinculos_da_moldura_nao_trazem_contagem_de_animais(): void
    {
        $clinica = $this->clinica();
        $veterinaria = $this->veterinario($clinica, 'Larissa Nogueira', '20981');
        $veterinaria->prestadores()->attach($clinica, ['papel' => 'admin_prestador']);

        $resposta = $this->actingAs($veterinaria)->getJson('/api/prestador/painel')->assertOk();

        $this->assertSame(['id', 'nome', 'admin'], array_keys($resposta->json('vinculos_clinicos.0')));
    }

    /** RF07c — sem responsável técnico não há a quem atribuir uma aplicação. */
    public function test_prestador_sem_responsavel_tecnico_recebe_alerta_bloqueante(): void
    {
        $clinica = $this->clinica([
            'responsavel_tecnico_nome' => null,
            'responsavel_tecnico_crmv' => null,
            'responsavel_tecnico_crmv_uf' => null,
        ]);

        $this->actingAs($this->administradora($clinica))
            ->getJson('/api/prestador/painel')
            ->assertOk()
            ->assertJsonPath('bloqueio.chave', 'sem_responsavel_tecnico')
            ->assertJsonPath('bloqueio.acao.tipo', 'ir_para_dados');
    }

    public function test_prestador_com_responsavel_tecnico_nao_tem_bloqueio(): void
    {
        $clinica = $this->clinica();

        $this->actingAs($this->administradora($clinica))
            ->getJson('/api/prestador/painel')
            ->assertOk()
            ->assertJsonPath('bloqueio', null);
    }

    public function test_endereco_sem_cep_vira_pendencia_de_configuracao(): void
    {
        $clinica = $this->clinica(['cep' => null]);

        $resposta = $this->actingAs($this->administradora($clinica))
            ->getJson('/api/prestador/painel')
            ->assertOk();

        $chaves = collect($resposta->json('pendencias'))->pluck('chave');

        $this->assertTrue($chaves->contains('endereco_incompleto'));
    }

    public function test_cep_preenchido_encerra_a_pendencia_de_endereco(): void
    {
        $clinica = $this->clinica(['cep' => '36570000']);

        $resposta = $this->actingAs($this->administradora($clinica))
            ->getJson('/api/prestador/painel')
            ->assertOk();

        $chaves = collect($resposta->json('pendencias'))->pluck('chave');

        $this->assertFalse($chaves->contains('endereco_incompleto'));
    }

    public function test_convite_prestes_a_expirar_vira_pendencia_com_acao_de_reenvio(): void
    {
        $clinica = $this->clinica();
        $administradora = $this->administradora($clinica);
        $this->convidado($clinica, 'beatriz@example.com', 2);

        $resposta = $this->actingAs($administradora)
            ->getJson('/api/prestador/painel')
            ->assertOk();

        $pendencia = collect($resposta->json('pendencias'))->firstWhere('chave', 'convite_a_expirar');

        $this->assertNotNull($pendencia);
        $this->assertSame('O convite para beatriz@example.com expira em 2 dias.', $pendencia['descricao']);
        $this->assertSame('reenviar_convite', $pendencia['acao']['tipo']);
    }

    public function test_convite_folgado_nao_vira_pendencia(): void
    {
        $clinica = $this->clinica();
        $administradora = $this->administradora($clinica);
        $this->convidado($clinica, 'beatriz@example.com', 6);

        $resposta = $this->actingAs($administradora)
            ->getJson('/api/prestador/painel')
            ->assertOk();

        $chaves = collect($resposta->json('pendencias'))->pluck('chave');

        $this->assertFalse($chaves->contains('convite_a_expirar'));
    }

    public function test_equipe_sem_veterinario_ativo_vira_pendencia(): void
    {
        $clinica = $this->clinica();

        $resposta = $this->actingAs($this->administradora($clinica))
            ->getJson('/api/prestador/painel')
            ->assertOk();

        $chaves = collect($resposta->json('pendencias'))->pluck('chave');

        $this->assertTrue($chaves->contains('equipe_sem_veterinario'));
    }

    /** RF09b — quem administra mais de uma conta escolhe qual está vendo. */
    public function test_administradora_de_dois_prestadores_alterna_o_contexto_pela_query(): void
    {
        $vetAmigo = $this->clinica(['nome' => 'Clínica Vet Amigo']);
        $bichoBom = $this->clinica(['nome' => 'Hospital Bicho Bom', 'cnpj' => '11444777000161']);
        $administradora = $this->administradora($vetAmigo, $bichoBom);

        $this->actingAs($administradora)
            ->getJson('/api/prestador/painel')
            ->assertJsonPath('prestador.nome', 'Clínica Vet Amigo');

        $this->actingAs($administradora)
            ->getJson('/api/prestador/painel?prestador='.$bichoBom->id)
            ->assertJsonPath('prestador.nome', 'Hospital Bicho Bom')
            ->assertJsonCount(2, 'vinculos');
    }

    public function test_pedido_por_prestador_alheio_e_recusado(): void
    {
        $clinica = $this->clinica();
        $outra = $this->clinica(['nome' => 'Pet Center', 'cnpj' => '11444777000161']);

        $this->actingAs($this->administradora($clinica))
            ->getJson('/api/prestador/painel?prestador='.$outra->id)
            ->assertForbidden();
    }

    /** RF10a — encerrado o vínculo, nem o painel administrativo se abre. */
    public function test_vinculo_administrativo_encerrado_nao_abre_o_painel(): void
    {
        $clinica = $this->clinica();
        $usuario = User::factory()->create();
        $usuario->prestadores()->attach($clinica, [
            'papel' => 'admin_prestador',
            'encerrado_em' => Carbon::now()->subDay(),
        ]);

        $this->actingAs($usuario)->getJson('/api/prestador/painel')->assertForbidden();
    }
}
