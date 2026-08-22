<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            \App\Repositories\Contracts\TransactionRepositoryContract::class,
            \App\Repositories\TransactionRepository::class
        );

        $this->app->bind(
            \App\Repositories\Contracts\CategoryRepositoryContract::class,
            \App\Repositories\CategoryRepository::class
        );

        $this->app->bind(
            \App\Repositories\Contracts\ParetoRepositoryContract::class,
            \App\Repositories\ParetoRepository::class
        );

        $this->app->bind(
            \App\Repositories\Contracts\DashboardRepositoryContract::class,
            \App\Repositories\DashboardRepository::class
        );

        $this->app->bind(
            \App\Repositories\Contracts\DetailRepositoryContract::class,
            \App\Repositories\DetailRepository::class
        );

        $this->app->bind(
            \App\Repositories\Contracts\ImportRepositoryContract::class,
            \App\Repositories\ImportRepository::class
        );

        $this->app->bind(
            \App\Repositories\Contracts\ReconciliationCandidateRepositoryContract::class,
            \App\Repositories\ReconciliationCandidateRepository::class
        );

        // Capture channels are resolved by key, so adding an adapter is a registration
        // here rather than an edit to every caller.
        $this->app->singleton(
            \App\Services\Capture\CaptureChannelRegistry::class,
            fn ($app) => new \App\Services\Capture\CaptureChannelRegistry([
                $app->make(\App\Services\Capture\WhatsAppChannel::class),
                $app->make(\App\Services\Capture\TelegramChannel::class),
            ])
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->forwardContainerEnvironmentToServe();

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });
    }

    /**
     * Lets `php artisan serve` hand the container's connection settings to the
     * process that actually answers requests.
     *
     * `serve` does not run the HTTP server itself: it spawns `php -S` as a child
     * and rebuilds that child's environment from a whitelist, unsetting every
     * variable outside it ({@see \Illuminate\Foundation\Console\ServeCommand::$passthroughVariables},
     * applied at `ServeCommand.php:179`). Compose injects `DB_HOST=postgres`, the
     * parent has it, the child never sees it — so the served request falls back
     * to `.env`, reads `DB_HOST=127.0.0.1`, and looks for PostgreSQL inside the
     * PHP container, where nothing is listening.
     *
     * The failure is asymmetric and that is what makes it expensive: `horizon`
     * and `scheduler` are plain artisan processes, so they keep the injected
     * values and connect correctly. Only HTTP is affected, and only in the
     * container. Every diagnostic run through `artisan tinker` reports healthy
     * config while the webhook returns 500.
     *
     * Names come from `compose.yaml`'s `x-backend-env`; anything added there
     * belongs here too.
     */
    private function forwardContainerEnvironmentToServe(): void
    {
        ServeCommand::$passthroughVariables = array_values(array_unique(array_merge(
            ServeCommand::$passthroughVariables,
            [
                'DB_CONNECTION',
                'DB_HOST',
                'DB_PORT',
                'DB_DATABASE',
                'DB_USERNAME',
                'DB_PASSWORD',
                'REDIS_CLIENT',
                'REDIS_HOST',
                'REDIS_PORT',
                'QUEUE_CONNECTION',
                // Not a connection setting, but it fails the same way:
                // config/cors.php reads it, and only the served request needs
                // it. Without it here the CORS header is computed from the
                // 5173 fallback while artisan reports the injected value.
                'FRONTEND_URL',
                // Misma razon exacta: config/cors.php la lee y solo la request
                // servida la necesita.
                'FRONTEND_EXTRA_ORIGINS',
            ],
        )));
    }
}
