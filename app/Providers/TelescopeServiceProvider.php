<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Telescope::night();

        $this->hideSensitiveRequestDetails();

        // filtrar por cliente no Telescope: tag `account:{uuid}`
        Telescope::tag(function (IncomingEntry $entry) {
            $accountId = app(\App\Support\TenantContext::class)->accountId();

            return $accountId ? ['account:'.$accountId] : [];
        });

        Telescope::filter(function (IncomingEntry $entry){
            if ($this->app->environment('local')) {
                return true;
            }
     
            return $entry->isReportableException() ||
                $entry->isFailedJob() ||
                $entry->isScheduledTask() ||
                $entry->isSlowQuery() ||
                $entry->hasMonitoredTag();
        });
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     */
    protected function hideSensitiveRequestDetails(): void
    {
    
        Telescope::hideRequestParameters(['_token', 'card_token', 'token', 'billing_address', 'issuer_id', 'payment_method_id']);

        Telescope::hideRequestHeaders([
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
            'authorization',
        ]);
    }

    /**
     * Register the Telescope gate.
     *
     * This gate determines who can access Telescope in non-local environments.
     */
    protected function gate(): void
    {
        // O Telescope guarda requisições e exceções de TODAS as contas: só super-admins da plataforma
        Gate::define('viewTelescope', function ($user) {
            return (bool) $user?->is_super_admin;
        });
    }
}
