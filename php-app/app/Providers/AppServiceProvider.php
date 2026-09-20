<?php

namespace App\Providers;

use App\Integrations\Etariff\EtariffPort;
use App\Integrations\Etariff\UnavailableEtariffAdapter;
use App\Integrations\Itas\ItasIdentityPort;
use App\Integrations\Itas\UnavailableItasIdentityAdapter;
use App\Integrations\Payment\PaymentConnectorPort;
use App\Integrations\Payment\SandboxPaymentConnector;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ItasIdentityPort::class, UnavailableItasIdentityAdapter::class);
        $this->app->bind(EtariffPort::class, UnavailableEtariffAdapter::class);
        // Unlike Itas/Etariff's own "unconditionally unavailable" stand-in
        // (no config could ever make those available), SandboxPaymentConnector
        // is the one real, permanent implementation -- its own internal
        // service_components guard is what varies, not the bound class. See
        // App\Integrations\Payment\PaymentConnectorPort's own doc comment.
        $this->app->bind(PaymentConnectorPort::class, SandboxPaymentConnector::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /**
         * Ported from lib/auth.ts's requirePermission/hasPermission. Every
         * protected controller action must call
         * `Gate::authorize('permission', 'invoices:submit')` (or the
         * `can:` middleware / @can Blade directive) -- server-side, never
         * menu-hiding alone, matching the source's own stated invariant
         * ("every one of 165 route files is permission-gated").
         *
         * `User::hasAppPermission()` covers both halves of the source's own
         * `hasPermission` (static role grants and organisation-defined
         * custom-role dynamic grants) -- see that method's own doc comment.
         *
         * The denial message is ported verbatim from lib/domain/access.ts's
         * own `requirePermission` ("Role ${user.role} does not have
         * ${permission} permission.") rather than left as Laravel's generic
         * "This action is unauthorized." default -- App\Support\Security\
         * SecurityEventRecorder::recordAuthorizationDenial (reached via
         * bootstrap/app.php's own AccessDeniedHttpException handler) regexes
         * the denied permission back out of this exact wording, matching
         * the source's own recordAuthorizationDenial in lib/security/
         * request.ts. Without this fix that regex never matched anything
         * real, silently recording every denial as a generic 'ACCESS_DENIED'.
         */
        Gate::define('permission', function (User $user, string $permission) {
            if ($user->isActive() && $user->hasAppPermission($permission)) {
                return true;
            }

            return Response::deny("Role {$user->role} does not have {$permission} permission.");
        });
    }
}
