<?php

declare(strict_types=1);

namespace Avant\Permissions\Tests\Fixtures\Policies;

use Avant\Permissions\Policy;
use Avant\Permissions\PolicyHelper;
use Avant\Permissions\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;

/** A policy with no model behind it: the attribute points the Gate back at this class. */
#[UsePolicy(AdminPolicy::class)]
class AdminPolicy implements Policy
{
    use PolicyHelper;

    public function access(User $user): ?bool
    {
        return $this->hasPermission($user);
    }
}
