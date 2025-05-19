<?php

namespace Avant\Permissions;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class PermissionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/permission.php', 'permission');

        $this->app->singleton(Permissions::class, fn () => new Permissions());
    }

    public function boot(): void
    {
        Gate::after(function (User $user, $ability, $result, $arguments): bool {
            return !is_null($result) ? $result : $user->hasRole(Permissions::ADMIN_ROLE);
        });

        Gate::guessPolicyNamesUsing(function ($class) {
            $classDirname = str_replace('/', '\\', dirname(str_replace('\\', '/', $class)));

            $classDirnameSegments = explode('\\', $classDirname);

            if (str_starts_with($classDirname, 'App\Policies')) {
                return $class;
            }

            return Arr::wrap(Collection::times(count($classDirnameSegments),
                function ($index) use ($class, $classDirnameSegments) {
                    $classDirname = implode('\\', array_slice($classDirnameSegments, 0, $index));

                    return $classDirname.'\\Policies\\'.class_basename($class).'Policy';
                })->when(str_contains($classDirname, '\\Models\\'), function ($collection) use ($class, $classDirname) {
                return $collection->concat([
                    str_replace('\\Models\\',
                        '\\Policies\\',
                        $classDirname).'\\'.class_basename($class).'Policy',
                ])
                    ->concat([
                        str_replace('\\Models\\',
                            '\\Models\\Policies\\',
                            $classDirname).'\\'.class_basename($class).'Policy',
                    ]);
            })->reverse()->values()->first(function ($class) {
                return class_exists($class);
            }) ?: [$classDirname.'\\Policies\\'.class_basename($class).'Policy']);
        });
    }
}