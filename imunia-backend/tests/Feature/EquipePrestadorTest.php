<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Autorizacao;
use App\Models\Convite;
use App\Models\Prestador;
use App\Models\RegistroDeAcesso;
use App\Models\Tutor;
use App\Models\User;
use App\Notifications\AutorizacaoRevogada;
use App\Notifications\ConviteDeAtivacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A03 — equipe do prestador (RF09, RF10).
 */
class EquipePrestadorTest extends TestCase
{
    use RefreshDatabase;

    private function clinica(array $atributos = []): Prestador
    {
        return Prestador::factory()->create(['cnpj' => '11222333000181', ...$atributos]);
    }

    private function administradora(Prestador ...$prestadores): User
    {
        $usuario = User::factory()->create(['name' => 'Ana Lúcia Ferraz']);

        foreach ($prestadores as $prestador) {
            $usuario->prestadores()->attach($prestador, ['papel' => 'admin_prestador']);
        }

        return $usuario;
    }

    private function veterinario(Prestador $prestador, string $nome, string $crmv, array $pivo = []): User
    {
        $usuario = User::factory()->create(['name' => $nome]);

        $usuario->prestadores()->attach($prestador, [
            'papel' => 'veterinario',
            'crmv' => $crmv,
            'crmv_uf' => 'MG',
            ...$pivo,
        ]);

        return $usuario;
    }

    private function vinculoDe(User $administradora, User $membro): int
    {
        $resposta = $this->actingAs($administradora)->getJson('/api/prestador/equipe')->assertOk();

        return collect($resposta->json('membros'))->firstWhere('email', $membro->email)['vinculo'];
    }

    // ---------------------------------------------------------------- leitura

    public function test_a_equipe_exige_sessao_e_vinculo(): void
    {
        $this->getJson('/api/prestador/equipe')->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/prestador/equipe')
            ->assertForbidden();
    }

    /**
     * Quem atende aqui vê com quem trabalha — e nada mais. Sem ações sobre
     * linha alguma, sem convite pendente (endereço que ainda não virou colega)
     * e sem vínculo encerrado, que está na lista do administrador porque RF10
     * pede que a saída fique registrada.
     */
    public function test_veterinario_sem_papel_administrativo_ve_a_equipe_sem_agir_sobre_ela(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->veterinario($clinica, 'Marcelo Andrade', '12345');
        $this->veterinario($clinica, 'Beatriz Salles', '20981');
        $this->veterinario($clinica, 'Henrique Vaz', '30877', ['encerrado_em' => Carbon::now()->subMonth()]);

        $resposta = $this->actingAs($marcelo)
            ->getJson('/api/prestador/equipe')
            ->assertOk()
            ->assertJsonPath('pode_administrar', false)
            ->assertJsonCount(2, 'membros');

        foreach ($resposta->json('membros') as $membro) {
            $this->assertFalse($membro['pode_encerrar']);
            $this->assertFalse($membro['pode_reenviar']);
            $this->assertFalse($membro['pode_conceder_administracao']);
            $this->assertFalse($membro['pode_revogar_administracao']);
            $this->assertNull($membro['impedimento']);
        }

        // Marcelo é o responsável técnico da clínica, e ainda assim não concede:
        // conceder é ato administrativo, e ele não administra a conta.
        $resposta->assertJsonPath('concessao.pode_conceder', false);
    }

    /**
     * A quem pedir — inclusive quando quem administra não é veterinário e por
     * isso não figura na tabela da equipe.
     */
    public function test_a_tela_diz_quem_administra_a_conta_mesmo_sem_crmv(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->veterinario($clinica, 'Marcelo Andrade', '12345');
        $this->administradora($clinica);

        $this->actingAs($marcelo)
            ->getJson('/api/prestador/equipe')
            ->assertOk()
            ->assertJsonPath('administrada_por', ['Ana Lúcia Ferraz']);
    }

