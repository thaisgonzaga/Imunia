<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Prestador;
use App\Models\RegistroDeAcesso;
use App\Models\Tutor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T14 — quem acessou meus dados (RF53), a leitura do livro que V03 e V06
 * escrevem (RF52).
 */
class LivroDeAcessosTest extends TestCase
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

    private function animal(Tutor $tutor, string $nome, string $especie = 'cao'): Animal
    {
        return Animal::factory()->create([
            'tutor_id' => $tutor->id,
            'nome' => $nome,
            'especie' => $especie,
        ]);
    }

    private function prestador(string $nome): Prestador
    {
        return Prestador::factory()->create([
            'nome' => $nome,
            'tipo' => 'clinica',
            'municipio' => 'Viçosa',
            'uf' => 'MG',
        ]);
    }

    private function veterinario(Prestador $prestador, string $nome = 'Marcelo Andrade'): User
    {
        $usuario = User::factory()->create(['name' => $nome]);

        $usuario->prestadores()->attach($prestador, [
            'papel' => 'veterinario',
            'crmv' => '12345',
            'crmv_uf' => 'MG',
        ]);

        return $usuario;
    }

    private function vincular(Animal $animal, Prestador $prestador): void
    {
        $prestador->vincular($animal);
    }

    public function test_a_auditoria_exige_sessao(): void
    {
        $this->getJson('/api/acessos')->assertUnauthorized();
    }

    public function test_quem_nao_e_tutor_nao_alcanca_a_auditoria(): void
    {
        // O livro de acessos é do titular dos dados. O veterinário não o
        // consulta por tela alguma: ele é quem figura nele.
        $this->actingAs(User::factory()->create())
            ->getJson('/api/acessos')
            ->assertForbidden();
    }

    public function test_a_relacao_agrupa_por_dia_com_prestador_profissional_e_hora(): void
    {
        $helena = $this->helena();
        $theo = $this->animal($helena, 'Théo');
        $vetAmigo = $this->prestador('Clínica Vet Amigo');
        $marcelo = $this->veterinario($vetAmigo);

        RegistroDeAcesso::factory()->sobre($theo)->em('2026-08-18 09:14:00')->create([
            'prestador_id' => $vetAmigo->id,
            'user_id' => $marcelo->id,
        ]);
        RegistroDeAcesso::factory()->sobre($theo)->em('2026-08-15 08:20:00')->create([
            'prestador_id' => $vetAmigo->id,
            'user_id' => $marcelo->id,
        ]);

        $resposta = $this->actingAs($helena->user)
            ->getJson('/api/acessos?periodo=tudo')
            ->assertOk();

        $resposta->assertJsonPath('total', 2);
        $resposta->assertJsonCount(2, 'dias');

        // Do mais recente para o mais antigo: a tela se lê de cima, e a
        // pergunta do tutor é sobre o que acabou de acontecer.
        $resposta->assertJsonPath('dias.0.data', '2026-08-18');
        $resposta->assertJsonPath('dias.1.data', '2026-08-15');

        $resposta->assertJsonPath('dias.0.acessos.0.hora', '09:14');
        $resposta->assertJsonPath('dias.0.acessos.0.prestador.nome', 'Clínica Vet Amigo');

        // RF53a — identifica o profissional, e com o CRMV do vínculo com
        // aquele prestador.
        $resposta->assertJsonPath('dias.0.acessos.0.profissional.nome', 'Marcelo Andrade');
        $resposta->assertJsonPath('dias.0.acessos.0.profissional.crmv', 'CRMV-MG 12345');
    }

    public function test_a_auditoria_de_um_tutor_nao_alcanca_a_de_outro(): void
    {
        $helena = $this->helena();
        $vetAmigo = $this->prestador('Clínica Vet Amigo');
        $marcelo = $this->veterinario($vetAmigo);

        $outro = Tutor::factory()->create(['nome' => 'Outro Tutor']);
        $bidu = $this->animal($outro, 'Bidu');

        RegistroDeAcesso::factory()->sobre($bidu)->create([
            'prestador_id' => $vetAmigo->id,
            'user_id' => $marcelo->id,
        ]);

        $resposta = $this->actingAs($helena->user)
            ->getJson('/api/acessos?periodo=tudo')
            ->assertOk();

        $resposta->assertJsonPath('total', 0);
        $resposta->assertJsonPath('algum_acesso', false);
    }

    public function test_a_linha_diz_se_a_clinica_acompanha_o_animal(): void
    {
        $helena = $this->helena();
        $theo = $this->animal($helena, 'Théo');
        $vetAmigo = $this->prestador('Clínica Vet Amigo');
        $marcelo = $this->veterinario($vetAmigo);
        $bichoBom = $this->prestador('Clínica Bicho Bom');
        $larissa = $this->veterinario($bichoBom, 'Larissa Prado');

        $this->vincular($theo, $vetAmigo);

        RegistroDeAcesso::factory()->sobre($theo)->em(now()->subHour()->toDateTimeString())->create([
            'prestador_id' => $vetAmigo->id,
            'user_id' => $marcelo->id,
        ]);
        RegistroDeAcesso::factory()->sobre($theo)->natureza(RegistroDeAcesso::BUSCA_POR_CODIGO)->create([
            'prestador_id' => $bichoBom->id,
            'user_id' => $larissa->id,
        ]);

        $resposta = $this->actingAs($helena->user)->getJson('/api/acessos')->assertOk();

        // A mais recente primeiro: a clínica que só pesquisou o código e não
        // acompanha o Théo.
        $resposta->assertJsonPath('dias.0.acessos.0.prestador.nome', 'Clínica Bicho Bom');
        $resposta->assertJsonPath('dias.0.acessos.0.acompanha', false);
        $resposta->assertJsonPath('dias.0.acessos.1.prestador.nome', 'Clínica Vet Amigo');
        $resposta->assertJsonPath('dias.0.acessos.1.acompanha', true);

        // O modelo de consentimento saiu: nenhuma linha fala mais dele.
        $resposta->assertJsonMissingPath('dias.0.acessos.0.autorizacao');
        $resposta->assertJsonMissingPath('dias.0.acessos.0.revogavel');
    }

    public function test_a_natureza_antiga_de_ficha_continua_legivel(): void
    {
        $helena = $this->helena();
        $theo = $this->animal($helena, 'Théo');
        $bichoBom = $this->prestador('Clínica Bicho Bom');
        $vet = $this->veterinario($bichoBom, 'Larissa Prado');

        // Linhas gravadas antes da mudança continuam no livro (RF52a) e
        // precisam se ler sem o vocabulário de autorização, que saiu.
        RegistroDeAcesso::factory()
            ->sobre($theo)
            ->natureza(RegistroDeAcesso::FICHA_SEM_AUTORIZACAO)
            ->create(['prestador_id' => $bichoBom->id, 'user_id' => $vet->id]);

        $resposta = $this->actingAs($helena->user)->getJson('/api/acessos')->assertOk();

        $resposta->assertJsonPath('dias.0.acessos.0.descricao', 'Abriu a ficha de Théo');
        $resposta->assertJsonPath('dias.0.acessos.0.resumo', 'ficha do animal');
    }

    public function test_a_busca_por_cpf_aparece_sem_animal(): void
    {
        $helena = $this->helena();
        $bichoBom = $this->prestador('Clínica Bicho Bom');
        $vet = $this->veterinario($bichoBom, 'Larissa Prado');

        RegistroDeAcesso::factory()->buscaPorCpf($helena)->create([
            'prestador_id' => $bichoBom->id,
            'user_id' => $vet->id,
        ]);

        $resposta = $this->actingAs($helena->user)->getJson('/api/acessos')->assertOk();

        $resposta->assertJsonPath('dias.0.acessos.0.animal', null);
        $resposta->assertJsonPath(
            'dias.0.acessos.0.descricao',
            'Pesquisou o seu CPF e encontrou o seu cadastro',
        );
    }

    public function test_o_filtro_por_animal_recorta_a_relacao(): void
    {
        $helena = $this->helena();
        $theo = $this->animal($helena, 'Théo');
        $nina = $this->animal($helena, 'Nina', 'gato');
        $vetAmigo = $this->prestador('Clínica Vet Amigo');
        $marcelo = $this->veterinario($vetAmigo);

        RegistroDeAcesso::factory()->sobre($theo)->create([
            'prestador_id' => $vetAmigo->id,
            'user_id' => $marcelo->id,
        ]);
        RegistroDeAcesso::factory()->sobre($nina)->create([
            'prestador_id' => $vetAmigo->id,
            'user_id' => $marcelo->id,
        ]);

        $resposta = $this->actingAs($helena->user)
            ->getJson("/api/acessos?animal={$nina->codigo}")
            ->assertOk();

        $resposta->assertJsonPath('total', 1);
        $resposta->assertJsonPath('dias.0.acessos.0.animal.nome', 'Nina');

        // O vazio filtrado precisa distinguir-se do vazio de verdade: há
        // acessos, só não neste recorte.
        $resposta->assertJsonPath('algum_acesso', true);
        $resposta->assertJsonPath('filtro.animal', $nina->codigo);
    }

    public function test_o_codigo_de_animal_alheio_nao_revela_acesso_algum(): void
    {
        $helena = $this->helena();
        $theo = $this->animal($helena, 'Théo');
        $vetAmigo = $this->prestador('Clínica Vet Amigo');
        $marcelo = $this->veterinario($vetAmigo);

        $outro = Tutor::factory()->create(['nome' => 'Outro Tutor']);
        $bidu = $this->animal($outro, 'Bidu');

        RegistroDeAcesso::factory()->sobre($theo)->create([
            'prestador_id' => $vetAmigo->id,
            'user_id' => $marcelo->id,
        ]);

        // Código de animal que não é dele não é erro a explicar: é recorte que
        // não encontra nada. Um 404 aqui confirmaria que o código existe.
        $this->actingAs($helena->user)
            ->getJson("/api/acessos?animal={$bidu->codigo}")
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_o_periodo_padrao_sao_doze_meses(): void
    {
        $helena = $this->helena();
        $theo = $this->animal($helena, 'Théo');
        $vetAmigo = $this->prestador('Clínica Vet Amigo');
        $marcelo = $this->veterinario($vetAmigo);

        RegistroDeAcesso::factory()
            ->sobre($theo)
            ->em(now()->subMonths(14)->toDateTimeString())
            ->create(['prestador_id' => $vetAmigo->id, 'user_id' => $marcelo->id]);

        $resposta = $this->actingAs($helena->user)->getJson('/api/acessos')->assertOk();

        $resposta->assertJsonPath('filtro.periodo', '12m');
        $resposta->assertJsonPath('total', 0);

        // "Ver todo o período" é a saída que o desenho oferece no vazio
        // filtrado, e ela precisa encontrar o que o recorte escondeu.
        $this->actingAs($helena->user)
            ->getJson('/api/acessos?periodo=tudo')
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_o_periodo_desconhecido_recai_no_padrao_sem_erro_de_campo(): void
    {
        $helena = $this->helena();

        $this->actingAs($helena->user)
            ->getJson('/api/acessos?periodo=ontem')
            ->assertOk()
            ->assertJsonPath('filtro.periodo', '12m');
    }

    public function test_o_filtro_por_prestador_chega_nomeado(): void
    {
        $helena = $this->helena();
        $theo = $this->animal($helena, 'Théo');
        $vetAmigo = $this->prestador('Clínica Vet Amigo');
        $serra = $this->prestador('Vet Serra');
        $marcelo = $this->veterinario($vetAmigo);
        $outroVet = $this->veterinario($serra, 'Larissa Prado');

        $this->vincular($theo, $vetAmigo);

        RegistroDeAcesso::factory()->sobre($theo)->create([
            'prestador_id' => $vetAmigo->id,
            'user_id' => $marcelo->id,
        ]);
        RegistroDeAcesso::factory()->sobre($theo)->create([
            'prestador_id' => $serra->id,
            'user_id' => $outroVet->id,
        ]);

        $resposta = $this->actingAs($helena->user)
            ->getJson("/api/acessos?prestador={$serra->id}")
            ->assertOk();

        $resposta->assertJsonPath('total', 1);
        $resposta->assertJsonPath('dias.0.acessos.0.prestador.nome', 'Vet Serra');

        // A etiqueta que a tela oferece remover precisa de nome, e o nome vem
        // do recorte — não da lista, que pode estar vazia.
        $resposta->assertJsonPath('prestador.nome', 'Vet Serra');
    }

    public function test_o_prestador_estranho_ao_tutor_nao_e_nomeado(): void
    {
        $helena = $this->helena();
        $estranho = $this->prestador('Clínica Que Nunca Atendeu');

        // T14 não é caminho para descobrir prestadores: sem vínculo nem acesso
        // a animal deste tutor, o identificador não vira nome.
        $this->actingAs($helena->user)
            ->getJson("/api/acessos?prestador={$estranho->id}")
            ->assertOk()
            ->assertJsonPath('prestador', null);
    }

    public function test_a_tela_recebe_os_animais_e_as_clinicas_que_acompanham(): void
    {
        $helena = $this->helena();
        $theo = $this->animal($helena, 'Théo');
        $nina = $this->animal($helena, 'Nina', 'gato');
        $vetAmigo = $this->prestador('Clínica Vet Amigo');

        $this->vincular($nina, $vetAmigo);

        $resposta = $this->actingAs($helena->user)
            ->getJson("/api/acessos?animal={$nina->codigo}")
            ->assertOk();

        // Os filtros do desenho: a lista de animais e as opções de período.
        $resposta->assertJsonCount(2, 'animais');
        $resposta->assertJsonCount(3, 'periodos');

        // O apoio do vazio filtrado: a clínica acompanha e não acessou — o
        // silêncio é do prestador, não do registro.
        $resposta->assertJsonPath('clinicas.0.nome', 'Clínica Vet Amigo');
        $resposta->assertJsonPath('total', 0);

        $semAcesso = $this->actingAs($helena->user)
            ->getJson("/api/acessos?animal={$theo->codigo}")
            ->assertOk();

        $semAcesso->assertJsonPath('clinicas', []);
    }
}
