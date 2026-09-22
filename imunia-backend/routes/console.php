<?php

use App\Models\Imunobiologico;
use App\Models\User;
use Database\Seeders\CatalogoImunobiologicosSeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Roda a cada início do servidor de produção, depois das migrações (ver
 * deploy/iniciar.sh). O plano gratuito do Render não abre terminal no
 * contêiner, então tudo o que o banco precisa para funcionar tem de nascer
 * daqui — e poder rodar de novo sem estragar nada.
 */
Artisan::command('imunia:preparar', function () {
    // O catálogo (RF23, RF24) só entra no banco vazio: depois disso, quem
    // manda nele é a administração da plataforma em X01 e X02, e semear de
    // novo desfaria o que ela mudou. Numa transação, para que uma falha no
    // meio não deixe um catálogo pela metade que nunca mais seria completado.
    if (Imunobiologico::query()->doesntExist()) {
        DB::transaction(fn () => $this->call('db:seed', [
            '--class' => CatalogoImunobiologicosSeeder::class,
            '--force' => true,
        ]));
    }

    $email = config('app.admin_plataforma_email');

    if (blank($email)) {
        return;
    }

    // Conta nova nasce com senha aleatória que ninguém conhece: o acesso
    // começa por "Esqueci minha senha" (P05), que também a ativa.
    $usuario = User::firstOrCreate(
        ['email' => $email],
        ['name' => 'Administração Imunia', 'password' => Str::password(40)],
    );

    $usuario->forceFill([
        'admin_plataforma' => true,
        'email_verified_at' => $usuario->email_verified_at ?? now(),
    ])->save();

    $this->info("Administração da plataforma: {$email}.");
})->purpose('Semeia o catálogo no banco vazio e garante a administração da plataforma');