    public function test_a_lista_traz_as_situacoes_com_texto_pronto(): void
    {
        $clinica = $this->clinica();
        $administradora = $this->administradora($clinica);

        $this->veterinario($clinica, 'Marcelo Andrade', '12345');
        $this->veterinario($clinica, 'Larissa Nogueira', '20981');
        $this->veterinario($clinica, 'Henrique Vaz', '09112', [
            'encerrado_em' => Carbon::parse('2026-05-14'),
        ]);

        $resposta = $this->actingAs($administradora)->getJson('/api/prestador/equipe')->assertOk();
        $membros = collect($resposta->json('membros'))->keyBy('nome');

        // O CRMV do responsável técnico bate com o do prestador, e é assim que
        // ele é reconhecido — não pelo nome (RN09).
        $this->assertSame('ativo · resp. técnico', $membros['Marcelo Andrade']['situacao_texto']);
        $this->assertSame('ativo', $membros['Larissa Nogueira']['situacao_texto']);
        $this->assertSame('encerrado em 14/05/2026', $membros['Henrique Vaz']['situacao_texto']);
        $this->assertSame('CRMV-MG 20981', $membros['Larissa Nogueira']['crmv']);
    }

    /** RF10 — a linha encerrada permanece: é a prova de que a autoria não sumiu. */
    public function test_o_vinculo_encerrado_permanece_na_lista_com_a_data(): void
    {
        $clinica = $this->clinica();
        $administradora = $this->administradora($clinica);
        $this->veterinario($clinica, 'Henrique Vaz', '09112', ['encerrado_em' => Carbon::now()]);

        $this->actingAs($administradora)
            ->getJson('/api/prestador/equipe')
            ->assertOk()
            ->assertJsonCount(1, 'membros')
            ->assertJsonPath('membros.0.situacao', 'encerrado')
            ->assertJsonPath('membros.0.pode_encerrar', false)
            ->assertJsonPath('contagens.encerrados', 1);
    }

    public function test_equipe_sem_veterinario_devolve_lista_vazia(): void
    {
        $clinica = $this->clinica();

        $this->actingAs($this->administradora($clinica))
            ->getJson('/api/prestador/equipe')
            ->assertOk()
            ->assertJsonCount(0, 'membros');
    }

    public function test_a_lista_nao_mistura_equipe_de_outro_prestador(): void
    {
        $clinica = $this->clinica();
        $outra = $this->clinica(['nome' => 'Pet Center', 'cnpj' => '11444777000161']);

        $this->veterinario($clinica, 'Marcelo Andrade', '12345');
        $this->veterinario($outra, 'Beatriz Salles', '18220');

        $this->actingAs($this->administradora($clinica))
            ->getJson('/api/prestador/equipe')
            ->assertOk()
            ->assertJsonCount(1, 'membros')
            ->assertJsonPath('membros.0.nome', 'Marcelo Andrade');
    }

    // ---------------------------------------------------------------- convite

    /** RF09 — o convite cria conta, vínculo com CRMV e a ligação de ativação. */
    public function test_convidar_veterinario_cria_conta_vinculo_e_envia_convite(): void
    {
        Notification::fake();

        $clinica = $this->clinica();
        $administradora = $this->administradora($clinica);

        $this->actingAs($administradora)
            ->postJson('/api/prestador/equipe', [
                'email' => 'beatriz@vetamigo.com.br',
                'crmv' => '18220',
                'crmv_uf' => 'MG',
            ])
            ->assertCreated()
            ->assertJsonPath('contagens.convites_pendentes', 1);

        $convidada = User::query()->where('email', 'beatriz@vetamigo.com.br')->firstOrFail();

        // A conta nasce sem nome e sem ativação: os dois são do titular, e ele
        // os define no primeiro acesso.
        $this->assertSame('', $convidada->name);
        $this->assertNull($convidada->ativado_em);

        $this->assertDatabaseHas('prestador_usuario', [
            'prestador_id' => $clinica->id,
            'user_id' => $convidada->id,
            'papel' => 'veterinario',
            'crmv' => '18220',
            'crmv_uf' => 'MG',
        ]);

        Notification::assertSentTo($convidada, ConviteDeAtivacao::class);
    }

    /** RN09 — a inscrição é por conselho regional, e mora no vínculo. */
    public function test_o_crmv_fica_no_vinculo_e_nao_no_usuario(): void
    {
        $clinica = $this->clinica();

        $this->actingAs($this->administradora($clinica))
            ->postJson('/api/prestador/equipe', [
                'email' => 'beatriz@vetamigo.com.br',
                'crmv' => '18220',
                'crmv_uf' => 'MG',
            ])
            ->assertCreated();

        $this->assertFalse(
            in_array('crmv', array_keys(User::query()->where('email', 'beatriz@vetamigo.com.br')->firstOrFail()->getAttributes()), true),
        );
    }

