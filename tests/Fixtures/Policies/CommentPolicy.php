<?php

declare(strict_types=1);

namespace Avant\Permissions\Tests\Fixtures\Policies;

use Avant\Permissions\Policy;
use Avant\Permissions\PolicyHelper;
use Avant\Permissions\Tests\Fixtures\Models\Comment;
use Avant\Permissions\Tests\Fixtures\Models\User;

class CommentPolicy implements Policy
{
    use PolicyHelper;

    public function viewAny(User $user): ?bool
    {
        return $this->hasPermission($user);
    }

    public function view(User $user, Comment $comment): ?bool
    {
        return $this->hasPermission($user) && $comment->approved ?: null;
    }
}
