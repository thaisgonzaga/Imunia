<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Imunobiologico;
use App\Models\ProtocoloVacinal;
use App\Models\Tutor;
use App\Models\User;
use App\Models\Vacinacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AnimalControllerTest extends TestCase
{
    use RefreshDatabase;

    private function helena(): Tutor
    {
        $usuario = User::factory()->create([
            'name' => 'Helena Ramos',
            'email' => 'helena.ramos@example.com',
        ]);

        return Tutor::factory()->create([
            'user_id' => $usuario->id,
            'nome' => 'Helena Ramos',
        ]);
    }

    /**
     * Uma vacinação aplicada — o registro clínico que trava a espécie (RF19d).
     *
     * O imunobiológico e o protocolo são criados aqui de propósito:
     * `VacinacaoFactory` chega a `Imunobiologico::factory()` por dois caminhos
     * (direto e através do protocolo) e a chave da tabela é única, de modo que
     * deixá-la resolver sozinha estoura já na primeira vacinação.
     */
    private function vacinacaoDe(Animal $animal): void
    {
        $imunobiologico = Imunobiologico::factory()->create();

        Vacinacao::factory()->create([
            'animal_id' => $animal->id,
            'imunobiologico_id' => $imunobiologico->id,
            'protocolo_vacinal_id' => ProtocoloVacinal::factory()
                ->create(['imunobiologico_id' => $imunobiologico->id])->id,
        ]);
    }

    public function test_a_relacao_de_animais_exige_sessao(): void
    {
        $this->getJson('/api/animais')->assertUnauthorized();
    }

    public function test_usuario_sem_cadastro_de_tutor_nao_alcanca_a_relacao(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)
            ->getJson('/api/animais')
            ->assertForbidden();
    }

    public function test_a_relacao_traz_os_animais_do_tutor_em_ordem_de_nome(): void
    {
        $tutor = $this->helena();

        Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Théo']);
        Animal::factory()->gato()->create(['tutor_id' => $tutor->id, 'nome' => 'Nina']);

        $resposta = $this->actingAs($tutor->user)->getJson('/api/animais');

        $resposta->assertOk();
        $resposta->assertJsonCount(2, 'animais');
        $resposta->assertJsonPath('animais.0.nome', 'Nina');
        $resposta->assertJsonPath('animais.1.nome', 'Théo');
    }

    public function test_a_relacao_nao_enxerga_animal_de_outro_tutor(): void
    {
        $helena = $this->helena();
        $outro = Tutor::factory()->create(['nome' => 'Marcos Lima']);

        Animal::factory()->create(['tutor_id' => $helena->id, 'nome' => 'Théo']);
        Animal::factory()->create(['tutor_id' => $outro->id, 'nome' => 'Bidu']);

        $resposta = $this->actingAs($helena->user)->getJson('/api/animais');

        $resposta->assertJsonCount(1, 'animais');
        $resposta->assertJsonPath('animais.0.nome', 'Théo');
    }

    public function test_tutor_sem_animais_recebe_relacao_vazia(): void
    {
        $tutor = $this->helena();

        $this->actingAs($tutor->user)
            ->getJson('/api/animais')
            ->assertOk()
            ->assertJsonPath('animais', []);
    }

    public function test_cada_animal_informa_se_o_cadastro_ainda_e_preliminar(): void
    {
        $tutor = $this->helena();

        Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Nina']);
        Animal::factory()->caracterizado()->create(['tutor_id' => $tutor->id, 'nome' => 'Théo']);

        $resposta = $this->actingAs($tutor->user)->getJson('/api/animais');

        $resposta->assertJsonPath('animais.0.preliminar', true);  // Nina — RN17
        $resposta->assertJsonPath('animais.1.preliminar', false); // Théo — RF19
    }

    // T03 — cadastrar animal --------------------------------------------------

    public function test_o_tutor_envia_a_fotografia_do_animal(): void
    {
        Storage::fake(Animal::DISCO_DA_FOTO);

        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id]);

        $resposta = $this->actingAs($tutor->user)->postJson(
            "/api/animais/{$animal->codigo}/foto",
            ['arquivo' => UploadedFile::fake()->image('theo.jpg')],
        );

        $resposta->assertOk();
        $this->assertNotNull($resposta->json('foto_url'));

        Storage::disk(Animal::DISCO_DA_FOTO)->assertExists($animal->fresh()->foto_caminho);
    }

    public function test_a_fotografia_em_formato_recusado_nao_entra(): void
    {
        Storage::fake(Animal::DISCO_DA_FOTO);

        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id]);

        $this->actingAs($tutor->user)
            ->postJson(
                "/api/animais/{$animal->codigo}/foto",
                ['arquivo' => UploadedFile::fake()->create('carteirinha.pdf', 40, 'application/pdf')],
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('arquivo');

        $this->assertNull($animal->fresh()->foto_caminho);
    }

    public function test_a_fotografia_acima_do_limite_de_tamanho_e_recusada(): void
    {
        Storage::fake(Animal::DISCO_DA_FOTO);

        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id]);

        $this->actingAs($tutor->user)
            ->postJson("/api/animais/{$animal->codigo}/foto", [
                'arquivo' => UploadedFile::fake()
                    ->image('enorme.jpg')
                    ->size(Animal::TAMANHO_MAXIMO_DA_FOTO_KB + 1),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('arquivo');
    }

    // RN20 — a fotografia pode ser substituída pelo tutor a qualquer tempo.
    public function test_a_fotografia_substituida_sai_do_disco(): void
    {
        Storage::fake(Animal::DISCO_DA_FOTO);

        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id]);

        $this->actingAs($tutor->user)->postJson(
            "/api/animais/{$animal->codigo}/foto",
            ['arquivo' => UploadedFile::fake()->image('antiga.jpg')],
        );

        $primeira = $animal->fresh()->foto_caminho;

        $this->actingAs($tutor->user)->postJson(
            "/api/animais/{$animal->codigo}/foto",
            ['arquivo' => UploadedFile::fake()->image('nova.jpg')],
        )->assertOk();

        $segunda = $animal->fresh()->foto_caminho;

        $this->assertNotSame($primeira, $segunda);
        Storage::disk(Animal::DISCO_DA_FOTO)->assertMissing($primeira);
        Storage::disk(Animal::DISCO_DA_FOTO)->assertExists($segunda);
    }

    public function test_nao_se_envia_fotografia_para_animal_de_outro_tutor(): void
    {
        Storage::fake(Animal::DISCO_DA_FOTO);

        $helena = $this->helena();
        $outro = Tutor::factory()->create(['nome' => 'Marcos Lima']);
        $animal = Animal::factory()->create(['tutor_id' => $outro->id]);

        $this->actingAs($helena->user)
            ->postJson(
                "/api/animais/{$animal->codigo}/foto",
                ['arquivo' => UploadedFile::fake()->image('theo.jpg')],
            )
            ->assertNotFound();
    }

    // T04 — perfil do animal --------------------------------------------------

    public function test_o_perfil_do_animal_exige_sessao(): void
    {
        $this->getJson('/api/animais/IM-7F3K-92QD')->assertUnauthorized();
    }

    public function test_usuario_sem_cadastro_de_tutor_nao_alcanca_o_perfil(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)
            ->getJson('/api/animais/IM-7F3K-92QD')
            ->assertForbidden();
    }

    public function test_codigo_inexistente_responde_404(): void
    {
        $tutor = $this->helena();

        $this->actingAs($tutor->user)
            ->getJson('/api/animais/IM-0000-0000')
            ->assertNotFound();
    }

    // RN12 — a existência de um cadastro alheio não pode ser distinguível de
    // um código que nunca existiu: as duas respostas precisam ser o mesmo 404.
    public function test_animal_de_outro_tutor_responde_404_como_se_nao_existisse(): void
    {
        $helena = $this->helena();
        $outro = Tutor::factory()->create(['nome' => 'Marcos Lima']);

        $animal = Animal::factory()->create(['tutor_id' => $outro->id, 'nome' => 'Bidu']);

        $this->actingAs($helena->user)
            ->getJson("/api/animais/{$animal->codigo}")
            ->assertNotFound();
    }

    public function test_o_perfil_traz_o_codigo_e_a_identificacao_do_animal(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Théo']);

        $resposta = $this->actingAs($tutor->user)->getJson("/api/animais/{$animal->codigo}");

        $resposta->assertOk();
        $resposta->assertJsonPath('codigo', $animal->codigo);
        $resposta->assertJsonPath('nome', 'Théo');
        $resposta->assertJsonPath('especie', 'cao');
    }

    // RF19/RN18 — a caracterização é privativa do veterinário e ainda não tem
    // fatia própria: o perfil precisa devolver o vazio, não inventar dado.
    public function test_a_caracterizacao_vem_vazia_ate_a_fatia_do_veterinario_existir(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id]);

        $this->actingAs($tutor->user)
            ->getJson("/api/animais/{$animal->codigo}")
            ->assertJsonPath('caracterizacao', null);
    }

    public function test_sem_vacinacao_registrada_a_situacao_vacinal_nao_afirma_que_esta_em_dia(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id]);

        $this->actingAs($tutor->user)
            ->getJson("/api/animais/{$animal->codigo}")
            ->assertJsonPath('situacao_vacinal', 'sem_registros');
    }

    // T04a — editar a identificação do animal ---------------------------------

    public function test_a_edicao_do_animal_exige_sessao(): void
    {
        $this->patchJson('/api/animais/IM-7F3K-92QD', ['nome' => 'Théo', 'especie' => 'cao'])
            ->assertUnauthorized();
    }

    public function test_usuario_sem_cadastro_de_tutor_nao_edita_animal(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)
            ->patchJson('/api/animais/IM-7F3K-92QD', ['nome' => 'Théo', 'especie' => 'cao'])
            ->assertForbidden();
    }

    // RN12 — a mesma resposta do perfil: o cadastro alheio não se distingue do
    // código que nunca existiu.
    public function test_nao_se_edita_animal_de_outro_tutor(): void
    {
        $helena = $this->helena();
        $outro = Tutor::factory()->create(['nome' => 'Marcos Lima']);
        $animal = Animal::factory()->create(['tutor_id' => $outro->id, 'nome' => 'Bidu']);

        $this->actingAs($helena->user)
            ->patchJson("/api/animais/{$animal->codigo}", ['nome' => 'Outro', 'especie' => 'cao'])
            ->assertNotFound();

        $this->assertSame('Bidu', $animal->fresh()->nome);
    }

    public function test_o_tutor_corrige_o_nome_do_animal(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Teo']);

        $resposta = $this->actingAs($tutor->user)
            ->patchJson("/api/animais/{$animal->codigo}", ['nome' => 'Théo', 'especie' => 'cao']);

        $resposta->assertOk();
        $resposta->assertJsonPath('nome', 'Théo');
        $this->assertSame('Théo', $animal->fresh()->nome);
    }

    // RF17b — o código não muda em circunstância alguma prevista no sistema, e
    // a edição não é exceção.
    public function test_a_edicao_nao_mexe_no_codigo_do_animal(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id]);
        $codigo = $animal->codigo;

        $this->actingAs($tutor->user)
            ->patchJson("/api/animais/{$codigo}", [
                'nome' => 'Théo',
                'especie' => 'cao',
                'codigo' => 'IM-0000-0000',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('codigo');

        $this->assertSame($codigo, $animal->fresh()->codigo);
    }

    public function test_a_edicao_recusa_campo_privativo_do_veterinario(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Théo']);

        $this->actingAs($tutor->user)
            ->patchJson("/api/animais/{$animal->codigo}", [
                'nome' => 'Théo',
                'especie' => 'cao',
                'raca' => 'Border Collie',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('raca');

        $this->assertNull($animal->fresh()->raca);
    }

    // RN14 — o tutor pode informar a data; quem a promove a exata é o
    // veterinário. A edição não é um segundo caminho para isso.
    public function test_a_edicao_recusa_a_promocao_do_nascimento_a_exato(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id]);

        $this->actingAs($tutor->user)
            ->patchJson("/api/animais/{$animal->codigo}", [
                'nome' => 'Théo',
                'especie' => 'cao',
                'nascimento_exato' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('nascimento_exato');

        $this->assertFalse($animal->fresh()->nascimento_exato);
    }

    // RN17, RF16 — enquanto o cadastro é preliminar, o declarado é do tutor.
    public function test_enquanto_preliminar_o_tutor_corrige_sexo_e_nascimento(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create([
            'tutor_id' => $tutor->id,
            'sexo' => 'macho',
            'nascimento_em' => '2023-01-01',
        ]);

        $this->actingAs($tutor->user)
            ->patchJson("/api/animais/{$animal->codigo}", [
                'nome' => 'Nina',
                'especie' => 'gato',
                'sexo' => 'femea',
                'nascimento' => '06/2024',
            ])
            ->assertOk();

        $atualizado = $animal->fresh();

        $this->assertSame('femea', $atualizado->sexo);
        $this->assertSame('2024-06-01', $atualizado->nascimento_em->toDateString());
        $this->assertFalse($atualizado->nascimento_exato);
    }

    public function test_o_tutor_apaga_o_nascimento_que_havia_declarado(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create([
            'tutor_id' => $tutor->id,
            'nascimento_em' => '2023-01-01',
        ]);

        $this->actingAs($tutor->user)
            ->patchJson("/api/animais/{$animal->codigo}", [
                'nome' => 'Théo',
                'especie' => 'cao',
                'sexo' => null,
                'nascimento' => '',
            ])
            ->assertOk();

        $atualizado = $animal->fresh();

        $this->assertNull($atualizado->nascimento_em);
        $this->assertNull($atualizado->sexo);
    }

    public function test_o_nascimento_declarado_na_edicao_nao_pode_ser_futuro(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id]);

        $this->actingAs($tutor->user)
            ->patchJson("/api/animais/{$animal->codigo}", [
                'nome' => 'Théo',
                'especie' => 'cao',
                'nascimento' => now()->addYear()->format('m/Y'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('nascimento');
    }

    // RF19b, RN18 — confirmado pelo veterinário, o declarado deixa de ser do
    // tutor: reescrevê-lo aqui desfaria a confirmação profissional.
    public function test_depois_da_caracterizacao_o_tutor_nao_reescreve_sexo_nem_nascimento(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->caracterizado()->create([
            'tutor_id' => $tutor->id,
            'sexo' => 'macho',
        ]);

        $this->actingAs($tutor->user)
            ->patchJson("/api/animais/{$animal->codigo}", [
                'nome' => 'Théo',
                'especie' => 'cao',
                'sexo' => 'femea',
                'nascimento' => '06/2024',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sexo', 'nascimento']);

        $this->assertSame('macho', $animal->fresh()->sexo);
    }

    // O corpo que a tela envia quando o bloco já não é dela: as chaves chegam
    // nulas, e nulo não é tentativa de escrita.
    public function test_depois_da_caracterizacao_o_nome_continua_editavel(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->caracterizado()->create([
            'tutor_id' => $tutor->id,
            'nome' => 'Teo',
        ]);

        $this->actingAs($tutor->user)
            ->patchJson("/api/animais/{$animal->codigo}", [
                'nome' => 'Théo',
                'especie' => 'cao',
                'sexo' => null,
                'nascimento' => null,
            ])
            ->assertOk();

        $this->assertSame('Théo', $animal->fresh()->nome);
    }

    public function test_o_tutor_corrige_a_especie_enquanto_nao_ha_registro_clinico(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id, 'especie' => 'cao']);

        $this->actingAs($tutor->user)
            ->patchJson("/api/animais/{$animal->codigo}", ['nome' => 'Nina', 'especie' => 'gato'])
            ->assertOk()
            ->assertJsonPath('especie', 'gato');

        $this->assertSame('gato', $animal->fresh()->especie);
    }

    // RF19d — a espécie não é alterável depois do primeiro registro clínico:
    // protocolo, dose e calendário foram calculados para a que constava ali.
    public function test_a_especie_nao_muda_depois_do_primeiro_registro_clinico(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id, 'especie' => 'cao']);

        $this->vacinacaoDe($animal);

        $this->actingAs($tutor->user)
            ->patchJson("/api/animais/{$animal->codigo}", ['nome' => 'Nina', 'especie' => 'gato'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('especie');

        $this->assertSame('cao', $animal->fresh()->especie);
    }

    // A trava é da troca, não do campo: quem só corrige o nome continua
    // enviando a espécie, e o pedido passa.
    public function test_com_registro_clinico_o_nome_continua_editavel_com_a_mesma_especie(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create([
            'tutor_id' => $tutor->id,
            'nome' => 'Teo',
            'especie' => 'cao',
        ]);

        $this->vacinacaoDe($animal);

        $this->actingAs($tutor->user)
            ->patchJson("/api/animais/{$animal->codigo}", ['nome' => 'Théo', 'especie' => 'cao'])
            ->assertOk();

        $this->assertSame('Théo', $animal->fresh()->nome);
    }

    public function test_o_perfil_informa_se_a_especie_ainda_pode_ser_corrigida(): void
    {
        $tutor = $this->helena();
        $semRegistro = Animal::factory()->create(['tutor_id' => $tutor->id]);
        $comRegistro = Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Bidu']);

        $this->vacinacaoDe($comRegistro);

        $this->actingAs($tutor->user)
            ->getJson("/api/animais/{$semRegistro->codigo}")
            ->assertJsonPath('especie_alteravel', true);

        $this->actingAs($tutor->user)
            ->getJson("/api/animais/{$comRegistro->codigo}")
            ->assertJsonPath('especie_alteravel', false);
    }

    // RF22, RN27 — o óbito é registro de veterinário como os demais, e trava a
    // espécie ainda que não haja vacinação nem atendimento.
    public function test_o_obito_registrado_tambem_trava_a_especie(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id, 'especie' => 'cao']);
        $animal->forceFill(['obito_em' => now()->subMonth()])->save();

        $this->actingAs($tutor->user)
            ->patchJson("/api/animais/{$animal->codigo}", ['nome' => 'Théo', 'especie' => 'gato'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('especie');
    }

    // O formulário de edição relê o que o tutor declarou, e não só a idade
    // derivada dele.
    public function test_o_perfil_devolve_o_declarado_no_formato_que_o_campo_aceita(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create([
            'tutor_id' => $tutor->id,
            'sexo' => 'femea',
            'nascimento_em' => '2024-06-01',
        ]);

        $this->actingAs($tutor->user)
            ->getJson("/api/animais/{$animal->codigo}")
            ->assertJsonPath('sexo', 'femea')
            ->assertJsonPath('nascimento', '06/2024');
    }

    // RN20 — manter a fotografia a qualquer tempo inclui retirá-la.
    public function test_o_tutor_remove_a_fotografia(): void
    {
        Storage::fake(Animal::DISCO_DA_FOTO);

        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id]);

        $this->actingAs($tutor->user)->postJson(
            "/api/animais/{$animal->codigo}/foto",
            ['arquivo' => UploadedFile::fake()->image('theo.jpg')],
        );

        $caminho = $animal->fresh()->foto_caminho;

        $this->actingAs($tutor->user)
            ->deleteJson("/api/animais/{$animal->codigo}/foto")
            ->assertOk()
            ->assertJsonPath('foto_url', null);

        $this->assertNull($animal->fresh()->foto_caminho);
        Storage::disk(Animal::DISCO_DA_FOTO)->assertMissing($caminho);
    }

    public function test_remover_fotografia_de_animal_sem_foto_nao_e_erro(): void
    {
        Storage::fake(Animal::DISCO_DA_FOTO);

        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id]);

        $this->actingAs($tutor->user)
            ->deleteJson("/api/animais/{$animal->codigo}/foto")
            ->assertOk()
            ->assertJsonPath('foto_url', null);
    }

    public function test_nao_se_remove_a_fotografia_de_animal_de_outro_tutor(): void
    {
        Storage::fake(Animal::DISCO_DA_FOTO);

        $helena = $this->helena();
        $outro = Tutor::factory()->create(['nome' => 'Marcos Lima']);
        $animal = Animal::factory()->create(['tutor_id' => $outro->id]);

        $this->actingAs($helena->user)->postJson(
            "/api/animais/{$animal->codigo}/foto",
            ['arquivo' => UploadedFile::fake()->image('bidu.jpg')],
        );

        $this->actingAs($helena->user)
            ->deleteJson("/api/animais/{$animal->codigo}/foto")
            ->assertNotFound();
    }
}