    public function test_o_convite_aparece_na_lista_identificado_pelo_endereco(): void
    {
        $clinica = $this->clinica();
        $administradora = $this->administradora($clinica);

        $this->actingAs($administradora)->postJson('/api/prestador/equipe', [
            'email' => 'beatriz@vetamigo.com.br',
            'crmv' => '18220',
            'crmv_uf' => 'MG',
        ])->assertCreated();

        $resposta = $this->actingAs($administradora)->getJson('/api/prestador/equipe')->assertOk();
        $membro = $resposta->json('membros.0');

        $this->assertNull($membro['nome']);
        $this->assertSame('beatriz@vetamigo.com.br', $membro['email']);
        $this->assertSame('convite · expira em 7 d', $membro['situacao_texto']);
        $this->assertTrue($membro['pode_reenviar']);
    }

    public function test_convidar_quem_ja_esta_na_equipe_e_rejeitado(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->veterinario($clinica, 'Marcelo Andrade', '12345');

        $this->actingAs($this->administradora($clinica))
            ->postJson('/api/prestador/equipe', [
                'email' => $marcelo->email,
                'crmv' => '12345',
                'crmv_uf' => 'MG',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_convidar_quem_ja_tem_convite_pendente_e_rejeitado(): void
    {
        $clinica = $this->clinica();
        $administradora = $this->administradora($clinica);
        $dados = ['email' => 'beatriz@vetamigo.com.br', 'crmv' => '18220', 'crmv_uf' => 'MG'];

        $this->actingAs($administradora)->postJson('/api/prestador/equipe', $dados)->assertCreated();

        $this->actingAs($administradora)
            ->postJson('/api/prestador/equipe', $dados)
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    /**
     * RF10 — reconvidar não apaga a história: o vínculo encerrado continua na
     * lista, e o novo nasce ao lado dele.
     */
    public function test_convidar_quem_teve_vinculo_encerrado_abre_vinculo_novo_e_preserva_o_encerrado(): void
    {
        $clinica = $this->clinica();
        $administradora = $this->administradora($clinica);
        $henrique = $this->veterinario($clinica, 'Henrique Vaz', '09112', [
            'encerrado_em' => Carbon::now()->subMonth(),
        ]);

        $this->actingAs($administradora)
            ->postJson('/api/prestador/equipe', [
                'email' => $henrique->email,
                'crmv' => '09112',
                'crmv_uf' => 'MG',
            ])
            ->assertCreated();

        $this->actingAs($administradora)
            ->getJson('/api/prestador/equipe')
            ->assertJsonCount(2, 'membros')
            ->assertJsonPath('contagens.encerrados', 1)
            ->assertJsonPath('contagens.convites_pendentes', 1);
    }

    /** RF09 — vínculo simultâneo com mais de um prestador é previsto. */
    public function test_veterinario_de_outra_clinica_pode_ser_convidado(): void
    {
        $petCenter = $this->clinica(['nome' => 'Pet Center', 'cnpj' => '11444777000161']);
        $beatriz = $this->veterinario($petCenter, 'Beatriz Salles', '18220');

        $vetAmigo = $this->clinica();

        $this->actingAs($this->administradora($vetAmigo))
            ->postJson('/api/prestador/equipe', [
                'email' => $beatriz->email,
                'crmv' => '18220',
                'crmv_uf' => 'MG',
            ])
            ->assertCreated();

        $this->assertSame(2, $beatriz->fresh()->prestadores()->count());
    }

    public function test_convite_sem_crmv_e_rejeitado(): void
    {
        $clinica = $this->clinica();

        $this->actingAs($this->administradora($clinica))
            ->postJson('/api/prestador/equipe', ['email' => 'beatriz@vetamigo.com.br', 'crmv_uf' => 'MG'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('crmv');
    }

    /** O CRMV guarda só o número: prefixo e UF têm campo próprio. */
    public function test_convite_com_crmv_fora_do_formato_numerico_e_rejeitado(): void
    {
        $clinica = $this->clinica();

        $this->actingAs($this->administradora($clinica))
            ->postJson('/api/prestador/equipe', [
                'email' => 'beatriz@vetamigo.com.br',
                'crmv' => 'CRMV-MG 18220',
                'crmv_uf' => 'MG',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('crmv');
    }

    /** RF14a — o prazo recomeça e o token anterior deixa de valer. */
    public function test_reenviar_renova_o_prazo_e_invalida_o_token_anterior(): void
    {
        Notification::fake();

        $clinica = $this->clinica();
        $administradora = $this->administradora($clinica);

        $this->actingAs($administradora)->postJson('/api/prestador/equipe', [
            'email' => 'beatriz@vetamigo.com.br',
            'crmv' => '18220',
            'crmv_uf' => 'MG',
        ])->assertCreated();

        $convidada = User::query()->where('email', 'beatriz@vetamigo.com.br')->firstOrFail();
        $tokenAntigo = Convite::query()->where('user_id', $convidada->id)->firstOrFail()->token;

        $this->actingAs($administradora)
            ->postJson('/api/prestador/equipe/'.$this->vinculoDe($administradora, $convidada).'/reenviar')
            ->assertStatus(202);

        $this->assertNotSame($tokenAntigo, Convite::query()->where('user_id', $convidada->id)->firstOrFail()->token);
    }

    public function test_reenviar_para_quem_nao_tem_convite_pendente_e_recusado(): void
    {
        $clinica = $this->clinica();
        $administradora = $this->administradora($clinica);
        $marcelo = $this->veterinario($clinica, 'Marcelo Andrade', '12345');

        $this->actingAs($administradora)
            ->postJson('/api/prestador/equipe/'.$this->vinculoDe($administradora, $marcelo).'/reenviar')
            ->assertStatus(422);
    }

    // ----------------------------------------------------------- encerramento

    public function test_encerrar_vinculo_carimba_a_data_e_nao_apaga_a_linha(): void
    {
        $clinica = $this->clinica();
        $administradora = $this->administradora($clinica);
        $larissa = $this->veterinario($clinica, 'Larissa Nogueira', '20981');

        $this->actingAs($administradora)
            ->deleteJson('/api/prestador/equipe/'.$this->vinculoDe($administradora, $larissa))
            ->assertOk()
            ->assertJsonPath('contagens.encerrados', 1)
            ->assertJsonPath('contagens.ativos', 0);

        $this->assertDatabaseHas('prestador_usuario', [
            'prestador_id' => $clinica->id,
            'user_id' => $larissa->id,
        ]);

        $this->assertNotNull(
            $larissa->fresh()->prestadores()->first()->pivot->encerrado_em,
        );
    }

    /** RF10a — o teste central: encerrado o vínculo, o ambiente clínico fecha. */
    public function test_apos_o_encerramento_o_profissional_nao_alcanca_o_ambiente_clinico(): void
    {
        $clinica = $this->clinica();
        $administradora = $this->administradora($clinica);
        $larissa = $this->veterinario($clinica, 'Larissa Nogueira', '20981');

        $this->actingAs($larissa)->getJson('/api/clinica/painel')->assertOk();

        $this->actingAs($administradora)
            ->deleteJson('/api/prestador/equipe/'.$this->vinculoDe($administradora, $larissa))
            ->assertOk();

        $this->actingAs($larissa->fresh())->getJson('/api/clinica/painel')->assertForbidden();
    }

    /**
     * RF10b e RF10c — o teste que sustenta a frase que A03 exibe na tela:
     * encerrar vínculo remove o acesso, não a autoria. O livro de acessos de
     * T14 resolve o CRMV do autor ao vivo, e continua exibindo-o.
     */
    public function test_apos_o_encerramento_os_registros_continuam_exibindo_nome_e_crmv_do_autor(): void
    {
        $clinica = $this->clinica();
        $administradora = $this->administradora($clinica);
        $larissa = $this->veterinario($clinica, 'Larissa Nogueira', '20981');

        $tutor = Tutor::factory()->create(['nome' => 'Helena Ramos']);
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Théo']);
        Autorizacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $clinica->id,
            'concedida_por_user_id' => $tutor->user_id,
        ]);

        RegistroDeAcesso::factory()->create([
            'prestador_id' => $clinica->id,
            'user_id' => $larissa->id,
            'tutor_id' => $tutor->id,
            'animal_id' => $animal->id,
            'natureza' => RegistroDeAcesso::BUSCA_POR_CODIGO,
            'ocorrido_em' => Carbon::now()->subDay(),
        ]);

        $this->actingAs($administradora)
            ->deleteJson('/api/prestador/equipe/'.$this->vinculoDe($administradora, $larissa))
            ->assertOk();

        $resposta = $this->actingAs($tutor->user)->getJson('/api/acessos')->assertOk();

        $this->assertStringContainsString(
            'CRMV-MG 20981',
            json_encode($resposta->json(), JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * RF10a — quem fundou a clínica em P03 tem duas linhas no pivô. Encerrar só
     * a de veterinário deixaria a pessoa administrando a conta de que acabou de
     * ser desligada.
     */
    public function test_encerrar_encerra_todos_os_papeis_da_mesma_pessoa_no_prestador(): void
    {
        $clinica = $this->clinica();
        $administradora = $this->administradora($clinica);

        $fundador = User::factory()->create(['name' => 'Marcelo Andrade']);
        $fundador->prestadores()->attach($clinica, ['papel' => 'admin_prestador']);
        $fundador->prestadores()->attach($clinica, [
            'papel' => 'veterinario',
            'crmv' => '77777',
            'crmv_uf' => 'MG',
        ]);

        $this->actingAs($administradora)
            ->deleteJson('/api/prestador/equipe/'.$this->vinculoDe($administradora, $fundador))
            ->assertOk();

        $this->assertDatabaseMissing('prestador_usuario', [
            'prestador_id' => $clinica->id,
            'user_id' => $fundador->id,
            'encerrado_em' => null,
        ]);

        $this->actingAs($fundador->fresh())->getJson('/api/prestador/painel')->assertForbidden();
    }

    /** RF07c — encerrar o responsável técnico deixaria a clínica sem poder registrar nada. */
    public function test_encerrar_o_responsavel_tecnico_e_recusado(): void
    {
        $clinica = $this->clinica();
        $administradora = $this->administradora($clinica);
        $marcelo = $this->veterinario($clinica, 'Marcelo Andrade', '12345');

        $vinculo = $this->vinculoDe($administradora, $marcelo);

        $this->actingAs($administradora)
            ->deleteJson('/api/prestador/equipe/'.$vinculo)
            ->assertStatus(422);
    }

    public function test_a_linha_do_responsavel_tecnico_explica_por_que_nao_oferece_a_acao(): void
    {
        $clinica = $this->clinica();
        $administradora = $this->administradora($clinica);
        $this->veterinario($clinica, 'Marcelo Andrade', '12345');

        $this->actingAs($administradora)
            ->getJson('/api/prestador/equipe')
            ->assertJsonPath('membros.0.pode_encerrar', false)
            ->assertJsonPath(
                'membros.0.impedimento',
                'É o responsável técnico do prestador. Informe outro em Dados do prestador antes de encerrar.',
            );
    }

    public function test_encerrar_o_proprio_vinculo_e_recusado(): void
    {
        $clinica = $this->clinica(['responsavel_tecnico_crmv' => '99999']);
        $administradora = $this->administradora($clinica);
        $administradora->prestadores()->attach($clinica, [
            'papel' => 'veterinario',
            'crmv' => '55555',
            'crmv_uf' => 'MG',
        ]);

        $this->actingAs($administradora)
            ->deleteJson('/api/prestador/equipe/'.$this->vinculoDe($administradora, $administradora))
            ->assertStatus(422);
    }

    public function test_encerrar_duas_vezes_termina_no_mesmo_lugar(): void
    {
        $clinica = $this->clinica();
        $administradora = $this->administradora($clinica);
        $larissa = $this->veterinario($clinica, 'Larissa Nogueira', '20981');
        $vinculo = $this->vinculoDe($administradora, $larissa);

        $this->actingAs($administradora)->deleteJson('/api/prestador/equipe/'.$vinculo)->assertOk();
        $encerradoEm = $larissa->fresh()->prestadores()->first()->pivot->encerrado_em;

        $this->actingAs($administradora)->deleteJson('/api/prestador/equipe/'.$vinculo)->assertOk();

        $this->assertSame($encerradoEm, $larissa->fresh()->prestadores()->first()->pivot->encerrado_em);
    }

    public function test_vinculo_de_outro_prestador_devolve_404(): void
    {
        $clinica = $this->clinica();
        $outra = $this->clinica(['nome' => 'Pet Center', 'cnpj' => '11444777000161']);
        $administradoraDaOutra = $this->administradora($outra);
        $beatriz = $this->veterinario($outra, 'Beatriz Salles', '18220');

        $vinculoAlheio = $this->vinculoDe($administradoraDaOutra, $beatriz);

        $this->actingAs($this->administradora($clinica))
            ->deleteJson('/api/prestador/equipe/'.$vinculoAlheio)
            ->assertNotFound();
    }

    /**
     * RF10a — o desligamento também vale para a caixa de entrada. O aviso de
     * revogação nomeia o animal, e quem saiu não tem mais o que fazer com ele.
     */
    public function test_o_ex_veterinario_nao_recebe_mais_aviso_de_revogacao(): void
    {
        $clinica = $this->clinica();
        $administradora = $this->administradora($clinica);
        $larissa = $this->veterinario($clinica, 'Larissa Nogueira', '20981');

        $tutor = Tutor::factory()->create();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id]);
        $autorizacao = Autorizacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $clinica->id,
            'concedida_por_user_id' => $tutor->user_id,
        ]);

        $this->actingAs($administradora)
            ->deleteJson('/api/prestador/equipe/'.$this->vinculoDe($administradora, $larissa))
            ->assertOk();

        Notification::fake();

        $this->actingAs($tutor->user)
            ->deleteJson('/api/autorizacoes/'.$autorizacao->id)
            ->assertOk();

        Notification::assertNotSentTo($larissa, AutorizacaoRevogada::class);
    }

    /**
     * RN08 — o aviso nomeia o animal, e o papel administrativo não alcança dado
     * clínico. Na caixa de entrada tanto quanto na tela.
     */
    public function test_o_administrador_nao_recebe_aviso_que_nomeia_animal(): void
    {
        $clinica = $this->clinica();
        $administradora = $this->administradora($clinica);
        $this->veterinario($clinica, 'Larissa Nogueira', '20981');

        $tutor = Tutor::factory()->create();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id]);
        $autorizacao = Autorizacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $clinica->id,
            'concedida_por_user_id' => $tutor->user_id,
        ]);

        Notification::fake();

        $this->actingAs($tutor->user)
            ->deleteJson('/api/autorizacoes/'.$autorizacao->id)
            ->assertOk();

        Notification::assertNotSentTo($administradora, AutorizacaoRevogada::class);
    }

    // ------------------------------------------------------------- concessão

    /**
     * O responsável técnico da clínica — o CRMV da factory — com o papel
     * administrativo que a tela de equipe exige para ser aberta.
     */
    private function responsavelTecnico(Prestador $prestador): User
    {
        $usuario = $this->veterinario($prestador, 'Marcelo Andrade', '12345');
        $usuario->prestadores()->attach($prestador, ['papel' => 'admin_prestador']);

        return $usuario;
    }

    public function test_a_lista_diz_quem_administra_a_conta(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->responsavelTecnico($clinica);
        $beatriz = $this->veterinario($clinica, 'Beatriz Salles', '20981');

        $resposta = $this->actingAs($marcelo)->getJson('/api/prestador/equipe')->assertOk();

        $linhas = collect($resposta->json('membros'))->keyBy('email');

        $this->assertTrue($linhas[$marcelo->email]['administrador']);
        $this->assertFalse($linhas[$beatriz->email]['administrador']);
        $this->assertTrue($linhas[$beatriz->email]['pode_conceder_administracao']);
        // A própria linha não oferece a saída de que quem está na tela depende.
        $this->assertFalse($linhas[$marcelo->email]['pode_revogar_administracao']);
        $resposta->assertJsonPath('concessao.pode_conceder', true);
    }

    public function test_o_responsavel_tecnico_concede_a_administracao_a_quem_atende(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->responsavelTecnico($clinica);
        $beatriz = $this->veterinario($clinica, 'Beatriz Salles', '20981');

        $this->actingAs($marcelo)
            ->postJson('/api/prestador/equipe/'.$this->vinculoDe($marcelo, $beatriz).'/administracao')
            ->assertCreated()
            ->assertJsonPath('message', 'Beatriz Salles passa a administrar a conta: pode convidar '
                .'profissionais, encerrar vínculos e alterar os dados do prestador.');

        // O que a concessão vale: a conta abre para quem a recebeu.
        $this->actingAs(User::find($beatriz->id))
            ->getJson('/api/prestador/painel')
            ->assertOk()
            ->assertJsonPath('prestador.nome', $clinica->nome);
    }

    /**
     * O papel administrativo é linha própria no pivô, e é isso que preserva o
     * CRMV com que a pessoa assina: conceder administração não mexe no vínculo
     * de veterinário nem no que ele carrega (RN22).
     */
    public function test_a_concessao_nao_altera_o_vinculo_clinico(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->responsavelTecnico($clinica);
        $beatriz = $this->veterinario($clinica, 'Beatriz Salles', '20981');

        $this->actingAs($marcelo)
            ->postJson('/api/prestador/equipe/'.$this->vinculoDe($marcelo, $beatriz).'/administracao')
            ->assertCreated();

        $beatriz = User::find($beatriz->id);

        $this->assertSame('CRMV-MG 20981', $beatriz->crmvEm($clinica));
        $this->assertContains('veterinario', $beatriz->papeis());
        $this->assertContains('admin_prestador', $beatriz->papeis());
    }

    public function test_conceder_de_novo_nao_duplica_o_vinculo_administrativo(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->responsavelTecnico($clinica);
        $beatriz = $this->veterinario($clinica, 'Beatriz Salles', '20981');
        $vinculo = $this->vinculoDe($marcelo, $beatriz);

        $this->actingAs($marcelo)->postJson("/api/prestador/equipe/{$vinculo}/administracao")->assertCreated();

        $this->actingAs($marcelo)
            ->postJson("/api/prestador/equipe/{$vinculo}/administracao")
            ->assertOk()
            ->assertJsonPath('message', 'Beatriz Salles já administra a conta.');

        $this->assertDatabaseCount('prestador_usuario', 4);
    }

    /**
     * A segregação escolhida: administrar é convidar, desligar e alterar o
     * cadastro, e quem responde tecnicamente pelo estabelecimento é quem
     * decide a quem esse poder cabe.
     */
    public function test_administradora_que_nao_e_responsavel_tecnico_nao_concede(): void
    {
        $clinica = $this->clinica();
        $this->responsavelTecnico($clinica);
        $ana = $this->administradora($clinica);
        $beatriz = $this->veterinario($clinica, 'Beatriz Salles', '20981');

        $this->actingAs($ana)
            ->getJson('/api/prestador/equipe')
            ->assertOk()
            ->assertJsonPath('concessao.pode_conceder', false)
            ->assertJsonPath(
                'concessao.restricao',
                'Conceder e retirar a administração da conta é do responsável técnico — hoje, Marcelo Andrade.',
            );

        $this->actingAs($ana)
            ->postJson('/api/prestador/equipe/'.$this->vinculoDe($ana, $beatriz).'/administracao')
            ->assertForbidden();

        $this->assertNotContains('admin_prestador', User::find($beatriz->id)->papeis());
    }

    /**
     * A conta cujo responsável técnico não tem vínculo ativo: ninguém concede,
     * e a tela diz por quê — o silêncio aqui seria uma ação que não existe.
     */
    public function test_sem_responsavel_tecnico_na_equipe_a_tela_aponta_o_cadastro(): void
    {
        $clinica = $this->clinica();
        $ana = $this->administradora($clinica);
        $this->veterinario($clinica, 'Beatriz Salles', '20981');

        $this->actingAs($ana)
            ->getJson('/api/prestador/equipe')
            ->assertOk()
            ->assertJsonPath('concessao.pode_conceder', false)
            ->assertJsonPath(
                'concessao.restricao',
                'Conceder e retirar a administração da conta é do responsável técnico, e nenhum '
                    .'vínculo ativo da equipe traz o CRMV informado em Dados do prestador.',
            );
    }

    public function test_convite_pendente_nao_recebe_a_administracao(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->responsavelTecnico($clinica);

        Notification::fake();

        $this->actingAs($marcelo)
            ->postJson('/api/prestador/equipe', [
                'email' => 'nova@clinica.test',
                'crmv' => '55555',
                'crmv_uf' => 'MG',
            ])
            ->assertCreated();

        $vinculo = collect($this->actingAs($marcelo)->getJson('/api/prestador/equipe')->json('membros'))
            ->firstWhere('email', 'nova@clinica.test')['vinculo'];

        $this->actingAs($marcelo)
            ->postJson("/api/prestador/equipe/{$vinculo}/administracao")
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'O convite ainda não foi aceito. A administração se concede a quem já entrou.',
            );
    }

