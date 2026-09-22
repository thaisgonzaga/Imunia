<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Autorizacao;
use App\Models\Imunobiologico;
use App\Models\Prestador;
use App\Models\ProtocoloVacinal;
use App\Models\Tutor;
use App\Models\User;
use App\Models\Vacinacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * V12 — registrar óbito (RF22).
 *
 * Três garantias são o assunto da suíte:
 *
 * 1. **Quem escreve é quem tem inscrição** (RN21), no prestador que escolheu,
 *    sobre animal cujo tutor autorizou (RN37) — a mesma porta de V07 e V08.
 * 2. **Registrado o óbito, cessa a previsão** (RF22a): nenhuma dose prevista
 *    na ficha, nenhuma linha na rechamada de V02 — e o histórico permanece
 *    inteiro (RF22b), agora com a entrada do óbito (RF35).
 * 3. **O registro fica** (RF22c): a segunda tentativa responde o que já
 *    consta, com data e autor, e não sobrescreve a autoria de ninguém.
 */
class RegistroDeObitoTest extends TestCase
{
    use RefreshDatabase;

    private function clinica(string $nome = 'Clínica Vet Amigo'): Prestador
    {
        return Prestador::factory()->create(['nome' => $nome]);
    }

    private function marcelo(Prestador ...$prestadores): User
    {
        $usuario = User::factory()->create(['name' => 'Marcelo Andrade']);

        foreach ($prestadores as $prestador) {
            $usuario->prestadores()->attach($prestador, [
                'papel' => 'veterinario',
                'crmv' => '12345',
                'crmv_uf' => 'MG',
            ]);
        }

        return $usuario;
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function animalDe(string $nomeDoTutor, string $nome, array $atributos = []): Animal
    {
        $tutor = Tutor::factory()->create(['nome' => $nomeDoTutor]);

        return Animal::factory()->create([
            ...$atributos,
            'tutor_id' => $tutor->id,
            'nome' => $nome,
        ]);
    }

    private function autorizar(Animal $animal, Prestador $prestador, string $estado = 'vigente'): Autorizacao
    {
        $factory = Autorizacao::factory();

        if ($estado !== 'vigente') {
            $factory = $factory->{$estado}();
        }

        return $factory->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'concedida_por_user_id' => $animal->tutor->user_id,
        ]);
    }

    /* Âmbito ---------------------------------------------------------------- */

    public function test_o_registro_exige_sessao(): void
    {
        $animal = $this->animalDe('Helena Ramos', 'Théo');

        $this->postJson("/api/clinica/animais/{$animal->codigo}/obito", [])->assertUnauthorized();
    }

    public function test_quem_nao_tem_vinculo_de_veterinario_nao_registra(): void
    {
        $animal = $this->animalDe('Helena Ramos', 'Théo');

        $this->actingAs(User::factory()->create())
            ->postJson("/api/clinica/animais/{$animal->codigo}/obito", ['em' => today()->toDateString()])
            ->assertForbidden();
    }

    /** RN08 — o papel administrativo não alcança o dado clínico. */
    public function test_administrador_do_prestador_nao_registra(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);

        $usuario = User::factory()->create();
        $usuario->prestadores()->attach($prestador, ['papel' => 'admin_prestador']);

        $this->actingAs($usuario)
            ->postJson("/api/clinica/animais/{$animal->codigo}/obito", ['em' => today()->toDateString()])
            ->assertForbidden();
    }

    /** RN21 — vínculo, prestador e autorização em ordem; falta a inscrição. */
    public function test_veterinario_sem_crmv_no_vinculo_nao_registra(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);

        $usuario = User::factory()->create();
        $usuario->prestadores()->attach($prestador, [
            'papel' => 'veterinario',
            'crmv' => null,
            'crmv_uf' => null,
        ]);

        $this->actingAs($usuario)
            ->postJson("/api/clinica/animais/{$animal->codigo}/obito", ['em' => today()->toDateString()])
            ->assertForbidden();

        $this->assertNull($animal->fresh()->obito_em);
    }

    /**
     * RN37 — sem autorização vigente não há registro. O óbito não tem a
     * exceção do atendimento de V08: ele muda o estado do animal para o tutor
     * e para todo prestador, não o prontuário de uma clínica.
     */
    public function test_sem_autorizacao_vigente_o_registro_e_recusado(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador, 'revogada');

        $this->actingAs($this->marcelo($prestador))
            ->postJson("/api/clinica/animais/{$animal->codigo}/obito", ['em' => today()->toDateString()])
            ->assertForbidden();

        $this->assertNull($animal->fresh()->obito_em);
    }

    public function test_animal_inexistente_responde_404(): void
    {
        $prestador = $this->clinica();

        $this->actingAs($this->marcelo($prestador))
            ->postJson('/api/clinica/animais/ZZZZ-9999/obito', ['em' => today()->toDateString()])
            ->assertNotFound();
    }

    /* Registro --------------------------------------------------------------- */

    public function test_o_veterinario_registra_o_obito_com_data_e_causa(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $marcelo = $this->marcelo($prestador);

        $resposta = $this->actingAs($marcelo)
            ->postJson("/api/clinica/animais/{$animal->codigo}/obito", [
                'em' => today()->subDay()->toDateString(),
                'causa' => 'Insuficiência renal crônica',
            ]);

        $resposta->assertCreated()
            ->assertJsonPath('em', today()->subDay()->toDateString())
            ->assertJsonPath('causa', 'Insuficiência renal crônica')
            ->assertJsonPath('registrado_por', 'Marcelo Andrade')
            ->assertJsonPath('destino', "/clinica/animais/{$animal->codigo}?prestador={$prestador->id}");

        $animal->refresh();

        $this->assertSame(today()->subDay()->toDateString(), $animal->obito_em->toDateString());
        $this->assertSame('Insuficiência renal crônica', $animal->obito_causa);

        // RF22c e RN27 — a autoria é do registro: quem, por qual prestador e
        // com qual inscrição, copiada do vínculo no ato.
        $this->assertSame($marcelo->id, $animal->obito_registrado_por_user_id);
        $this->assertSame($prestador->id, $animal->obito_prestador_id);
        $this->assertSame('CRMV-MG 12345', $animal->obito_registrado_crmv);
    }

    public function test_a_causa_e_opcional(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);

        $this->actingAs($this->marcelo($prestador))
            ->postJson("/api/clinica/animais/{$animal->codigo}/obito", ['em' => today()->toDateString()])
            ->assertCreated()
            ->assertJsonPath('causa', null);

        $this->assertNull($animal->fresh()->obito_causa);
    }

    public function test_data_no_futuro_e_recusada(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);

        $this->actingAs($this->marcelo($prestador))
            ->postJson("/api/clinica/animais/{$animal->codigo}/obito", [
                'em' => today()->addDay()->toDateString(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.em.0', 'A data do óbito não pode estar no futuro.');

        $this->assertNull($animal->fresh()->obito_em);
    }

    public function test_data_anterior_ao_nascimento_e_recusada(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo', [
            'nascimento_em' => today()->subYears(2)->toDateString(),
        ]);
        $this->autorizar($animal, $prestador);

        $this->actingAs($this->marcelo($prestador))
            ->postJson("/api/clinica/animais/{$animal->codigo}/obito", [
                'em' => today()->subYears(3)->toDateString(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.em.0', 'A data do óbito não pode ser anterior ao nascimento do animal.');
    }

    /**
     * RF22c — o registro não pode ser excluído nem sobrescrito. A segunda
     * tentativa é estado, não erro: responde 409 com o que já consta, e a
     * autoria original não muda.
     */
    public function test_obito_ja_registrado_responde_o_que_consta_e_nao_sobrescreve(): void
    {
        $clinicaDoRegistro = $this->clinica();
        $outraClinica = $this->clinica('Hospital Vida Animal');
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $clinicaDoRegistro);
        $this->autorizar($animal, $outraClinica);

        $marcelo = $this->marcelo($clinicaDoRegistro);

        $this->actingAs($marcelo)
            ->postJson("/api/clinica/animais/{$animal->codigo}/obito", [
                'em' => today()->subDays(3)->toDateString(),
            ])
            ->assertCreated();

        $segunda = User::factory()->create(['name' => 'Beatriz Nunes']);
        $segunda->prestadores()->attach($outraClinica, [
            'papel' => 'veterinario',
            'crmv' => '67890',
            'crmv_uf' => 'SP',
        ]);

        $this->actingAs($segunda)
            ->postJson("/api/clinica/animais/{$animal->codigo}/obito?prestador={$outraClinica->id}", [
                'em' => today()->toDateString(),
            ])
            ->assertConflict()
            ->assertJsonPath('situacao', 'ja_registrado')
            ->assertJsonPath('em', today()->subDays(3)->toDateString())
            ->assertJsonPath('registrado_por', 'Marcelo Andrade');

        $animal->refresh();

        $this->assertSame(today()->subDays(3)->toDateString(), $animal->obito_em->toDateString());
        $this->assertSame($marcelo->id, $animal->obito_registrado_por_user_id);
        $this->assertSame($clinicaDoRegistro->id, $animal->obito_prestador_id);
    }

    /* Efeitos (RF22a, RF22b) ------------------------------------------------- */

    /**
     * RF22a — registrado o óbito, cessa o cálculo do calendário: a ficha não
     * prevê dose nenhuma, e o grupo anuncia o encerramento em tom neutro. A
     * aplicação passada continua na carteira (RF22b): histórico não é previsão.
     */
    public function test_registrado_o_obito_a_ficha_nao_preve_dose(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $marcelo = $this->marcelo($prestador);

        $antirrabica = Imunobiologico::factory()->antirrabica()->create();
        ProtocoloVacinal::factory()->antirrabica()->create(['imunobiologico_id' => $antirrabica->id]);

        Vacinacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'imunobiologico_id' => $antirrabica->id,
            'protocolo_vacinal_id' => $antirrabica->protocoloVigente()->id,
            'aplicado_em' => today()->subMonths(14)->toDateString(),
        ]);

        $antes = $this->actingAs($marcelo)->getJson("/api/clinica/animais/{$animal->codigo}");
        $this->assertNotEmpty($antes->json('carteira.proximas_doses'));

        $this->actingAs($marcelo)
            ->postJson("/api/clinica/animais/{$animal->codigo}/obito", ['em' => today()->toDateString()])
            ->assertCreated();

        $depois = $this->actingAs($marcelo)->getJson("/api/clinica/animais/{$animal->codigo}");

        $depois->assertOk()
            ->assertJsonPath('carteira.proximas_doses', [])
            ->assertJsonPath('carteira.grupos.0.situacao.tipo', 'encerrada')
            ->assertJsonPath('resumo.proximas_doses', [])
            ->assertJsonPath('animal.obito.em', today()->toDateString());

        // A aplicação passada não desaparece com o calendário.
        $this->assertCount(1, $depois->json('carteira.grupos.0.aplicacoes'));
    }

    /** RF22a — nenhuma linha na rechamada de V02 para quem morreu. */
    public function test_registrado_o_obito_o_animal_sai_das_pendencias(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $marcelo = $this->marcelo($prestador);

        $antirrabica = Imunobiologico::factory()->antirrabica()->create();
        ProtocoloVacinal::factory()->antirrabica()->create(['imunobiologico_id' => $antirrabica->id]);

        Vacinacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'imunobiologico_id' => $antirrabica->id,
            'protocolo_vacinal_id' => $antirrabica->protocoloVigente()->id,
            'aplicado_em' => today()->subMonths(14)->toDateString(),
        ]);

        $antes = $this->actingAs($marcelo)->getJson('/api/clinica/pendencias');
        $this->assertNotEmpty($antes->json('itens'));

        $this->actingAs($marcelo)
            ->postJson("/api/clinica/animais/{$animal->codigo}/obito", ['em' => today()->toDateString()])
            ->assertCreated();

        $this->actingAs($marcelo)
            ->getJson('/api/clinica/pendencias')
            ->assertOk()
            ->assertJsonPath('itens', []);
    }

    /** RF22b e RF35 — o histórico permanece, agora com a entrada do óbito. */
    public function test_o_obito_entra_na_linha_do_tempo_com_procedencia(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $marcelo = $this->marcelo($prestador);

        $this->actingAs($marcelo)
            ->postJson("/api/clinica/animais/{$animal->codigo}/obito", [
                'em' => today()->subDay()->toDateString(),
                'causa' => 'Insuficiência renal crônica',
            ])
            ->assertCreated();

        $resposta = $this->actingAs($marcelo)
            ->getJson("/api/clinica/animais/{$animal->codigo}")
            ->assertOk();

        $entrada = collect($resposta->json('historico.entradas'))
            ->firstWhere('tipo', 'obito');

        $this->assertNotNull($entrada);
        $this->assertSame('Óbito', $entrada['titulo']);
        $this->assertSame(today()->subDay()->toDateString(), $entrada['data']);
        $this->assertStringContainsString('Insuficiência renal crônica', $entrada['resumo']);
        $this->assertStringContainsString('histórico permanece consultável', $entrada['resumo']);

        // P1 — procedência: quem registrou, com inscrição e âmbito.
        $this->assertSame('Marcelo Andrade', $entrada['aplicador']['nome']);
        $this->assertSame('CRMV-MG 12345', $entrada['aplicador']['crmv']);
        $this->assertSame('Clínica Vet Amigo', $entrada['aplicador']['prestador']);

        // O filtro por tipo passa a oferecer o óbito (RF35).
        $this->assertContains('obito', collect($resposta->json('historico.filtros.tipos'))->pluck('chave')->all());
    }
}
