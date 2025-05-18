<?php

namespace Avant\Permissions;

use Illuminate\Support\ServiceProvider;

class PermissionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/permissions.php', 'permissions');

        $this->app->singleton(Permissions::class, fn () => new Permissions());
    }
}