    public function test_o_responsavel_tecnico_retira_a_administracao_sem_encerrar_o_vinculo(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->responsavelTecnico($clinica);
        $beatriz = $this->veterinario($clinica, 'Beatriz Salles', '20981');
        $vinculo = $this->vinculoDe($marcelo, $beatriz);

        $this->actingAs($marcelo)->postJson("/api/prestador/equipe/{$vinculo}/administracao")->assertCreated();

        $this->actingAs($marcelo)
            ->deleteJson("/api/prestador/equipe/{$vinculo}/administracao")
            ->assertOk()
            ->assertJsonPath('message', 'Beatriz Salles deixa de administrar a conta. O vínculo de '
                .'veterinário continua, e os registros assinados permanecem no prontuário.');

        // O poder de mudar acaba; o de ver onde trabalha, não — e o ambiente
        // clínico segue intacto.
        $this->actingAs(User::find($beatriz->id))
            ->getJson('/api/prestador/painel')
            ->assertOk()
            ->assertJsonPath('pode_administrar', false);

        $this->actingAs(User::find($beatriz->id))
            ->postJson('/api/prestador/equipe', ['email' => 'x@y.test', 'crmv' => '11111', 'crmv_uf' => 'MG'])
            ->assertForbidden();

        $this->actingAs(User::find($beatriz->id))->getJson('/api/clinica/painel')->assertOk();
    }

