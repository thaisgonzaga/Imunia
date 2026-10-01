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
 * O que mais importa aqui é o que o cadastro **não** faz: não cria segundo
 * registro para CPF que já existe (RF12b) — devolve o cadastro encontrado para
 * o atendimento seguir — e não deixa o encontro de tutor fora da carteira sem
 * linha no livro de acessos (RF18b). O caminho feliz é o mesmo desenho de A03:
 * conta de senha inacessível; o convite de ativação sai com o primeiro animal.
 */
class CadastroDeTutorTest extends TestCase
{
    use RefreshDatabase;

    /** CPF com dígitos verificadores corretos — o mesmo de BuscaClinicaTest. */
    private const CPF_VALIDO = '23847190504';

    /** Outro CPF válido, para o cenário em que só o e-mail colide. */
    private const OUTRO_CPF_VALIDO = '52998224725';

    /** O exemplo de dígito verificador incorreto que a própria tela exibe. */
    private const CPF_INVALIDO = '41788231005';

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
     * @param  array<string, mixed>  $sobrescritos
     * @return array<string, mixed>
     */
    private function dados(array $sobrescritos = []): array
    {
        return [
            'nome' => 'Helena Ramos',
            'cpf' => '238.471.905-04',
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
            ->assertJsonPath('tutor.cpf', self::CPF_VALIDO)
            ->assertJsonPath('tutor.email', 'helena@example.com');

        // O CPF entra como dígitos puros, com a máscara digitada e tudo.
        $tutor = Tutor::query()->where('cpf', self::CPF_VALIDO)->first();
        $this->assertNotNull($tutor);
        $this->assertSame('Helena Ramos', $tutor->nome);

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

    public function test_cpf_existente_nao_cria_segundo_registro_e_devolve_o_cadastro(): void
    {
        Notification::fake();

        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);

        $helena = Tutor::factory()->create(['nome' => 'Helena Ramos', 'cpf' => self::CPF_VALIDO]);

        $response = $this->actingAs($marcelo)->postJson(
            '/api/clinica/tutores',
            $this->dados(['nome' => 'Helena R.', 'email' => 'outra@example.com']),
        );

        // O atendimento não para: a tela recebe o cadastro que já existe e
        // segue para o animal, com o nome que está no registro — não o que foi
        // digitado agora.
        $response->assertOk()
            ->assertJsonPath('situacao', 'cpf_existente')
            ->assertJsonPath('tutor.id', $helena->id)
            ->assertJsonPath('tutor.nome', 'Helena Ramos')
            ->assertJsonPath('tutor.cpf', self::CPF_VALIDO);

        // RF12b — jamais um segundo registro, nem convite, nem conta nova.
        $this->assertSame(1, Tutor::query()->count());
        $this->assertSame(0, Convite::query()->count());
        $this->assertNull(User::query()->where('email', 'outra@example.com')->first());

        Notification::assertNothingSent();
    }

    public function test_o_encontro_de_tutor_fora_da_carteira_fica_registrado(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);

        $helena = Tutor::factory()->create(['cpf' => self::CPF_VALIDO]);

        $this->actingAs($marcelo)->postJson('/api/clinica/tutores', $this->dados())
            ->assertOk();

        // RF18b — mesmo fora da busca de V03, chegar pelo CPF a um tutor que a
        // clínica não acompanha é encontro, e o titular o vê em T14.
        $this->assertDatabaseHas('registros_de_acesso', [
            'prestador_id' => $clinica->id,
            'user_id' => $marcelo->id,
            'tutor_id' => $helena->id,
            'animal_id' => null,
            'natureza' => RegistroDeAcesso::BUSCA_POR_CPF,
        ]);
    }

    public function test_tutor_com_animal_ja_acompanhado_nao_gera_linha_no_livro(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);

        $helena = Tutor::factory()->create(['cpf' => self::CPF_VALIDO]);
        Animal::factory()->acompanhadoPor($clinica)->create(['tutor_id' => $helena->id]);

        $this->actingAs($marcelo)->postJson('/api/clinica/tutores', $this->dados())
            ->assertOk();

        // A clínica já acompanha um animal deste tutor: reencontrá-lo não
        // revelou nada, e linha aqui só encheria T14 de ruído.
        $this->assertSame(0, RegistroDeAcesso::query()->count());
    }

    public function test_email_em_uso_recusa_sem_criar_nada(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);

        User::factory()->create(['email' => 'helena@example.com']);

        $response = $this->actingAs($marcelo)->postJson(
            '/api/clinica/tutores',
            $this->dados(['cpf' => self::OUTRO_CPF_VALIDO]),
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);

        $this->assertSame(0, Tutor::query()->count());
        $this->assertSame(0, Convite::query()->count());
    }

    public function test_cpf_com_digito_verificador_incorreto_e_recusado(): void
    {
        $clinica = $this->clinica();

        $response = $this->actingAs($this->marcelo($clinica))->postJson(
            '/api/clinica/tutores',
            $this->dados(['cpf' => self::CPF_INVALIDO]),
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['cpf']);
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
