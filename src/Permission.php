<?php

declare(strict_types=1);

namespace Avant\Permissions;

use Attribute;

#[Attribute]
class Permission
{
    public const string SUPERUSER = 'superuser';
}
