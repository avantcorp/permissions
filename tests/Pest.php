<?php

declare(strict_types=1);

use Avant\Permissions\Permissions;
use Avant\Permissions\Tests\Fixtures\Models\User;
use Avant\Permissions\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in(__DIR__);

/**
 * Seed the permissions the fixture policies derive, as `permission:seed` would.
 *
 * `PolicyHelper::hasPermission()` throws when the permission row is missing, so
 * every ability under test has to exist even when nobody holds it.
 */
function seedPermissions(): void
{
    foreach (['viewAnyPost', 'viewPost', 'updatePost', 'viewAnyComment', 'viewComment', 'accessAdmin'] as $permission) {
        Permission::findOrCreate($permission);
    }
}

/**
 * Create a user holding the given policy-derived permissions.
 *
 * @param  list<string>  $permissions
 */
function userWith(array $permissions = [], bool $superuser = false): User
{
    $user = User::query()->create(['name' => 'Test User']);

    if ($permissions !== []) {
        $user->givePermissionTo($permissions);
    }

    if ($superuser) {
        $user->assignRole(Role::findOrCreate(Permissions::SUPERUSER));
    }

    return $user;
}

/**
 * Run a callback while recording the queries it issues against the given tables.
 *
 * @param  list<string>  $tables
 * @return list<string>
 */
function queriesAgainst(array $tables, Closure $callback): array
{
    $recorded = [];

    DB::listen(function ($query) use (&$recorded): void {
        $recorded[] = $query->sql;
    });

    $callback();

    return array_values(array_filter(
        $recorded,
        fn (string $sql): bool => (bool) array_filter(
            $tables,
            fn (string $table): bool => str_contains($sql, '"'.$table.'"')
        )
    ));
}
