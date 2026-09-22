<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Autorizacao;
use App\Models\Convite;
use App\Models\Prestador;
use App\Models\RegistroDeAcesso;
use App\Models\Tutor;
use App\Models\User;
use App\Notifications\ConviteDeAtivacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * V04 — cadastrar tutor no atendimento (RF12, RF13, RF14).
 *
 * O que mais importa aqui é o que o cadastro **não** faz: não cria segundo
 * registro para CPF que já existe (RF12b), não devolve nome nem dado algum do
 * cadastro encontrado (RN12), e não deixa a revelação de existência sem linha
 * no livro de acessos (RF18b). O caminho feliz é o mesmo desenho de A03: conta
 * de senha inacessível, ativação por convite.
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

    public function test_veterinario_cadastra_tutor_e_o_convite_de_ativacao_sai(): void
    {
        Notification::fake();

        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);

        $response = $this->actingAs($marcelo)->postJson('/api/clinica/tutores', $this->dados());

        $response->assertStatus(201)
            ->assertJsonPath('tutor.nome', 'Helena Ramos')
            ->assertJsonPath('convite.email', 'helena@example.com')
            ->assertJsonPath('convite.validade_em_dias', Convite::VALIDADE_EM_DIAS);

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

        $convite = Convite::query()->where('user_id', $tutor->user_id)->first();
        $this->assertNotNull($convite);
        $this->assertSame('tutor', $convite->tipo);
        $this->assertSame($clinica->id, $convite->prestador_id);
        $this->assertSame($marcelo->id, $convite->convidado_por);

        Notification::assertSentTo($tutor->user, ConviteDeAtivacao::class);
    }

    public function test_cpf_existente_nao_cria_segundo_registro_e_conduz_ao_vinculo(): void
    {
        Notification::fake();

        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);

        Tutor::factory()->create(['nome' => 'Helena Ramos', 'cpf' => self::CPF_VALIDO]);

        $response = $this->actingAs($marcelo)->postJson(
            '/api/clinica/tutores',
            $this->dados(['nome' => 'Helena R.', 'email' => 'outra@example.com']),
        );

        $response->assertStatus(409)->assertJsonPath('situacao', 'cpf_existente');

        // RN12 — a resposta afirma a existência e nada além dela.
        $this->assertStringNotContainsString('Helena Ramos', $response->getContent());

        // RF12b — jamais um segundo registro, nem convite, nem conta nova.
        $this->assertSame(1, Tutor::query()->count());
        $this->assertSame(0, Convite::query()->count());
        $this->assertNull(User::query()->where('email', 'outra@example.com')->first());

        Notification::assertNothingSent();
    }

    public function test_a_revelacao_de_existencia_no_envio_fica_registrada(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);

        $helena = Tutor::factory()->create(['cpf' => self::CPF_VALIDO]);

        $this->actingAs($marcelo)->postJson('/api/clinica/tutores', $this->dados())
            ->assertStatus(409);

        // RF18b — mesmo fora da busca de V03, revelar que o CPF tem cadastro é
        // revelação, e o titular a vê em T14.
        $this->assertDatabaseHas('registros_de_acesso', [
            'prestador_id' => $clinica->id,
            'user_id' => $marcelo->id,
            'tutor_id' => $helena->id,
            'animal_id' => null,
            'natureza' => RegistroDeAcesso::BUSCA_POR_CPF,
        ]);
    }

    public function test_tutor_ja_sob_autorizacao_vigente_nao_gera_linha_no_livro(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);

        $helena = Tutor::factory()->create(['cpf' => self::CPF_VALIDO]);
        $animal = Animal::factory()->create(['tutor_id' => $helena->id]);

        Autorizacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $clinica->id,
            'concedida_por_user_id' => $helena->user_id,
        ]);

        $this->actingAs($marcelo)->postJson('/api/clinica/tutores', $this->dados())
            ->assertStatus(409);

        // O profissional já enxerga este tutor pelo próprio âmbito: anunciar a
        // existência não revelou nada, e linha aqui só encheria T14 de ruído.
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

    public function test_o_convite_carimba_o_prestador_ativo_escolhido(): void
    {
        Notification::fake();

        $primeira = $this->clinica();
        $segunda = $this->clinica('Hospital Veterinário Central');
        $marcelo = $this->marcelo($primeira, $segunda);

        $this->actingAs($marcelo)->postJson(
            "/api/clinica/tutores?prestador={$segunda->id}",
            $this->dados(),
        )->assertStatus(201);

        // RF09b — o convite sai em nome do contexto escolhido na faixa, não do
        // primeiro vínculo: é o nome dele que o tutor lê no e-mail.
        $convite = Convite::query()->firstOrFail();
        $this->assertSame($segunda->id, $convite->prestador_id);
    }
}
