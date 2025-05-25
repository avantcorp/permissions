<?php

declare(strict_types=1);

namespace Avant\Permissions;

use Illuminate\Contracts\Auth\Access\Authorizable;

trait PolicyHelper
{
    protected function getPolicyModel(): string
    {
        return str(static::class)
            ->classBasename()
            ->beforeLast('Policy')
            ->toString();
    }

    /** @param \Spatie\Permission\Traits\HasRoles $authorizable */
    protected function hasPermission(Authorizable $authorizable): ?bool
    {
        $functionName = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'];

        return $authorizable->hasPermissionTo($functionName.$this->getPolicyModel()) ?: null;
    }
}