    public function test_ninguem_retira_a_propria_administracao(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->responsavelTecnico($clinica);

        $this->actingAs($marcelo)
            ->deleteJson('/api/prestador/equipe/'.$this->vinculoDe($marcelo, $marcelo).'/administracao')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Você não pode retirar a própria administração.');

        $this->actingAs(User::find($marcelo->id))->getJson('/api/prestador/painel')->assertOk();
    }

    public function test_retirar_administracao_de_quem_nao_administra_e_recusado(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->responsavelTecnico($clinica);
        $beatriz = $this->veterinario($clinica, 'Beatriz Salles', '20981');

        $this->actingAs($marcelo)
            ->deleteJson('/api/prestador/equipe/'.$this->vinculoDe($marcelo, $beatriz).'/administracao')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Beatriz Salles não administra a conta.');
    }

    /**
     * O caso que motivou a fatia: quem atende numa clínica que não administra
     * e mantém a própria conta passa, depois da concessão, a administrar
     * aquela em que está — sem que a memória de contexto a desloque.
     */
    public function test_quem_recebe_a_administracao_administra_a_conta_em_que_atende(): void
    {
        $clinica = $this->clinica();
        $consultorio = $this->clinica([
            'nome' => 'Consultório da Beatriz',
            'cnpj' => '99888777000100',
            'responsavel_tecnico_crmv' => '20981',
        ]);

        $marcelo = $this->responsavelTecnico($clinica);
        $beatriz = $this->veterinario($clinica, 'Beatriz Salles', '20981');
        $beatriz->prestadores()->attach($consultorio, [
            'papel' => 'veterinario', 'crmv' => '20981', 'crmv_uf' => 'MG',
        ]);
        $beatriz->prestadores()->attach($consultorio, ['papel' => 'admin_prestador']);

        $this->actingAs($marcelo)
            ->postJson('/api/prestador/equipe/'.$this->vinculoDe($marcelo, $beatriz).'/administracao')
            ->assertCreated();

        // Ela escolhe o contexto da clínica e abre a administração: é a clínica
        // que aparece, e não mais o consultório por falta de alternativa.
        $this->actingAs(User::find($beatriz->id))
            ->getJson("/api/clinica/painel?prestador={$clinica->id}")
            ->assertOk();

        $this->actingAs(User::find($beatriz->id))
            ->getJson('/api/prestador/painel')
            ->assertOk()
            ->assertJsonPath('prestador.nome', $clinica->nome)
            ->assertJsonPath('contexto_clinico', null);
    }
}
