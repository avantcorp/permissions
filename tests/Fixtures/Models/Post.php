<?php

declare(strict_types=1);

namespace Avant\Permissions\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['published' => 'boolean'];
    }
}
