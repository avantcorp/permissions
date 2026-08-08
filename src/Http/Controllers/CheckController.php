<?php

declare(strict_types=1);

namespace Avant\Permissions\Http\Controllers;

use Avant\Permissions\Http\Requests\CheckRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class CheckController
{
    public function __invoke(CheckRequest $request): JsonResponse
    {
        return response()
            ->json(
                $request
                    ->collect()
                    ->map(function (array $permission): bool {
                        try {
                            [$ability, $model] = $this->parse($permission);
                            return Gate::check($ability, $model);
                        } catch (\Throwable $e) {
                            return false;
                        }
                    })
            );
    }

    private function parse($permission): array
    {
        $ability = data_get($permission, 'ability');
        $model = data_get($permission, 'model');
        $id = data_get($permission, 'id');

        if (blank($model)) {
            [$ability, $model] = explode('-', $ability);
        }

        $model = $this->findModel($model);

        return [$ability, $id ? $model::query()->find($id) : $model];
    }

    private function findModel(string $model): string
    {
        if (str_contains($model, '\\') && class_exists($model)) {
            return $model;
        }

        if (str_contains($model, '\\') && config('permission.modules.namespace')) {
            [$moduleName, $modelOrPolicy] = explode('\\', $model, 2);
            $module = sprintf('%s\\%s', config('permission.modules.namespace'), $moduleName);
            if ($moduleModel = sprintf('%s\\%s', $module, $modelOrPolicy)) {
                return $moduleModel;
            }

            if ($modulePolicy = sprintf('%s\\%s', $module, $modelOrPolicy)) {
                return $modulePolicy;
            }
        }

        if ($baseModel = sprintf('%s\\%s', config('permission.models_namespace'), $model)) {
            return $baseModel;
        }

        if ($basePolicy = sprintf('%s\\%s', config('permission.policies_namespace'), $model)) {
            return $basePolicy;
        }

        throw new \Exception('Model or Policy not found');
    }
}