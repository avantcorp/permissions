<?php

declare(strict_types=1);

namespace Avant\Permissions\Tests\Fixtures\Policies;

use Avant\Permissions\Policy;
use Avant\Permissions\PolicyHelper;
use Avant\Permissions\Tests\Fixtures\Models\Post;
use Avant\Permissions\Tests\Fixtures\Models\User;

class PostPolicy implements Policy
{
    use PolicyHelper;

    public function viewAny(User $user): ?bool
    {
        return $this->hasPermission($user);
    }

    /** Only published posts are viewable, so the loaded record is observable in the result. */
    public function view(User $user, Post $post): ?bool
    {
        return $this->hasPermission($user) && $post->published ?: null;
    }

    public function update(User $user, Post $post): ?bool
    {
        return $this->hasPermission($user);
    }
}
