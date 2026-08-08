<?php

declare(strict_types=1);

namespace Avant\Permissions\Http\Controllers;

use Avant\Permissions\Http\Requests\CheckRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Exception;
use Throwable;

class CheckController
{
    public function __invoke(CheckRequest $request): JsonResponse
    {
        $parsed = $request
            ->collect()
            ->map(function (array $permission): ?array {
                try {
                    return $this->parse($permission);
                } catch (Throwable $e) {
                    return null;
                }
            });

        $models = $this->loadModels($parsed);

        return response()
            ->json(
                $parsed->map(function (?array $permission) use ($models): bool {
                    if ($permission === null) {
                        return false;
                    }

                    [$ability, $model, $id] = $permission;

                    if ($id) {
                        $loaded = $models->get($model);

                        if ($loaded === null) {
                            return false;
                        }

                        $model = $loaded->get((string) $id);
                    }

                    try {
                        return Gate::check($ability, $model);
                    } catch (Throwable $e) {
                        return false;
                    }
                })
            );
    }

    /**
     * Load every model referenced by id in a single query per class.
     *
     * Returns a map of class name => keyed models, or class name => null when
     * the class could not be queried.
     */
    private function loadModels(Collection $parsed): Collection
    {
        return $parsed
            ->filter(fn (?array $permission): bool => $permission !== null && (bool) $permission[2])
            ->groupBy(fn (array $permission): string => $permission[1])
            ->map(function (Collection $permissions, string $model): ?Collection {
                $ids = $permissions->map(fn (array $permission) => $permission[2])->unique()->values();

                try {
                    return $model::query()
                        ->whereKey($ids)
                        ->get()
                        ->keyBy(fn ($record): string => (string) $record->getKey());
                } catch (Throwable $e) {
                    return null;
                }
            });
    }

    private function parse($permission): array
    {
        $ability = data_get($permission, 'ability');
        $model = data_get($permission, 'model');
        $id = data_get($permission, 'id');

        if (blank($model)) {
            [$ability, $model] = explode('-', $ability);
        }

        return [$ability, ltrim($this->findModel($model), '\\'), $id];
    }

    private function findModel(string $model): string
    {
        $model = str_replace('/', '\\', $model);

        if (str_contains($model, '\\') && class_exists($model)) {
            return $model;
        }

        if (($baseModel = sprintf(
            '%s\\%s',
            config('permission.models_namespace'),
            $model
        )) && class_exists($baseModel)) {
            return $baseModel;
        }

        if (($basePolicy = sprintf(
            '%s\\%s',
            config('permission.policies_namespace'),
            $model
        )) && class_exists($basePolicy)) {
            return $basePolicy;
        }

        if (config('permission.modules.namespace')) {
            $model = str_contains($model, '\\') ? $model : "{$model}\\{$model}";
            [$moduleName, $modelOrPolicy] = explode('\\', $model, 2);
            $module = sprintf('%s\\%s', config('permission.modules.namespace'), $moduleName);
            if ($moduleModel = sprintf('%s\\%s', $module, $modelOrPolicy)) {
                return $moduleModel;
            }

            if ($modulePolicy = sprintf('%s\\%s', $module, $modelOrPolicy)) {
                return $modulePolicy;
            }
        }

        throw new Exception('Model or Policy not found');
    }
}
