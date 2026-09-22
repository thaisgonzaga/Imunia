<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Atendimento;
use App\Models\Autorizacao;
use App\Models\Imunobiologico;
use App\Models\Prestador;
use App\Models\ProtocoloVacinal;
use App\Models\Tutor;
use App\Models\User;
use App\Models\Vacinacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PainelVeterinarioTest extends TestCase
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
     * Animal com autorização vigente para o prestador — a única forma de um
     * animal entrar no painel (RN48).
     */
    private function animalAutorizado(Prestador $prestador, string $nome = 'Théo', array $atributos = []): Animal
    {
        $tutor = Tutor::factory()->create(['nome' => "Tutor de {$nome}"]);
        $animal = Animal::factory()->create([...$atributos, 'tutor_id' => $tutor->id, 'nome' => $nome]);

        Autorizacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'concedida_por_user_id' => $tutor->user_id,
        ]);

        return $animal;
    }

    private function atendimento(Animal $animal, Prestador $prestador, User $profissional, array $atributos = []): Atendimento
    {
        return Atendimento::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'profissional_user_id' => $profissional->id,
            'atendido_em' => now()->subDays(3),
            ...$atributos,
        ]);
    }

    public function test_o_painel_exige_sessao(): void
    {
        $this->getJson('/api/clinica/painel')->assertUnauthorized();
    }

    /**
     * O ambiente clínico é de quem tem vínculo de veterinário. O tutor tem o
     * painel dele (T01), e um painel vazio aqui seria a tela errada, não a tela
     * incompleta.
     */
    public function test_quem_nao_e_veterinario_nao_alcanca_o_painel(): void
    {
        $tutor = Tutor::factory()->create();

        $this->actingAs($tutor->user)
            ->getJson('/api/clinica/painel')
            ->assertForbidden();
    }

    public function test_o_administrador_do_prestador_nao_alcanca_o_painel_clinico(): void
    {
        // RN08 — a administração cuida da conta do prestador, não do dado
        // clínico. O papel não abre esta porta nem no próprio prestador.
        $clinica = $this->clinica();
        $usuario = User::factory()->create();
        $usuario->prestadores()->attach($clinica, ['papel' => 'admin_prestador']);

        $this->actingAs($usuario)
            ->getJson('/api/clinica/painel')
            ->assertForbidden();
    }

    public function test_o_painel_identifica_o_profissional_e_o_prestador_ativo(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $this->animalAutorizado($clinica);
        $this->atendimento($this->animalAutorizado($clinica, 'Bidu'), $clinica, $marcelo);

        $resposta = $this->actingAs($marcelo)->getJson('/api/clinica/painel');

        $resposta->assertOk();
        $resposta->assertJsonPath('profissional.nome', 'Marcelo Andrade');
        // O CRMV vem do vínculo, não do usuário: a inscrição é por regional.
        $resposta->assertJsonPath('profissional.crmv', 'CRMV-MG 12345');
        $resposta->assertJsonPath('prestador.nome', 'Clínica Vet Amigo');
        $resposta->assertJsonPath('intervalo.dias', 30);
    }

    /**
     * RN48 — a regra que dá âmbito a toda esta tela. O animal existe, tem
     * registro do prestador, e mesmo assim não figura: o que o traz para cá é a
     * autorização do tutor, e nada mais.
     */
    public function test_animal_sem_autorizacao_vigente_nao_figura_no_painel(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);

        $autorizado = $this->animalAutorizado($clinica, 'Théo');
        $this->atendimento($autorizado, $clinica, $marcelo);

        $semAutorizacao = Animal::factory()->create(['nome' => 'Bidu']);
        $this->atendimento($semAutorizacao, $clinica, $marcelo);

        $resposta = $this->actingAs($marcelo)->getJson('/api/clinica/painel');

        $resposta->assertJsonPath('animais_atendidos.paginacao.total', 1);
        $resposta->assertJsonPath('animais_atendidos.itens.0.nome', 'Théo');
        // O atendimento do Bidu existe e é do prestador, mas não é contado:
        // indicador é consulta agregada, e RN48 alcança as agregadas também.
        $resposta->assertJsonPath('indicadores.atendimentos', 1);
    }

    public function test_autorizacao_revogada_ou_expirada_nao_da_acesso(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);

        foreach (['revogada', 'expirada'] as $estado) {
            $animal = Animal::factory()->create(['nome' => "Animal {$estado}"]);
            Autorizacao::factory()->{$estado}()->create([
                'animal_id' => $animal->id,
                'prestador_id' => $clinica->id,
                'concedida_por_user_id' => $animal->tutor->user_id,
            ]);
            $this->atendimento($animal, $clinica, $marcelo);
        }

        $this->actingAs($marcelo)
            ->getJson('/api/clinica/painel')
            ->assertJsonPath('animais_atendidos.paginacao.total', 0)
            ->assertJsonPath('indicadores.atendimentos', 0);
    }

    /**
     * RF48a — o painel abrange exclusivamente o contexto do prestador ativo. O
     * que o mesmo veterinário registrou no outro estabelecimento não é assunto
     * deste.
     */
    public function test_o_painel_nao_mistura_prestadores(): void
    {
        $clinica = $this->clinica();
        $hospital = $this->clinica('Hospital Bicho Bom');
        $marcelo = $this->marcelo($clinica, $hospital);

        $daClinica = $this->animalAutorizado($clinica, 'Théo');
        $this->atendimento($daClinica, $clinica, $marcelo);

        $doHospital = $this->animalAutorizado($hospital, 'Amora');
        $this->atendimento($doHospital, $hospital, $marcelo);

        $resposta = $this->actingAs($marcelo)->getJson('/api/clinica/painel');

        $resposta->assertJsonPath('prestador.nome', 'Clínica Vet Amigo');
        $resposta->assertJsonPath('animais_atendidos.paginacao.total', 1);
        $resposta->assertJsonPath('animais_atendidos.itens.0.nome', 'Théo');

        // RF09b — o profissional alterna o contexto, e o painel inteiro muda.
        $noHospital = $this->actingAs($marcelo)
            ->getJson("/api/clinica/painel?prestador={$hospital->id}");

        $noHospital->assertJsonPath('prestador.nome', 'Hospital Bicho Bom');
        $noHospital->assertJsonPath('animais_atendidos.itens.0.nome', 'Amora');
        $noHospital->assertJsonCount(2, 'vinculos');
    }

    public function test_prestador_sem_vinculo_com_o_profissional_e_recusado(): void
    {
        $clinica = $this->clinica();
        $alheio = $this->clinica('Clínica de Outrem');
        $marcelo = $this->marcelo($clinica);

        $this->actingAs($marcelo)
            ->getJson("/api/clinica/painel?prestador={$alheio->id}")
            ->assertForbidden();
    }

    /**
     * RF48b — o intervalo é ajustável pelo usuário. Aos sete dias, o atendimento
     * de três semanas atrás sai da conta sem sair do sistema.
     */
    public function test_o_intervalo_e_ajustavel_pelo_usuario(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animalAutorizado($clinica);

        $this->atendimento($animal, $clinica, $marcelo, ['atendido_em' => now()->subDays(2)]);
        $this->atendimento($animal, $clinica, $marcelo, ['atendido_em' => now()->subDays(21)]);

        $this->actingAs($marcelo)->getJson('/api/clinica/painel?dias=7')
            ->assertJsonPath('intervalo.dias', 7)
            ->assertJsonPath('indicadores.atendimentos', 1);

        $this->actingAs($marcelo)->getJson('/api/clinica/painel?dias=90')
            ->assertJsonPath('intervalo.dias', 90)
            ->assertJsonPath('indicadores.atendimentos', 2);

        // Intervalo fora da lista volta ao padrão: o parâmetro vem da própria
        // interface, e uma tela que não abre seria resposta pior que a padrão.
        $this->actingAs($marcelo)->getJson('/api/clinica/painel?dias=45')
            ->assertJsonPath('intervalo.dias', 30);
    }

    /**
     * O único indicador pessoal do painel. O atendimento da colega, no mesmo
     * prestador e no mesmo período, não entra no "registrados por você".
     */
    public function test_o_indicador_de_atendimentos_conta_so_os_do_proprio_profissional(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $larissa = $this->marcelo($clinica);
        $animal = $this->animalAutorizado($clinica);

        $this->atendimento($animal, $clinica, $marcelo);
        $this->atendimento($animal, $clinica, $larissa, ['atendido_em' => now()->subDay()]);

        $this->actingAs($marcelo)->getJson('/api/clinica/painel')
            ->assertJsonPath('indicadores.atendimentos', 1)
            // A lista de atendidos é do prestador, e o animal foi atendido: uma
            // linha só, porque a lista é de animais e não de atendimentos.
            ->assertJsonPath('animais_atendidos.paginacao.total', 1);
    }

    public function test_dose_atrasada_de_animal_autorizado_vira_pendencia(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animalAutorizado($clinica);

        $imunobiologico = Imunobiologico::factory()->antirrabica()->create();
        $protocolo = ProtocoloVacinal::factory()->antirrabica()->create([
            'imunobiologico_id' => $imunobiologico->id,
        ]);

        Vacinacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $clinica->id,
            'imunobiologico_id' => $imunobiologico->id,
            'protocolo_vacinal_id' => $protocolo->id,
            'aplicado_em' => now()->subMonths(14),
        ]);

        $resposta = $this->actingAs($marcelo)->getJson('/api/clinica/painel');

        $resposta->assertJsonCount(1, 'pendencias');
        $resposta->assertJsonPath('pendencias.0.animal.nome', 'Théo');
        $resposta->assertJsonPath('pendencias.0.situacao.tipo', 'atrasada');
        // Conta animais, não doses: a rechamada é um telefonema por tutor.
        $resposta->assertJsonPath('indicadores.doses_vencidas.animais', 1);
    }

    /**
     * RF34 — o retorno programado entra no painel enquanto está por vir. O que
     * já passou é rechamada (V02), não previsão.
     */
    public function test_retorno_programado_aparece_enquanto_esta_por_vir(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animalAutorizado($clinica);

        $this->atendimento($animal, $clinica, $marcelo, [
            'retorno_em' => now()->addDays(5)->toDateString(),
            'retorno_finalidade' => 'Reavaliação dermatológica',
        ]);
        $this->atendimento($animal, $clinica, $marcelo, [
            'atendido_em' => now()->subDays(20),
            'retorno_em' => now()->subDays(5)->toDateString(),
            'retorno_finalidade' => 'Retorno que já passou',
        ]);

        $resposta = $this->actingAs($marcelo)->getJson('/api/clinica/painel');

        $resposta->assertJsonCount(1, 'retornos');
        $resposta->assertJsonPath('retornos.0.finalidade', 'Reavaliação dermatológica');
        $resposta->assertJsonPath('indicadores.retornos_previstos', 1);
    }

    /**
     * §8.3 do briefing — o primeiro acesso do profissional não é um painel
     * vazio: é a orientação em três passos.
     */
    public function test_prestador_sem_registro_algum_recebe_o_estado_de_primeiro_acesso(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);

        $this->actingAs($marcelo)->getJson('/api/clinica/painel')
            ->assertOk()
            ->assertJsonPath('estado', 'primeiro_acesso');
    }

    /**
     * O estado que explica RN48 a quem já trabalha no sistema: há registro, e
     * ele continua sob a guarda do prestador (RN40); o que não há é autorização
     * vigente, e é isso que esvazia o painel.
     */
    public function test_prestador_com_registro_e_sem_autorizacao_vigente_explica_a_regra(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);

        $animal = Animal::factory()->create();
        $this->atendimento($animal, $clinica, $marcelo);

        $this->actingAs($marcelo)->getJson('/api/clinica/painel')
            ->assertJsonPath('estado', 'sem_autorizacoes')
            ->assertJsonPath('animais_atendidos.itens', []);
    }

    public function test_a_lista_de_atendidos_e_paginada_do_registro_mais_recente_ao_mais_antigo(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);

        foreach (range(1, 6) as $indice) {
            $animal = $this->animalAutorizado($clinica, "Animal {$indice}");
            $this->atendimento($animal, $clinica, $marcelo, [
                'atendido_em' => now()->subDays($indice),
            ]);
        }

        $primeira = $this->actingAs($marcelo)->getJson('/api/clinica/painel');

        $primeira->assertJsonCount(5, 'animais_atendidos.itens');
        $primeira->assertJsonPath('animais_atendidos.itens.0.nome', 'Animal 1');
        $primeira->assertJsonPath('animais_atendidos.paginacao.total', 6);
        $primeira->assertJsonPath('animais_atendidos.paginacao.paginas', 2);

        $segunda = $this->actingAs($marcelo)->getJson('/api/clinica/painel?pagina=2');

        $segunda->assertJsonCount(1, 'animais_atendidos.itens');
        $segunda->assertJsonPath('animais_atendidos.itens.0.nome', 'Animal 6');
        $segunda->assertJsonPath('animais_atendidos.paginacao.de', 6);
    }
}
