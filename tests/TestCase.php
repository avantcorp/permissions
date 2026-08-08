<?php

declare(strict_types=1);

namespace Avant\Permissions\Tests;

use Avant\Permissions\PermissionsServiceProvider;
use Avant\Permissions\Permissions;
use Avant\Permissions\Tests\Fixtures\Models\Comment;
use Avant\Permissions\Tests\Fixtures\Models\Post;
use Avant\Permissions\Tests\Fixtures\Models\User;
use Avant\Permissions\Tests\Fixtures\Policies\CommentPolicy;
use Avant\Permissions\Tests\Fixtures\Policies\PostPolicy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Permission\PermissionServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Gate::policy(Post::class, PostPolicy::class);
        Gate::policy(Comment::class, CommentPolicy::class);
    }

    protected function getPackageProviders($app): array
    {
        return [
            PermissionServiceProvider::class,
            PermissionsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('auth.providers.users.model', User::class);

        // The controller resolves the `model` string sent by the frontend against these.
        $app['config']->set('permission.models_namespace', 'Avant\\Permissions\\Tests\\Fixtures\\Models');
        $app['config']->set('permission.policies_namespace', 'Avant\\Permissions\\Tests\\Fixtures\\Policies');
        $app['config']->set('permission.modules.namespace', null);
    }

    protected function defineRoutes($router): void
    {
        Permissions::route('/permissions/check');
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->boolean('published')->default(true);
        });

        Schema::create('comments', function (Blueprint $table): void {
            $table->id();
            $table->string('body');
            $table->boolean('approved')->default(true);
        });

        $permissionTables = include __DIR__.'/../vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub';
        $permissionTables->up();
    }
}
