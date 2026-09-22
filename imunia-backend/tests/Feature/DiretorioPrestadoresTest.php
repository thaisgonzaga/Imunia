<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Autorizacao;
use App\Models\Prestador;
use App\Models\Tutor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiretorioPrestadoresTest extends TestCase
{
    use RefreshDatabase;

    private function helena(): Tutor
    {
        $usuario = User::factory()->create([
            'name' => 'Helena Ramos',
            'email' => 'helena.ramos@example.com',
        ]);

        return Tutor::factory()->create(['user_id' => $usuario->id, 'nome' => 'Helena Ramos']);
    }

    private function prestador(string $nome, string $municipio = 'Viçosa', string $tipo = 'clinica'): Prestador
    {
        return Prestador::factory()->create([
            'nome' => $nome,
            'municipio' => $municipio,
            'uf' => 'MG',
            'tipo' => $tipo,
        ]);
    }

    public function test_o_diretorio_exige_sessao(): void
    {
        $this->getJson('/api/prestadores')->assertUnauthorized();
    }

    public function test_quem_nao_e_tutor_nao_alcanca_o_diretorio(): void
    {
        // O diretório é o ponto de partida da autorização (RF11b), e só o tutor
        // concede. O veterinário tem a busca do ambiente clínico (V03).
        $this->actingAs(User::factory()->create())
            ->getJson('/api/prestadores')
            ->assertForbidden();
    }

    public function test_o_cartao_traz_identificacao_e_contato_publicos(): void
    {
        $tutor = $this->helena();
        $this->prestador('Clínica Vet Amigo');

        $resposta = $this->actingAs($tutor->user)->getJson('/api/prestadores');

        $resposta->assertOk();
        $resposta->assertJsonPath('prestadores.0.nome', 'Clínica Vet Amigo');
        $resposta->assertJsonPath('prestadores.0.tipo_rotulo', 'Clínica veterinária');
        $resposta->assertJsonPath('prestadores.0.municipio', 'Viçosa');
        $resposta->assertJsonPath('prestadores.0.uf', 'MG');
        $resposta->assertJsonPath('prestadores.0.telefone', '(31) 3899-1000');
    }

    public function test_o_tipo_determina_o_rotulo_sem_alterar_o_modelo(): void
    {
        // RF07a — o mesmo campo `tipo` responde por três rótulos diferentes.
        $tutor = $this->helena();
        $this->prestador('Hospital Bicho Bom', tipo: 'hospital');
        $this->prestador('Dra. Larissa Prado', tipo: 'autonomo');

        $resposta = $this->actingAs($tutor->user)->getJson('/api/prestadores');

        $resposta->assertJsonPath('prestadores.0.tipo_rotulo', 'Atendimento domiciliar');
        $resposta->assertJsonPath('prestadores.1.tipo_rotulo', 'Hospital veterinário');
    }

    public function test_o_diretorio_nao_expoe_dado_operacional_do_prestador(): void
    {
        // RF11a — o diretório serve para encontrar quem vai atender, não para
        // comparar tamanho de clínica. Nem a contagem, nem o caminho para
        // calculá-la: o CNPJ e o responsável técnico também
        // não são informação que o tutor precise para escolher.
        $tutor = $this->helena();
        $prestador = $this->prestador('Clínica Vet Amigo');

        $animal = Animal::factory()->create(['tutor_id' => $tutor->id]);
        Autorizacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'concedida_por_user_id' => $tutor->user_id,
        ]);

        $cartao = $this->actingAs($tutor->user)
            ->getJson('/api/prestadores')
            ->json('prestadores.0');

        $this->assertSame([
            'id', 'nome', 'tipo', 'tipo_rotulo', 'municipio', 'uf', 'telefone', 'autorizacao',
        ], array_keys($cartao));

        $serializado = json_encode($cartao, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString($prestador->cnpj, $serializado);
        $this->assertStringNotContainsString('Marcelo Andrade', $serializado);
        $this->assertStringNotContainsString('12345', $serializado);
    }

    public function test_a_busca_por_nome_restringe_a_lista(): void
    {
        $tutor = $this->helena();
        $this->prestador('Clínica Vet Amigo');
        $this->prestador('Hospital Bicho Bom');

        $resposta = $this->actingAs($tutor->user)->getJson('/api/prestadores?nome=amigo&municipio=');

        $resposta->assertJsonCount(1, 'prestadores');
        $resposta->assertJsonPath('prestadores.0.nome', 'Clínica Vet Amigo');
        $resposta->assertJsonPath('total', 1);
    }

    public function test_o_curinga_digitado_pelo_tutor_e_tratado_como_texto(): void
    {
        $tutor = $this->helena();
        $this->prestador('Clínica Vet Amigo');

        $resposta = $this->actingAs($tutor->user)->getJson('/api/prestadores?nome=%25&municipio=');

        $resposta->assertJsonCount(0, 'prestadores');
    }

    public function test_o_filtro_de_municipio_restringe_a_lista(): void
    {
        $tutor = $this->helena();
        $this->prestador('Clínica Vet Amigo', 'Viçosa');
        $this->prestador('Pet Center Zona Sul', 'Belo Horizonte');

        $resposta = $this->actingAs($tutor->user)
            ->getJson('/api/prestadores?municipio=Belo+Horizonte&uf=MG');

        $resposta->assertJsonCount(1, 'prestadores');
        $resposta->assertJsonPath('prestadores.0.nome', 'Pet Center Zona Sul');
        $resposta->assertJsonPath('filtro.rotulo_municipio', 'Belo Horizonte, MG');
        $resposta->assertJsonPath('filtro.sugerido', false);
    }

    public function test_o_municipio_vem_pre_preenchido_pelo_do_prestador_ja_autorizado(): void
    {
        // §6.1 do briefing — "filtro pré-preenchido pelo município do tutor,
        // quando conhecido". O cadastro do tutor não guarda endereço; o
        // município que o sistema conhece a respeito dele é o do
        // estabelecimento que ele mesmo autorizou.
        $tutor = $this->helena();
        $daCidade = $this->prestador('Clínica Vet Amigo', 'Viçosa');
        $this->prestador('Pet Center Zona Sul', 'Belo Horizonte');

        $animal = Animal::factory()->create(['tutor_id' => $tutor->id]);
        Autorizacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $daCidade->id,
            'concedida_por_user_id' => $tutor->user_id,
        ]);

        $resposta = $this->actingAs($tutor->user)->getJson('/api/prestadores');

        $resposta->assertJsonPath('filtro.municipio', 'Viçosa');
        $resposta->assertJsonPath('filtro.sugerido', true);
        $resposta->assertJsonCount(1, 'prestadores');
    }

    public function test_limpar_o_municipio_desfaz_a_sugestao(): void
    {
        // A ação de limpar do desenho. Sem a distinção entre "não escolhi" e
        // "escolhi ver todos", a sugestão voltaria na requisição seguinte e o
        // botão de limpar não teria efeito algum.
        $tutor = $this->helena();
        $daCidade = $this->prestador('Clínica Vet Amigo', 'Viçosa');
        $this->prestador('Pet Center Zona Sul', 'Belo Horizonte');

        $animal = Animal::factory()->create(['tutor_id' => $tutor->id]);
        Autorizacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $daCidade->id,
            'concedida_por_user_id' => $tutor->user_id,
        ]);

        $resposta = $this->actingAs($tutor->user)->getJson('/api/prestadores?municipio=');

        $resposta->assertJsonPath('filtro.municipio', null);
        $resposta->assertJsonPath('filtro.sugerido', false);
        $resposta->assertJsonCount(2, 'prestadores');
    }

    public function test_tutor_sem_autorizacao_alguma_recebe_a_lista_inteira(): void
    {
        $tutor = $this->helena();
        $this->prestador('Clínica Vet Amigo', 'Viçosa');
        $this->prestador('Pet Center Zona Sul', 'Belo Horizonte');

        $resposta = $this->actingAs($tutor->user)->getJson('/api/prestadores');

        $resposta->assertJsonPath('filtro.municipio', null);
        $resposta->assertJsonCount(2, 'prestadores');
    }

    public function test_prestador_ja_autorizado_traz_o_prazo_e_o_animal_alcancado(): void
    {
        $tutor = $this->helena();
        $prestador = $this->prestador('Clínica Vet Amigo');

        $theo = Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Théo']);
        Animal::factory()->gato()->create(['tutor_id' => $tutor->id, 'nome' => 'Nina']);

        $autorizacao = Autorizacao::factory()->create([
            'animal_id' => $theo->id,
            'prestador_id' => $prestador->id,
            'concedida_por_user_id' => $tutor->user_id,
        ]);

        $resposta = $this->actingAs($tutor->user)->getJson('/api/prestadores?municipio=');

        $resposta->assertJsonPath(
            'prestadores.0.autorizacao.expira_em',
            $autorizacao->expira_em->toDateString(),
        );
        $resposta->assertJsonPath('prestadores.0.autorizacao.animais', ['Théo']);

        // RF36a — a autorização é nominal por animal. A Nina não está coberta,
        // e a tela precisa saber disso para não dizer ao tutor que está.
        $resposta->assertJsonPath('prestadores.0.autorizacao.pendentes', 1);
    }

    public function test_autorizacao_expirada_ou_revogada_nao_marca_o_cartao(): void
    {
        $tutor = $this->helena();
        $expirado = $this->prestador('Clínica Vet Amigo');
        $revogado = $this->prestador('Hospital Bicho Bom');

        $animal = Animal::factory()->create(['tutor_id' => $tutor->id]);

        Autorizacao::factory()->expirada()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $expirado->id,
            'concedida_por_user_id' => $tutor->user_id,
        ]);
        Autorizacao::factory()->revogada()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $revogado->id,
            'concedida_por_user_id' => $tutor->user_id,
        ]);

        $resposta = $this->actingAs($tutor->user)->getJson('/api/prestadores?municipio=');

        $resposta->assertJsonPath('prestadores.0.autorizacao', null);
        $resposta->assertJsonPath('prestadores.1.autorizacao', null);
    }

    public function test_autorizacao_de_outro_tutor_nao_marca_o_cartao(): void
    {
        // A etiqueta responde sobre o tutor autenticado. Marcá-la por
        // autorização alheia diria a Helena que ela já autorizou onde não
        // autorizou — e, de quebra, revelaria movimento do prestador (RF11a).
        $tutor = $this->helena();
        $prestador = $this->prestador('Clínica Vet Amigo');

        $outro = Tutor::factory()->create(['nome' => 'Marcos Lima']);
        $animalAlheio = Animal::factory()->create(['tutor_id' => $outro->id]);

        Autorizacao::factory()->create([
            'animal_id' => $animalAlheio->id,
            'prestador_id' => $prestador->id,
            'concedida_por_user_id' => $outro->user_id,
        ]);

        $resposta = $this->actingAs($tutor->user)->getJson('/api/prestadores?municipio=');

        $resposta->assertJsonPath('prestadores.0.autorizacao', null);
    }

    public function test_o_seletor_lista_os_municipios_com_estabelecimento_sem_contagem(): void
    {
        $tutor = $this->helena();
        $this->prestador('Clínica Vet Amigo', 'Viçosa');
        $this->prestador('Hospital Bicho Bom', 'Viçosa');
        $this->prestador('Pet Center Zona Sul', 'Belo Horizonte');

        $municipios = $this->actingAs($tutor->user)
            ->getJson('/api/prestadores')
            ->json('municipios');

        $this->assertSame([
            ['municipio' => 'Belo Horizonte', 'uf' => 'MG', 'rotulo' => 'Belo Horizonte, MG'],
            ['municipio' => 'Viçosa', 'uf' => 'MG', 'rotulo' => 'Viçosa, MG'],
        ], $municipios);
    }

    public function test_municipio_sem_estabelecimento_devolve_lista_vazia(): void
    {
        $tutor = $this->helena();
        $this->prestador('Clínica Vet Amigo', 'Viçosa');

        $resposta = $this->actingAs($tutor->user)->getJson('/api/prestadores?municipio=Cajuri&uf=MG');

        $resposta->assertOk();
        $resposta->assertJsonCount(0, 'prestadores');
        $resposta->assertJsonPath('total', 0);
        $resposta->assertJsonPath('filtro.rotulo_municipio', 'Cajuri, MG');
    }
}
