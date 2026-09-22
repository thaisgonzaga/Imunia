<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Imunobiologico;
use App\Models\Prestador;
use App\Models\ProtocoloVacinal;
use App\Models\Tutor;
use App\Models\User;
use App\Models\Vacinacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PainelTutorTest extends TestCase
{
    use RefreshDatabase;

    private function helena(array $atributosDoUsuario = []): Tutor
    {
        $usuario = User::factory()->create(array_merge([
            'name' => 'Helena Ramos',
            'email' => 'helena.ramos@example.com',
        ], $atributosDoUsuario));

        return Tutor::factory()->create([
            'user_id' => $usuario->id,
            'nome' => 'Helena Ramos',
        ]);
    }

    public function test_o_painel_exige_sessao(): void
    {
        $this->getJson('/api/tutor/painel')->assertUnauthorized();
    }

    public function test_usuario_sem_cadastro_de_tutor_nao_alcanca_o_painel(): void
    {
        // O veterinário e o administrador do prestador têm painéis próprios: o
        // ambiente do tutor não é a porta de entrada de quem não é tutor.
        $usuario = User::factory()->create();

        $this->actingAs($usuario)
            ->getJson('/api/tutor/painel')
            ->assertForbidden();
    }

    public function test_o_painel_traz_os_animais_do_tutor_em_ordem_de_nome(): void
    {
        $tutor = $this->helena();

        Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Théo']);
        Animal::factory()->gato()->create(['tutor_id' => $tutor->id, 'nome' => 'Nina']);

        $resposta = $this->actingAs($tutor->user)->getJson('/api/tutor/painel');

        $resposta->assertOk();
        $resposta->assertJsonPath('tutor.nome', 'Helena Ramos');
        $resposta->assertJsonCount(2, 'animais');
        $resposta->assertJsonPath('animais.0.nome', 'Nina');
        $resposta->assertJsonPath('animais.1.nome', 'Théo');
    }

    public function test_o_painel_nao_enxerga_animal_de_outro_tutor(): void
    {
        $helena = $this->helena();
        $outro = Tutor::factory()->create(['nome' => 'Marcos Lima']);

        Animal::factory()->create(['tutor_id' => $helena->id, 'nome' => 'Théo']);
        Animal::factory()->create(['tutor_id' => $outro->id, 'nome' => 'Bidu']);

        $resposta = $this->actingAs($helena->user)->getJson('/api/tutor/painel');

        $resposta->assertJsonCount(1, 'animais');
        $resposta->assertJsonPath('animais.0.nome', 'Théo');
    }

    public function test_tutor_sem_animais_recebe_painel_vazio(): void
    {
        $tutor = $this->helena();

        $resposta = $this->actingAs($tutor->user)->getJson('/api/tutor/painel');

        $resposta->assertOk();
        $resposta->assertJsonPath('animais', []);
        $resposta->assertJsonPath('pendencias', []);
        $resposta->assertJsonPath('retornos', []);
    }

    /**
     * RF50 pede a situação das doses. Sem vacinação registrada, a resposta
     * honesta é "não há registro", e não "em dia": a tela precisa do primeiro
     * valor para não afirmar ao tutor coisa que o sistema não sabe.
     */
    public function test_sem_vacinacao_registrada_a_situacao_geral_nao_afirma_que_esta_em_dia(): void
    {
        $tutor = $this->helena();
        Animal::factory()->create(['tutor_id' => $tutor->id]);

        $this->actingAs($tutor->user)
            ->getJson('/api/tutor/painel')
            ->assertJsonPath('situacao_geral', 'sem_registros');
    }

    /**
     * RF25, RF26 — a fatia da carteira (T05) alimenta o painel, exatamente no
     * formato que o controlador já documentava antes de existir: uma dose
     * atrasada de um animal vira pendência, ordenada pela data mais urgente.
     */
    public function test_dose_atrasada_aparece_como_pendencia_no_painel(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Théo']);
        $prestador = Prestador::factory()->create();

        $imunobiologico = Imunobiologico::factory()->antirrabica()->create();
        $protocolo = ProtocoloVacinal::factory()->antirrabica()->create([
            'imunobiologico_id' => $imunobiologico->id,
        ]);

        Vacinacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'imunobiologico_id' => $imunobiologico->id,
            'protocolo_vacinal_id' => $protocolo->id,
            'aplicado_em' => now()->subMonths(14),
            'ordem_dose' => 1,
        ]);

        $resposta = $this->actingAs($tutor->user)->getJson('/api/tutor/painel');

        $resposta->assertOk();
        $resposta->assertJsonCount(1, 'pendencias');
        $resposta->assertJsonPath('pendencias.0.animal.codigo', $animal->codigo);
        $resposta->assertJsonPath('pendencias.0.situacao.tipo', 'atrasada');
    }

    public function test_o_painel_sinaliza_email_ainda_nao_confirmado(): void
    {
        // RF05 — é a tarja de reenvio que a tela desenha a partir deste campo.
        $tutor = $this->helena(['email_verified_at' => null]);

        $this->actingAs($tutor->user)
            ->getJson('/api/tutor/painel')
            ->assertJsonPath('tutor.email_verificado', false);
    }

    public function test_cada_animal_informa_se_o_cadastro_ainda_e_preliminar(): void
    {
        $tutor = $this->helena();

        Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Nina']);
        Animal::factory()->caracterizado()->create(['tutor_id' => $tutor->id, 'nome' => 'Théo']);

        $resposta = $this->actingAs($tutor->user)->getJson('/api/tutor/painel');

        $resposta->assertJsonPath('animais.0.preliminar', true);  // Nina — RN17
        $resposta->assertJsonPath('animais.1.preliminar', false); // Théo — RF19
    }

    /**
     * RN14 — a idade é cálculo derivado da data de nascimento, e a natureza da
     * data viaja com ela até a tela.
     */
    public function test_a_idade_vem_acompanhada_da_natureza_da_data_de_nascimento(): void
    {
        $tutor = $this->helena();

        Animal::factory()->create([
            'tutor_id' => $tutor->id,
            'nome' => 'Théo',
            // Sem transbordo: num dia 31, `subMonths(18)` cai num 31/02 que não
            // existe e escorrega para março — e a idade correta passa a ser 17.
            'nascimento_em' => now()->subMonthsNoOverflow(18)->toDateString(),
            'nascimento_exato' => false,
        ]);
        Animal::factory()->gato()->create(['tutor_id' => $tutor->id, 'nome' => 'Nina']);

        $resposta = $this->actingAs($tutor->user)->getJson('/api/tutor/painel');

        $resposta->assertJsonPath('animais.1.idade_em_meses', 18);
        $resposta->assertJsonPath('animais.1.nascimento_exato', false);

        // Nina foi adotada adulta: sem data, não se inventa idade.
        $resposta->assertJsonPath('animais.0.idade_em_meses', null);
    }
}
