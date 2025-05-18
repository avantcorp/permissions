<?php

namespace Avant\Permissions;

use Avant\Permissions\Permission as PermissionAttribute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Spatie\Permission\Commands\CacheReset;
use Spatie\Permission\Models\Permission;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

class Permissions
{
    public const string ADMIN_ROLE = 'superuser';

    public function publish(): void
    {
        config('permission.models.role')::query()
            ->firstOrCreate([
                'name'       => static::ADMIN_ROLE,
            ]);

        $permissions = $this
            ->all()
            ->diff(Permission::query()->pluck('name'));

        if ($permissions->isNotEmpty()) {
            Permission::query()
                ->insert(
                    $permissions
                        ->map(fn (string $name): array => [
                            'name'       => $name,
                            'guard_name' => 'web',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ])
                        ->toArray()
                );
        }

        Permission::query()
            ->whereNotIn('name', $this->all())
            ->delete();

        Artisan::call(CacheReset::class);
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
                                ->contains(PermissionAttribute::class)
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