<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Autorizacao;
use App\Models\Prestador;
use App\Models\Tutor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A memória do contexto ativo (RF09b): a escolha de prestador feita numa tela
 * vale para a seguinte e para a próxima sessão, nos dois ambientes. Sem ela,
 * cada navegação devolvia o profissional ao primeiro vínculo — e um animal
 * recém-autorizado à clínica ficava invisível para quem estava no contexto do
 * consultório.
 */
class ContextoAtivoTest extends TestCase
{
    use RefreshDatabase;

    private Prestador $consultorio;

    private Prestador $clinica;

    private User $veterinario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consultorio = Prestador::factory()->create(['nome' => 'Consultório do Lucas']);
        $this->clinica = Prestador::factory()->create(['nome' => 'Clínica Vida Animal']);

        $this->veterinario = User::factory()->create(['name' => 'Lucas Mendes']);

        foreach ([$this->consultorio, $this->clinica] as $prestador) {
            $this->veterinario->prestadores()->attach($prestador, [
                'papel' => 'veterinario',
                'crmv' => '28614',
                'crmv_uf' => 'SP',
            ]);
        }
    }

    public function test_sem_escolha_e_sem_memoria_o_contexto_e_o_primeiro_vinculo(): void
    {
        $this->actingAs($this->veterinario)
            ->getJson('/api/clinica/painel')
            ->assertOk()
            ->assertJsonPath('prestador.nome', 'Consultório do Lucas');
    }

    public function test_a_escolha_explicita_fica_lembrada_para_a_navegacao_seguinte(): void
    {
        $this->actingAs($this->veterinario)
            ->getJson("/api/clinica/painel?prestador={$this->clinica->id}")
            ->assertOk();

        // A tela seguinte não manda `?prestador=` — os destinos da barra
        // lateral nunca mandaram — e mesmo assim continua na clínica.
        $this->actingAs($this->veterinario)
            ->getJson('/api/clinica/animais')
            ->assertOk()
            ->assertJsonPath('prestador.nome', 'Clínica Vida Animal');
    }

    public function test_memoria_de_vinculo_encerrado_e_ignorada_sem_recusar_o_acesso(): void
    {
        $this->veterinario->lembrarContexto($this->clinica);

        $this->veterinario->prestadores()
            ->newPivotStatement()
            ->where('user_id', $this->veterinario->id)
            ->where('prestador_id', $this->clinica->id)
            ->update(['encerrado_em' => now()]);

        $this->actingAs($this->veterinario->fresh())
            ->getJson('/api/clinica/painel')
            ->assertOk()
            ->assertJsonPath('prestador.nome', 'Consultório do Lucas');
    }

    public function test_a_administracao_abre_no_prestador_em_que_o_profissional_estava(): void
    {
        $this->veterinario->prestadores()->attach($this->clinica, ['papel' => 'admin_prestador']);
        $this->veterinario->prestadores()->attach($this->consultorio, ['papel' => 'admin_prestador']);

        $this->actingAs($this->veterinario)
            ->getJson("/api/clinica/painel?prestador={$this->clinica->id}")
            ->assertOk();

        $this->actingAs(User::find($this->veterinario->id))
            ->getJson('/api/prestador/painel')
            ->assertOk()
            ->assertJsonPath('prestador.nome', 'Clínica Vida Animal');
    }

    /**
     * O caso de quem atende numa clínica que não administra e mantém a própria
     * conta: as telas do prestador falam do estabelecimento em que ela está —
     * a clínica —, sem lhe dar poder algum sobre ele, e a passagem por ali não
     * desloca o contexto clínico.
     */
    public function test_as_telas_do_prestador_falam_do_contexto_em_que_a_pessoa_esta(): void
    {
        $this->veterinario->prestadores()->attach($this->consultorio, ['papel' => 'admin_prestador']);

        $this->actingAs($this->veterinario)
            ->getJson("/api/clinica/painel?prestador={$this->clinica->id}")
            ->assertOk();

        $this->actingAs(User::find($this->veterinario->id))
            ->getJson('/api/prestador/painel')
            ->assertOk()
            ->assertJsonPath('prestador.nome', 'Clínica Vida Animal')
            ->assertJsonPath('pode_administrar', false)
            // Não há divergência a explicar: a tela abriu onde a pessoa estava.
            ->assertJsonPath('contexto_clinico', null);

        $this->actingAs(User::find($this->veterinario->id))
            ->getJson('/api/clinica/painel')
            ->assertOk()
            ->assertJsonPath('prestador.nome', 'Clínica Vida Animal');
    }

    /**
     * E o consultório continua a um passo: escolhê-lo explicitamente abre a
     * conta que ela administra, com o poder de mudar que a clínica não lhe dá.
     */
    public function test_a_escolha_explicita_abre_a_conta_que_a_pessoa_administra(): void
    {
        $this->veterinario->prestadores()->attach($this->consultorio, ['papel' => 'admin_prestador']);

        $this->actingAs($this->veterinario)
            ->getJson("/api/clinica/painel?prestador={$this->clinica->id}")
            ->assertOk();

        $this->actingAs(User::find($this->veterinario->id))
            ->getJson("/api/prestador/painel?prestador={$this->consultorio->id}")
            ->assertOk()
            ->assertJsonPath('prestador.nome', 'Consultório do Lucas')
            ->assertJsonPath('pode_administrar', true);
    }

    public function test_sem_divergencia_entre_o_que_se_atende_e_o_que_se_administra_nao_ha_o_que_explicar(): void
    {
        $this->veterinario->prestadores()->attach($this->clinica, ['papel' => 'admin_prestador']);

        $this->actingAs($this->veterinario)
            ->getJson("/api/clinica/painel?prestador={$this->clinica->id}")
            ->assertOk();

        $this->actingAs(User::find($this->veterinario->id))
            ->getJson('/api/prestador/painel')
            ->assertOk()
            ->assertJsonPath('prestador.nome', 'Clínica Vida Animal')
            ->assertJsonPath('contexto_clinico', null);
    }

    public function test_cada_vinculo_informa_animais_sob_autorizacao_vigente_e_o_papel_de_administrador(): void
    {
        $this->veterinario->prestadores()->attach($this->consultorio, ['papel' => 'admin_prestador']);

        $tutor = Tutor::factory()->create();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id]);

        // Renovada antes do prazo: duas autorizações vigentes, um animal só.
        Autorizacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $this->clinica->id,
            'concedida_em' => now()->subDays(80),
            'expira_em' => now()->addDays(10),
        ]);
        Autorizacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $this->clinica->id,
            'concedida_em' => now(),
            'expira_em' => now()->addDays(90),
        ]);

        $this->actingAs($this->veterinario)
            ->getJson('/api/clinica/painel')
            ->assertOk()
            ->assertJsonPath('vinculos.0.nome', 'Consultório do Lucas')
            ->assertJsonPath('vinculos.0.animais', 0)
            ->assertJsonPath('vinculos.0.admin', true)
            ->assertJsonPath('vinculos.1.nome', 'Clínica Vida Animal')
            ->assertJsonPath('vinculos.1.animais', 1)
            ->assertJsonPath('vinculos.1.admin', false);
    }
}
