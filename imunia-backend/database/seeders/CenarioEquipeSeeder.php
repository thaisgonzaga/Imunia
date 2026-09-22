<?php

namespace Database\Seeders;

use App\Models\Convite;
use App\Models\Prestador;
use App\Models\RegistroDeAcesso;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A administração da Clínica Vet Amigo (A01, A02, A03).
 *
 * Seeder próprio, e não um acréscimo aos dois existentes, pela mesma razão que
 * separa aqueles dois entre si: `CenarioDemonstracaoSeeder` monta o caso
 * canônico do tutor, `CenarioClinicaSeeder` monta a rotina clínica, e este monta
 * a conta vista de dentro. Quem for ler um deles para entender uma tela não
 * precisa atravessar os outros dois.
 *
 * Reproduz o estado do desenho aprovado de A01: três veterinários ativos, um
 * convite prestes a expirar, um vínculo encerrado e o CEP em branco — que é o
 * que faz a pendência de configuração aparecer sem precisar de encenação.
 */
class CenarioEquipeSeeder extends Seeder
{
    private const SENHA = 'Segredo123';

    public function run(): void
    {
        $clinica = Prestador::query()->where('cnpj', '11222333000181')->firstOrFail();

        $administradora = $this->administradora($clinica);

        $this->veterinaria($clinica, 'Larissa Nogueira', 'larissa.nogueira@vetamigo.example.com', '20981');
        $this->veterinaria($clinica, 'Paulo Rezende', 'paulo.rezende@vetamigo.example.com', '31447');
        $this->convitePrestesAExpirar($clinica, $administradora);
        $this->vinculoEncerrado($clinica);

        $this->command?->info(
            'Administração do prestador pronta: '.$administradora->email
            .' / '.self::SENHA.' — 3 veterinários ativos, 1 convite a expirar, 1 vínculo encerrado.',
        );
    }

    /**
     * Quem administra a conta e não atende. A separação é o argumento das três
     * telas: ela não tem vínculo de veterinário, e por isso não alcança animal,
     * tutor nem registro clínico algum (RN08).
     */
    private function administradora(Prestador $clinica): User
    {
        $usuario = User::firstOrCreate(
            ['email' => 'ana.ferraz@vetamigo.example.com'],
            ['name' => 'Ana Lúcia Ferraz', 'password' => self::SENHA],
        );

        $usuario->forceFill([
            'email_verified_at' => $usuario->email_verified_at ?? now(),
            'ativado_em' => $usuario->ativado_em ?? now(),
        ])->save();

        $usuario->prestadores()->syncWithoutDetaching([
            $clinica->id => ['papel' => 'admin_prestador'],
        ]);

        return $usuario;
    }

    /** Os dois veterinários que, com o Dr. Marcelo, fecham os três do desenho. */
    private function veterinaria(Prestador $clinica, string $nome, string $email, string $crmv): void
    {
        $usuario = User::firstOrCreate(
            ['email' => $email],
            ['name' => $nome, 'password' => self::SENHA],
        );

        $usuario->forceFill([
            'email_verified_at' => $usuario->email_verified_at ?? now(),
            'ativado_em' => $usuario->ativado_em ?? now(),
        ])->save();

        $usuario->prestadores()->syncWithoutDetaching([
            $clinica->id => ['papel' => 'veterinario', 'crmv' => $crmv, 'crmv_uf' => 'MG'],
        ]);
    }

    /**
     * O convite que A01 cobra em pendência — e que é, de propósito, o caso de
     * RF09b: a Dra. Beatriz já atende no Pet Center (`CenarioClinicaSeeder`), e
     * o convite da Vet Amigo lhe abre um **segundo** vínculo.
     *
     * Escolher alguém que já tem conta é o que torna o cenário demonstrável em
     * dois pontos de uma vez: a linha da equipe exibe o nome dela (porque ele
     * já existe), e o aceite em P07 não pede senha nem nome — se pedisse,
     * sobrescreveria a senha com que ela entra no Pet Center.
     */
    private function convitePrestesAExpirar(Prestador $clinica, User $administradora): void
    {
        $beatriz = User::query()->where('email', 'beatriz.salles@petcenter.example.com')->first()
            ?? User::firstOrCreate(
                ['email' => 'beatriz.salles@petcenter.example.com'],
                ['name' => 'Beatriz Salles', 'password' => self::SENHA],
            );

        $beatriz->prestadores()->syncWithoutDetaching([
            $clinica->id => ['papel' => 'veterinario', 'crmv' => '18220', 'crmv_uf' => 'MG'],
        ]);

        $jaConvidada = Convite::query()
            ->where('user_id', $beatriz->id)
            ->where('prestador_id', $clinica->id)
            ->exists();

        if ($jaConvidada) {
            return;
        }

        [$convite] = Convite::emitir($beatriz, $clinica, 'veterinario', $administradora);

        // Dois dias, o prazo do desenho: dentro do limiar que faz a pendência
        // aparecer em A01, e ainda com folga para o reenvio surtir efeito.
        $convite->forceFill(['expira_em' => Carbon::now()->addDays(2)])->save();
    }

    /**
     * O vínculo encerrado, e o registro que ele deixou para trás.
     *
     * O `RegistroDeAcesso` é o que torna RF10 demonstrável: `registros_de_acesso`
     * não guarda retrato do CRMV, e o livro de acessos do tutor (T14) o resolve
     * ao vivo pelo vínculo. Se a autoria fosse apagada junto com o acesso, é
     * aqui que apareceria — o acesso do Dr. Henrique perderia o CRMV no dia em
     * que ele foi desligado, e a frase que A03 exibe na tela ("encerrar vínculo
     * remove o acesso, não a autoria") viraria mentira.
     */
    private function vinculoEncerrado(Prestador $clinica): void
    {
        $usuario = User::firstOrCreate(
            ['email' => 'henrique.vaz@example.com'],
            ['name' => 'Henrique Vaz', 'password' => self::SENHA],
        );

        $usuario->forceFill([
            'email_verified_at' => $usuario->email_verified_at ?? now(),
            'ativado_em' => $usuario->ativado_em ?? now(),
        ])->save();

        $usuario->prestadores()->syncWithoutDetaching([
            $clinica->id => ['papel' => 'veterinario', 'crmv' => '09112', 'crmv_uf' => 'MG'],
        ]);

        $encerradoEm = Carbon::now()->subDays(40);

        DB::table('prestador_usuario')
            ->where('prestador_id', $clinica->id)
            ->where('user_id', $usuario->id)
            ->update(['encerrado_em' => $encerradoEm, 'updated_at' => $encerradoEm]);

        $animal = DB::table('animais')->orderBy('id')->first();

        if ($animal === null) {
            return;
        }

        RegistroDeAcesso::firstOrCreate([
            'prestador_id' => $clinica->id,
            'user_id' => $usuario->id,
            'tutor_id' => $animal->tutor_id,
            'animal_id' => $animal->id,
            'natureza' => RegistroDeAcesso::BUSCA_POR_CODIGO,
            'ocorrido_em' => $encerradoEm->copy()->subDays(5),
        ]);
    }
}
