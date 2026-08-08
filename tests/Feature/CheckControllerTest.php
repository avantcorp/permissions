<?php

declare(strict_types=1);

use Avant\Permissions\Tests\Fixtures\Models\Comment;
use Avant\Permissions\Tests\Fixtures\Models\Post;

use function Pest\Laravel\actingAs;

function check(array $permissions): Illuminate\Testing\TestResponse
{
    return test()->postJson('/permissions/check', $permissions);
}

beforeEach(function (): void {
    seedPermissions();

    Post::query()->insert([
        ['id' => 1, 'title' => 'First', 'published' => true],
        ['id' => 2, 'title' => 'Second', 'published' => false],
        ['id' => 3, 'title' => 'Third', 'published' => true],
    ]);

    Comment::query()->insert([
        ['id' => 1, 'body' => 'Nice', 'approved' => true],
        ['id' => 2, 'body' => 'Spam', 'approved' => false],
    ]);
});

it('requires authentication', function (): void {
    check([['ability' => 'viewAny', 'model' => 'Post']])
        ->assertUnauthorized();
});

it('checks a class level ability without touching the database', function (): void {
    actingAs(userWith(['viewAnyPost']));

    $queries = queriesAgainst(['posts'], function (): void {
        check([['ability' => 'viewAny', 'model' => 'Post']])
            ->assertOk()
            ->assertExactJson([true]);
    });

    expect($queries)->toBeEmpty();
});

it('checks a model level ability against the loaded record', function (): void {
    actingAs(userWith(['viewPost']));

    check([
        ['ability' => 'view', 'model' => 'Post', 'id' => 1],
        ['ability' => 'view', 'model' => 'Post', 'id' => 2],
    ])->assertExactJson([true, false]);
});

it('loads every id for one model in a single query', function (): void {
    actingAs(userWith(['viewPost']));

    $queries = queriesAgainst(['posts'], function (): void {
        check([
            ['ability' => 'view', 'model' => 'Post', 'id' => 1],
            ['ability' => 'view', 'model' => 'Post', 'id' => 2],
            ['ability' => 'view', 'model' => 'Post', 'id' => 3],
        ])->assertExactJson([true, false, true]);
    });

    expect($queries)->toHaveCount(1);
});

it('deduplicates repeated ids within the batch', function (): void {
    actingAs(userWith(['viewPost', 'updatePost']));

    $queries = queriesAgainst(['posts'], function (): void {
        check([
            ['ability' => 'view', 'model' => 'Post', 'id' => 1],
            ['ability' => 'update', 'model' => 'Post', 'id' => 1],
            ['ability' => 'view', 'model' => 'Post', 'id' => 1],
        ])->assertExactJson([true, true, true]);
    });

    expect($queries)->toHaveCount(1)
        ->and($queries[0])->toContain('in (1)');
});

it('issues one query per distinct model', function (): void {
    actingAs(userWith(['viewPost', 'viewComment']));

    $queries = queriesAgainst(['posts', 'comments'], function (): void {
        check([
            ['ability' => 'view', 'model' => 'Post', 'id' => 1],
            ['ability' => 'view', 'model' => 'Comment', 'id' => 1],
            ['ability' => 'view', 'model' => 'Post', 'id' => 2],
            ['ability' => 'view', 'model' => 'Comment', 'id' => 2],
        ])->assertExactJson([true, true, false, false]);
    });

    expect($queries)->toHaveCount(2);
});

it('groups ids resolved through different model strings into one query', function (): void {
    actingAs(userWith(['viewPost']));

    $queries = queriesAgainst(['posts'], function (): void {
        check([
            ['ability' => 'view', 'model' => 'Post', 'id' => 1],
            ['ability' => 'view', 'model' => Post::class, 'id' => 2],
            ['ability' => 'view', 'model' => str_replace('\\', '/', Post::class), 'id' => 3],
        ])->assertExactJson([true, false, true]);
    });

    expect($queries)->toHaveCount(1);
});

it('preserves the keys and order of the request', function (): void {
    actingAs(userWith(['viewPost', 'viewAnyPost']));

    check([
        'view-Post-2'  => ['ability' => 'view', 'model' => 'Post', 'id' => 2],
        'viewAny-Post' => ['ability' => 'viewAny', 'model' => 'Post'],
        'view-Post-1'  => ['ability' => 'view', 'model' => 'Post', 'id' => 1],
    ])->assertExactJson([
        'view-Post-2'  => false,
        'viewAny-Post' => true,
        'view-Post-1'  => true,
    ]);
});

it('parses the ability-Model shorthand', function (): void {
    actingAs(userWith(['viewAnyPost', 'viewPost']));

    check([
        ['ability' => 'viewAny-Post'],
        ['ability' => 'view-Post', 'id' => 1],
        ['ability' => 'view-Post', 'id' => 2],
    ])->assertExactJson([true, true, false]);
});

it('checks a modelless policy by its policy name', function (): void {
    actingAs(userWith(['accessAdmin']));

    check([['ability' => 'access', 'model' => 'AdminPolicy']])
        ->assertExactJson([true]);
});

/** Without the self-referencing #[UsePolicy] attribute the Gate cannot resolve a policy class. */
it('cannot check a policy that is missing the UsePolicy attribute', function (): void {
    actingAs(userWith(['viewAnyPost']));

    check([['ability' => 'viewAny', 'model' => 'PostPolicy']])
        ->assertExactJson([false]);
});

it('returns false for a record that does not exist', function (): void {
    actingAs(userWith(['viewPost']));

    check([
        ['ability' => 'view', 'model' => 'Post', 'id' => 999],
        ['ability' => 'view', 'model' => 'Post', 'id' => 1],
    ])->assertExactJson([false, true]);
});

it('returns false for an unresolvable model without failing the rest of the batch', function (): void {
    actingAs(userWith(['viewPost', 'viewAnyPost']));

    check([
        ['ability' => 'view', 'model' => 'Nonsense', 'id' => 1],
        ['ability' => 'viewAny', 'model' => 'Nonsense'],
        ['ability' => 'view', 'model' => 'Post', 'id' => 1],
        ['ability' => 'viewAny', 'model' => 'Post'],
    ])->assertExactJson([false, false, true, true]);
});

it('returns false when the user lacks the permission', function (): void {
    actingAs(userWith([]));

    check([
        ['ability' => 'viewAny', 'model' => 'Post'],
        ['ability' => 'view', 'model' => 'Post', 'id' => 1],
    ])->assertExactJson([false, false]);
});

it('grants every ability to a superuser', function (): void {
    actingAs(userWith([], superuser: true));

    check([
        ['ability' => 'viewAny', 'model' => 'Post'],
        ['ability' => 'view', 'model' => 'Post', 'id' => 2],
    ])->assertExactJson([true, true]);
});

it('rejects a malformed batch', function (): void {
    actingAs(userWith([]));

    check([['model' => 'Post']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('0.ability');
});

it('answers an empty batch with an empty result', function (): void {
    actingAs(userWith([]));

    $queries = queriesAgainst(['posts', 'comments'], function (): void {
        check([])->assertOk()->assertExactJson([]);
    });

    expect($queries)->toBeEmpty();
});
