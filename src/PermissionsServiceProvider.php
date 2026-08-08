<?php

declare(strict_types=1);

namespace Avant\Permissions;

use Avant\Permissions\Console\Commands\SeedPermissions;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class PermissionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/permission.php', 'permission');

        $this->commands([
            SeedPermissions::class,
        ]);
    }

    public function boot(): void
    {
        Gate::after(function (Authorizable $authorizable, $ability, $result, $arguments): bool {
            return !is_null($result) ? $result : $authorizable->hasRole(Permissions::SUPERUSER);
        });
    }
}
