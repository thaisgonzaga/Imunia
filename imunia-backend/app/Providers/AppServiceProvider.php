<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A ligação de redefinição leva à tela do SPA (P06), não a uma rota da
        // API. O endereço acompanha o token porque o verificador do Laravel
        // valida o par token + endereço.
        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            return sprintf(
                '%s/redefinir-senha/%s?email=%s',
                rtrim((string) config('app.frontend_url'), '/'),
                $token,
                urlencode($notifiable->getEmailForPasswordReset()),
            );
        });

        // O mailer `brevo` de config/mail.php: envio pela API HTTP, e não por
        // SMTP, que o plano gratuito do Render bloqueia.
        Mail::extend('brevo', fn (array $config) => (new BrevoTransportFactory(client: HttpClient::create()))
            ->create(new Dsn('brevo+api', 'default', $config['key'] ?? null)));
    }
}
