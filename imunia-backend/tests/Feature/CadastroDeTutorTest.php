<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Convite;
use App\Models\Prestador;
use App\Models\RegistroDeAcesso;
use App\Models\Tutor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * V04 — cadastrar tutor no atendimento (RF12, RF13, RF14).
 *
 * Nome e e-mail bastam: o CPF não é pedido. O que mais importa aqui é o que o
 * cadastro **não** faz: não cria segundo registro para e-mail de tutor que já
 * existe (RF12b) — devolve o cadastro encontrado para
 * o atendimento seguir — e não deixa o encontro de tutor fora da carteira sem
 * linha no livro de acessos (RF18b). O caminho feliz é o mesmo desenho de A03:
 * conta de senha inacessível; o convite de ativação sai com o primeiro animal.
 */
class CadastroDeTutorTest extends TestCase
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

    private function helenaJaCadastrada(): Tutor
    {
        $conta = User::factory()->create(['email' => 'helena@example.com']);

        return Tutor::factory()->for($conta)->create(['nome' => 'Helena Ramos']);
    }

    /**
     * @param  array<string, mixed>  $sobrescritos
     * @return array<string, mixed>
     */
    private function dados(array $sobrescritos = []): array
    {
        return [
            'nome' => 'Helena Ramos',
            'email' => 'helena@example.com',
            ...$sobrescritos,
        ];
    }

    public function test_veterinario_cadastra_tutor_sem_enviar_convite_ainda(): void
    {
        Notification::fake();

        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);

        $response = $this->actingAs($marcelo)->postJson('/api/clinica/tutores', $this->dados());

        $response->assertStatus(201)
            ->assertJsonPath('tutor.nome', 'Helena Ramos')
            ->assertJsonPath('tutor.email', 'helena@example.com');

        // Sem CPF: o cadastro existe só com nome e e-mail.
        $tutor = Tutor::query()->doEmail('helena@example.com')->first();
        $this->assertNotNull($tutor);
        $this->assertSame('Helena Ramos', $tutor->nome);
        $this->assertNull($tutor->cpf);

        // RF14 — a conta nasce por ativar: sem termos aceitos, sem endereço
        // verificado, sem primeira entrada. Quem completa tudo isso é o
        // titular, no aceite do convite.
        $this->assertNull($tutor->termos_aceitos_em);
        $this->assertNull($tutor->user->ativado_em);
        $this->assertNull($tutor->user->email_verified_at);

        // O convite sai com o primeiro animal, não aqui: um e-mail que diz
        // "seu pet foi cadastrado" antes de existir pet não teria o que dizer.
        $this->assertSame(0, Convite::query()->count());
        Notification::assertNothingSent();
    }

    public function test_o_cpf_nao_e_pedido(): void
    {
        $clinica = $this->clinica();

        // Mesmo um CPF inválido enviado por engano não importa: o campo não
        // faz parte do cadastro feito no balcão.
        $this->actingAs($this->marcelo($clinica))
            ->postJson('/api/clinica/tutores', $this->dados(['cpf' => '417.882.310-05']))
            ->assertStatus(201);

        $this->assertNull(Tutor::query()->sole()->cpf);
    }

    public function test_dois_tutores_sem_cpf_convivem(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);

        $this->actingAs($marcelo)->postJson('/api/clinica/tutores', $this->dados())->assertStatus(201);
        $this->actingAs($marcelo)
            ->postJson('/api/clinica/tutores', $this->dados(['nome' => 'Antônio Prado', 'email' => 'antonio@example.com']))
            ->assertStatus(201);

        $this->assertSame(2, Tutor::query()->whereNull('cpf')->count());
    }

    public function test_email_de_tutor_existente_nao_cria_segundo_registro_e_devolve_o_cadastro(): void
    {
        Notification::fake();

        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);

        $conta = User::factory()->create(['email' => 'helena@example.com']);
        $helena = Tutor::factory()->for($conta)->create(['nome' => 'Helena Ramos']);

        $response = $this->actingAs($marcelo)->postJson(
            '/api/clinica/tutores',
            // Maiúsculas e espaços não fazem outro endereço.
            $this->dados(['nome' => 'Helena R.', 'email' => ' Helena@Example.com ']),
        );

        // O atendimento não para: a tela recebe o cadastro que já existe e
        // segue para o animal, com o nome que está no registro — não o que foi
        // digitado agora.
        $response->assertOk()
            ->assertJsonPath('situacao', 'tutor_existente')
            ->assertJsonPath('tutor.id', $helena->id)
            ->assertJsonPath('tutor.nome', 'Helena Ramos')
            ->assertJsonPath('tutor.email', 'helena@example.com');

        // RF12b — jamais um segundo registro, nem convite, nem conta nova.
        $this->assertSame(1, Tutor::query()->count());
        $this->assertSame(0, Convite::query()->count());
        $this->assertSame(1, User::query()->where('email', 'helena@example.com')->count());

        Notification::assertNothingSent();
    }

    public function test_o_encontro_de_tutor_fora_da_carteira_fica_registrado(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);

        $helena = $this->helenaJaCadastrada();

        $this->actingAs($marcelo)->postJson('/api/clinica/tutores', $this->dados())
            ->assertOk();

        // RF18b — mesmo fora da busca de V03, chegar pelo e-mail a um tutor
        // que a clínica não acompanha é encontro, e o titular o vê em T14.
        $this->assertDatabaseHas('registros_de_acesso', [
            'prestador_id' => $clinica->id,
            'user_id' => $marcelo->id,
            'tutor_id' => $helena->id,
            'animal_id' => null,
            'natureza' => RegistroDeAcesso::BUSCA_POR_EMAIL,
        ]);
    }

    public function test_tutor_com_animal_ja_acompanhado_nao_gera_linha_no_livro(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);

        $helena = $this->helenaJaCadastrada();
        $theo = Animal::factory()->acompanhadoPor($clinica)->create(['tutor_id' => $helena->id]);

        $this->actingAs($marcelo)->postJson('/api/clinica/tutores', $this->dados())
            ->assertOk()
            ->assertJsonPath('animais.0.codigo', $theo->codigo);

        // A clínica já acompanha um animal deste tutor: reencontrá-lo não
        // revelou nada, e linha aqui só encheria T14 de ruído.
        $this->assertSame(0, RegistroDeAcesso::query()->count());
    }

    public function test_email_de_conta_sem_papel_de_tutor_ganha_o_papel_na_mesma_conta(): void
    {
        // RN05 — o veterinário que também tem animais: uma conta, dois papéis.
        // Sem o autocadastro, é por aqui que ele vira tutor.
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $larissa = $this->marcelo($this->clinica('Hospital Veterinário Central'));
        $larissa->forceFill(['email' => 'larissa@example.com', 'ativado_em' => now()])->save();

        $this->actingAs($marcelo)
            ->postJson('/api/clinica/tutores', $this->dados([
                'nome' => 'Larissa Prado',
                'email' => 'larissa@example.com',
            ]))
            ->assertStatus(201)
            ->assertJsonPath('tutor.nome', 'Larissa Prado');

        $this->assertSame(1, User::query()->where('email', 'larissa@example.com')->count());

        $tutor = Tutor::query()->sole();
        $this->assertSame($larissa->id, $tutor->user_id);

        // A conta continua a mesma — senha, ativação e o papel de veterinário.
        $this->assertNotNull($larissa->refresh()->ativado_em);
        $this->assertContains('tutor', $larissa->papeis());
        $this->assertContains('veterinario', $larissa->papeis());
    }

    public function test_email_e_obrigatorio(): void
    {
        $clinica = $this->clinica();

        $this->actingAs($this->marcelo($clinica))
            ->postJson('/api/clinica/tutores', $this->dados(['email' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->assertSame(0, Tutor::query()->count());
    }

    public function test_quem_nao_tem_vinculo_de_veterinario_nao_cadastra(): void
    {
        $this->clinica();
        $semVinculo = User::factory()->create();

        $this->actingAs($semVinculo)->postJson('/api/clinica/tutores', $this->dados())
            ->assertStatus(403);

        $this->assertSame(0, Tutor::query()->count());
    }

    public function test_prestador_sem_vinculo_do_profissional_e_recusado(): void
    {
        $minha = $this->clinica();
        $alheia = $this->clinica('Hospital Veterinário Central');
        $marcelo = $this->marcelo($minha);

        $this->actingAs($marcelo)->postJson(
            "/api/clinica/tutores?prestador={$alheia->id}",
            $this->dados(),
        )->assertStatus(403);

        $this->assertSame(0, Tutor::query()->count());
    }

    public function test_sem_sessao_nao_ha_cadastro(): void
    {
        $this->postJson('/api/clinica/tutores', $this->dados())->assertStatus(401);
    }
}
