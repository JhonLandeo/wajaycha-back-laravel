<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
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
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });
    }
}
