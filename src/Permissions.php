<?php

declare(strict_types=1);

namespace Avant\Permissions;

use Avant\Permissions\Http\Controllers\CheckController;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Stringable;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\Iterator\PathFilterIterator;
use Symfony\Component\Finder\SplFileInfo;

#[Singleton]
class Permissions
{
    public const string SUPERUSER = 'superuser';

    /** @return Collection<class-string, string> */
    protected function policies(): Collection
    {
        /** @var Collection<class-string, string> */
        return once(
            fn () => with(
                value: Finder::create()
                    ->files()
                    ->name('*.php')
                    ->in(app_path())
                    ->getIterator(),
                callback: fn (PathFilterIterator $files): Collection => collect($files)
                    ->map(
                        fn (SplFileInfo $fileInfo) => str($fileInfo->getPathname())
                            ->after(base_path(DIRECTORY_SEPARATOR))
                            ->ucfirst()
                            ->beforeLast('.php')
                            ->replace(DIRECTORY_SEPARATOR, '\\')
                            ->prepend('\\')
                            ->toString()
                    )
                    ->filter(fn (string $classString) => (
                        class_exists($classString)
                        && collect(class_implements($classString))->flip()->has(Policy::class)
                    ))
            )
        );
    }

    /** @return Collection<string, int> */
    protected function fromDatabase(): Collection
    {
        return once(fn () => with(
            config('permission.models.permission'),
            fn (string $model) => $model::query()->pluck('id', 'name')
        ));
    }

    /** @return Collection<string, Collection<int, Stringable>> */
    public function byPolicy(): Collection
    {
        $databasePermissions = $this->fromDatabase();

        return once(
            fn () => $this->policies()
                ->flip()
                ->map(fn ($_, string $classString): ReflectionClass => new ReflectionClass($classString))
                ->filter(fn (ReflectionClass $class): bool => $class->isInstantiable())
                ->keyBy(
                    fn (ReflectionClass $class, string $classString): string => str($classString)
                        ->classBasename()
                        ->beforeLast('Policy')
                        ->toString()
                )
                ->map(
                    fn (
                        ReflectionClass $class,
                        string $policyName
                    ): Collection => collect($class->getMethods(ReflectionMethod::IS_PUBLIC))
                        ->map(fn (ReflectionMethod $method): string => $method->getName())
                        ->mapWithKeys(fn (string $name): array => [
                            $databasePermissions->get($name.$policyName) => str(
                                str(ucfirst($name))
                                    ->split('/(?<=[A-Z])(?=[A-Z][a-z])|(?<=[^A-Z])(?=[A-Z])|(?<=[A-Za-z])(?=[^A-Za-z])/')
                                    ->implode(' ')
                            ),
                        ])
                        ->sort()
                )
                ->keyBy(fn ($_, string $group) => str($group)
                    ->split('/(?<=[A-Z])(?=[A-Z][a-z])|(?<=[^A-Z])(?=[A-Z])|(?<=[A-Za-z])(?=[^A-Za-z])/')
                    ->implode(' ')
                )
                ->sortKeys()
                ->filter(fn (Collection $permissions) => $permissions->isNotEmpty())
        );
    }

    /** @return Collection<int, Stringable> */
    public function all(): Collection
    {
        return once(
            fn () => $this
                ->byPolicy()
                ->map(
                    fn (Collection $permissions, string $policy): Collection => $permissions
                        ->map(fn (Stringable $permission): Stringable => $permission->append(" {$policy}"))
                )
                ->values()
                ->collapseWithKeys()
        );
    }

    public static function route(string $path): void
    {
        Route::post($path, CheckController::class)
            ->middleware(['web', 'auth'])
            ->name('permissions.check');
    }
}
