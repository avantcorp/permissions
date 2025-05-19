<?php

namespace Avant\Permissions;

use Illuminate\Foundation\Auth\User;

trait PolicyHelper
{
    protected function getPolicyModel(): string
    {
        return str(static::class)
            ->classBasename()
            ->beforeLast('Policy')
            ->toString();
    }

    protected function hasPermission(User $user): ?bool
    {
        $functionName = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'];

        return $user->hasPermissionTo($functionName.$this->getPolicyModel()) ?: null;
    }
}