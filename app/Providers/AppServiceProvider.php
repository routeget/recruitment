<?php

namespace App\Providers;

use App\Services\AuditService;
use App\Services\TenantContextService;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContextService::class, fn () => new TenantContextService);
        $this->app->singleton(AuditService::class, fn () => new AuditService);
    }

    public function boot(): void
    {
        Paginator::useBootstrapFive();

        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
