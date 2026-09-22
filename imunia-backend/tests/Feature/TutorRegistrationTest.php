<?php

namespace Tests\Feature;

use App\Models\Tutor;
use App\Models\User;
use App\Support\DocumentosLegais;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TutorRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'nome' => 'Helena Ramos',
            'cpf' => '52998224725',
            'email' => 'helena.ramos@example.com',
            'password' => 'Segredo123',
            'password_confirmation' => 'Segredo123',
            'aceite_termos' => true,
        ], $overrides);
    }

    public function test_autocadastro_cria_usuario_e_tutor(): void
    {
        $response = $this->postJson('/api/tutores', $this->payload());

        $response->assertCreated();
        $response->assertJsonPath('tutor.nome', 'Helena Ramos');

        $tutor = Tutor::firstWhere('cpf', '52998224725');
        $usuario = User::firstWhere('email', 'helena.ramos@example.com');

        $this->assertNotNull($tutor);
        $this->assertNotNull($usuario);
        $this->assertEquals($usuario->id, $tutor->user_id);
    }

    public function test_aceite_dos_termos_fica_registrado_com_data_e_hora(): void
    {
        $this->postJson('/api/tutores', $this->payload())->assertCreated();

        $tutor = Tutor::firstWhere('cpf', '52998224725');

        $this->assertNotNull($tutor->termos_aceitos_em);
        $this->assertTrue($tutor->termos_aceitos_em->isSameMinute(now()));
    }

    /**
     * A data diz quando; a versão diz o quê. Sem as duas, publicar um texto
     * novo reescreveria retroativamente o que todo mundo aceitou.
     */
    public function test_aceite_registra_a_versao_vigente_do_documento(): void
    {
        $this->postJson('/api/tutores', $this->payload())->assertCreated();

        $tutor = Tutor::firstWhere('cpf', '52998224725');

        $this->assertSame(DocumentosLegais::VERSAO, $tutor->termos_versao);
    }

    /**
     * Qual documento estava no ar é fato do sistema, não afirmação de quem se
     * cadastra: um cliente que informe outra versão não muda o que fica gravado.
     */
    public function test_versao_informada_pelo_cliente_e_ignorada(): void
    {
        $this->postJson('/api/tutores', $this->payload([
            'termos_versao' => '0.1',
        ]))->assertCreated();

        $tutor = Tutor::firstWhere('cpf', '52998224725');

        $this->assertSame(DocumentosLegais::VERSAO, $tutor->termos_versao);
    }

    public function test_sem_aceite_dos_termos_a_conta_nao_e_criada(): void
    {
        $response = $this->postJson('/api/tutores', $this->payload([
            'aceite_termos' => false,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('aceite_termos');
        $this->assertNull(Tutor::firstWhere('cpf', '52998224725'));
        $this->assertNull(User::firstWhere('email', 'helena.ramos@example.com'));
    }

    public function test_aceite_omitido_do_corpo_e_rejeitado(): void
    {
        $payload = $this->payload();
        unset($payload['aceite_termos']);

        $response = $this->postJson('/api/tutores', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('aceite_termos');
    }

    public function test_tutor_nao_recebe_prestador_id(): void
    {
        $this->postJson('/api/tutores', $this->payload())->assertCreated();

        $tutor = Tutor::firstWhere('cpf', '52998224725');

        $this->assertArrayNotHasKey('prestador_id', $tutor->getAttributes());
    }

    public function test_cpf_duplicado_e_rejeitado_sem_expor_dados_do_cadastro_existente(): void
    {
        $this->postJson('/api/tutores', $this->payload())->assertCreated();

        $response = $this->postJson('/api/tutores', $this->payload([
            'email' => 'outro@example.com',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('conta');
        $response->assertJsonPath('errors.conta.0', 'Já existe uma conta com estes dados.');
        // A chave contaria o que a mensagem cala: erro em `cpf` confirmaria que
        // aquele CPF tem cadastro, e o CPF vem de quem está do outro lado.
        $response->assertJsonMissingPath('errors.cpf');
        $response->assertJsonMissingPath('errors.email');
    }

    public function test_email_duplicado_e_rejeitado(): void
    {
        $this->postJson('/api/tutores', $this->payload())->assertCreated();

        $response = $this->postJson('/api/tutores', $this->payload([
            'cpf' => '11144477735',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('conta');
        $response->assertJsonMissingPath('errors.email');
        $response->assertJsonMissingPath('errors.cpf');
    }

    /**
     * O que RF12b promete só vale se as três recusas forem a mesma recusa: CPF
     * em uso, e-mail em uso e ambos em uso precisam devolver corpo idêntico, ou
     * a diferença entre eles vira o oráculo que a mensagem única evita.
     */
    public function test_cpf_email_e_ambos_duplicados_devolvem_a_mesma_resposta(): void
    {
        $this->postJson('/api/tutores', $this->payload())->assertCreated();

        $soCpf = $this->postJson('/api/tutores', $this->payload(['email' => 'outro@example.com']));
        $soEmail = $this->postJson('/api/tutores', $this->payload(['cpf' => '11144477735']));
        $ambos = $this->postJson('/api/tutores', $this->payload());

        $soCpf->assertStatus(422);
        $this->assertSame($soCpf->json(), $soEmail->json());
        $this->assertSame($soCpf->json(), $ambos->json());
    }

    /**
     * A mensagem única vale para dado repetido, não para dado malformado: quem
     * digitou o CPF errado precisa saber qual campo corrigir, e essa recusa não
     * afirma nada sobre a existência de cadastro alheio.
     */
    public function test_cpf_invalido_com_email_ja_em_uso_responde_no_campo_cpf(): void
    {
        $this->postJson('/api/tutores', $this->payload())->assertCreated();

        $response = $this->postJson('/api/tutores', $this->payload([
            'cpf' => '52998224720',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('cpf');
        $response->assertJsonMissingPath('errors.conta');
    }

    public function test_cpf_com_digito_verificador_invalido_e_rejeitado(): void
    {
        $response = $this->postJson('/api/tutores', $this->payload([
            'cpf' => '52998224720',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('cpf');
    }

    public function test_cnpj_e_rejeitado_no_campo_cpf(): void
    {
        $response = $this->postJson('/api/tutores', $this->payload([
            'cpf' => '11222333000181',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('cpf');
    }

    public function test_campos_obrigatorios_ausentes_retornam_422(): void
    {
        $response = $this->postJson('/api/tutores', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['nome', 'cpf', 'email', 'password', 'aceite_termos']);
        // A interface exibe a mensagem tal como vem: se a tradução sumir, o
        // tutor lê a frase padrão do framework, em inglês.
        $response->assertJsonValidationErrors([
            'email' => 'Informe o e-mail.',
            'password' => 'Crie uma senha.',
        ]);
    }

    public function test_senha_curta_e_rejeitada(): void
    {
        $response = $this->postJson('/api/tutores', $this->payload([
            'password' => 'Curta1',
            'password_confirmation' => 'Curta1',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('password');
    }

    public function test_senha_sem_maiuscula_e_rejeitada(): void
    {
        $response = $this->postJson('/api/tutores', $this->payload([
            'password' => 'segredo123',
            'password_confirmation' => 'segredo123',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('password');
    }

    public function test_senha_sem_numero_ou_simbolo_e_rejeitada(): void
    {
        $response = $this->postJson('/api/tutores', $this->payload([
            'password' => 'SegredoLongo',
            'password_confirmation' => 'SegredoLongo',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('password');
    }

    public function test_senhas_diferentes_sao_rejeitadas(): void
    {
        $response = $this->postJson('/api/tutores', $this->payload([
            'password_confirmation' => 'Diferente123',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('password');
    }
}
