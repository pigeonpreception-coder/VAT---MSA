<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Access\Permissions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

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
        /**
         * Ported from lib/auth.ts's requirePermission/hasPermission. Every
         * protected controller action must call
         * `Gate::authorize('permission', 'invoices:submit')` (or the
         * `can:` middleware / @can Blade directive) -- server-side, never
         * menu-hiding alone, matching the source's own stated invariant
         * ("every one of 165 route files is permission-gated").
         */
        Gate::define('permission', function (User $user, string $permission) {
            return $user->isActive() && Permissions::roleHas($user->role, $permission);
        });
    }
}
