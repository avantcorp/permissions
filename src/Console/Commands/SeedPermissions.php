<?php

declare(strict_types=1);

namespace Avant\Permissions\Console\Commands;

use Avant\Permissions\Permission;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Spatie\Permission\Commands\CacheReset;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

class SeedPermissions extends Command
{
    protected $signature = 'permission:seed {guard?}';
    protected $description = 'Read policy permissions and seed the database';

    public function handle(): int
    {
        /** @var \Spatie\Permission\Models\Role $roleModel */
        $roleModel = config('permission.models.role');

        /** @var \Spatie\Permission\Models\Permission $permissionModel */
        $permissionModel = config('permission.models.permission');

        $guard = $this->argument('guard') ?: config('auth.defaults.guard');

        $roleModel::query()
            ->firstOrCreate([
                'name'       => Permission::SUPERUSER,
                'guard_name' => $guard,
            ]);

        $permissions = $this
            ->all()
            ->diff($permissionModel::query()->pluck('name'));

        if ($permissions->isNotEmpty()) {
            $permissionModel::query()
                ->insert(
                    $permissions
                        ->map(fn (string $name): array => [
                            'name'       => $name,
                            'guard_name' => $guard,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ])
                        ->toArray()
                );
        }

        $permissionModel::query()
            ->whereNotIn('name', $this->all())
            ->delete();

        Artisan::call(CacheReset::class);

        return static::SUCCESS;
    }

    public function byGroup(): Collection
    {
        return once(
            fn () => $this->policies()
                ->flip()
                ->map(fn ($_, string $classString): ReflectionClass => new ReflectionClass($classString))
                ->keyBY(
                    fn (ReflectionClass $class, string $classString): string => str($classString)
                        ->classBasename()
                        ->beforeLast('Policy')
                        ->toString()
                )
                ->filter(fn (ReflectionClass $class): bool => $class->isInstantiable())
                ->map(
                    fn (
                        ReflectionClass $class,
                        string $policyName
                    ): Collection => collect($class->getMethods(ReflectionMethod::IS_PUBLIC))
                        ->filter(
                            fn (ReflectionMethod $method): bool => collect($method->getAttributes())
                                ->map(fn (ReflectionAttribute $attribute) => $attribute->getName())
                                ->contains(Permission::class)
                        )
                        ->map(fn (ReflectionMethod $method): string => $method->getName())
                        ->crossJoin($policyName)
                        ->map(fn ($parts): string => implode('', $parts))
                        ->sort()
                )
                ->filter(fn (Collection $permissions) => $permissions->isNotEmpty())
        );
    }

    public function all(): Collection
    {
        return $this->byGroup()->collapse();
    }

    protected function policies(): Collection
    {
        return collect(
            Finder::create()
                ->files()
                ->name('*.php')
                ->in(config('permission.policy_path'))
                ->getIterator()
        )
            ->map(
                fn (SplFileInfo $fileInfo) => str($fileInfo->getPathname())
                    ->after(base_path('/'))
                    ->ucfirst()
                    ->beforeLast('.php')
                    ->replace('/', '\\')
                    ->prepend('\\')
                    ->toString()
            );
    }
}
