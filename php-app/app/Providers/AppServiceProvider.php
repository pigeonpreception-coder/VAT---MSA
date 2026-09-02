<?php

namespace App\Providers;

use App\Integrations\Itas\ItasIdentityPort;
use App\Integrations\Itas\UnavailableItasIdentityAdapter;
use App\Models\User;
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
         */
        Gate::define('permission', function (User $user, string $permission) {
            return $user->isActive() && $user->hasAppPermission($permission);
        });
    }
}
