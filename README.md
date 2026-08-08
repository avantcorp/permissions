# avantcorp/permissions

A thin layer over [`spatie/laravel-permission`](https://github.com/spatie/laravel-permission) that
derives permission names from your policies instead of making you maintain a list by hand.

A public method `viewAny` on `InvoicePolicy` *is* the permission `viewAnyInvoice`. Write the policy
method, run `php artisan permission:seed`, and the permission exists. Delete the method and it goes
away again.

It also ships a Vue plugin with `v-permission` and `v-role` directives, backed by a batching endpoint
that runs the real `Gate` on the server — so the frontend can ask about record-level abilities
(`can this user edit *this* invoice?`) without duplicating policy logic in JavaScript.

## Requirements

- PHP 8.4+
- Laravel 12 or 13, with `spatie/laravel-permission` 6.18+
- Vue 3 + Inertia, if you want the frontend directives

## Installation

The package is served from Avant's own Composer repository, so register that first and authenticate
against it:

```shell
composer config repositories.avant composer https://avant.repo.avant.one
composer config --auth http-basic.avant.repo.avant.one token {token}
composer require avantcorp/permissions
```

Replace `{token}` with your Avant repository token. `--auth` writes it to `auth.json`, so keep that
file out of version control.

The service provider is auto-discovered. Publish and run spatie's migrations if you haven't already:

```shell
php artisan vendor:publish --tag="permission-migrations"
php artisan migrate
```

Three things have to be wired up by your application.

**1. Register the check route** from the `then` callback in `bootstrap/app.php`. It registers a
`POST` route named `permissions.check`, behind the `web` and `auth` middleware:

```php
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        //...
        then: function (): void {
            //...
            Avant\Permissions\Permissions::route('permissions/check');
        }
    )
```

The route applies its own middleware, so it doesn't need a `Route::middleware(...)` group around it.

**2. Install the Vue plugin** in `resources/js/app.ts`:

```ts
import Permissions from '../../vendor/avantcorp/permissions/resources/js/Permissions';

createInertiaApp({
    withApp(app) {
        app.use(Permissions);
    },
});
```

**3. Share `auth.permissions` and `auth.roles`** as Inertia page props, in
`app/Http/Middleware/HandleInertiaRequests.php`:

```php
public function share(Request $request): array
{
    return [
        ...parent::share($request),
        'auth' => inertia()->once(fn () => transform($request->user(), fn (User $user): array => [
            //...
            'permissions' => collect($user->getAllPermissions())->pluck('name'),
            'roles'       => $user->getRoleNames(),
        ])),
    ];
}
```

`once()` evaluates the closure a single time per request rather than on every partial reload, and
`transform()` leaves the prop `null` for guests. The plugin reads `auth.permissions` to answer
class-level checks without a request, and `auth.roles` for `v-role`.

Your app also has to expose a Wayfinder route module at `@/routes/permissions` exporting `check`.

### Laravel Boost skill

This package ships a Boost skill that teaches AI coding agents the conventions below. Boost does not
pick up a newly installed package's skills on its own — run:

```shell
php artisan boost:update
```

It prompts with *"New packages with guidelines/skills discovered!"* — select **avantcorp/permissions**
to opt in. You only need to do this once; from then on the skill is updated along with the rest of
your Boost guidelines. Note that the prompt is skipped in non-interactive runs and when Boost is
invoked as a Composer script, so it has to be run by hand at least once.

## Writing policies

A policy contributes permissions only if it implements the `Avant\Permissions\Policy` marker
interface. Use the `PolicyHelper` trait so each method looks up its own permission name.

```php
<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use Avant\Permissions\Policy;
use Avant\Permissions\PolicyHelper;

class InvoicePolicy implements Policy
{
    use PolicyHelper;

    public function viewAny(User $user): ?bool
    {
        return $this->hasPermission($user);
    }

    public function update(User $user, Invoice $invoice): ?bool
    {
        return $this->hasPermission($user) && $invoice->isEditable() ?: null;
    }
}
```

Things to get right:

- **Name the class `{Model}Policy`.** The trailing `Policy` is stripped and the remainder appended to
  the method name: `InvoicePolicy::update` → `updateInvoice`.
- **Return `null`, not `false`, when a check doesn't pass.** `hasPermission()` already does this. A
  `null` lets the superuser fallback run; a hard `false` blocks it.
- **Don't pass a permission name to `hasPermission()`.** It reads the calling method name from the
  backtrace, so it must be called directly from the policy method it belongs to.
- **Only public methods count.** Every public method becomes a seeded permission — keep helpers
  `protected` or `private`.
- **A policy backed by a model doesn't need `#[UsePolicy]`.** Laravel's own convention already pairs
  `Invoice` with `InvoicePolicy`, and checks are sent as `model: 'Invoice'`. The attribute is only
  required for policies with no model behind them (below).
- Abstract policies and interfaces are skipped; only instantiable classes are reflected.

### Policies with no model

A policy doesn't need a model behind it. Add `#[UsePolicy]` pointing the policy at itself — that's
what makes the policy class its own subject:

```php
#[UsePolicy(AdminPolicy::class)]
class AdminPolicy implements Policy
{
    use PolicyHelper;

    public function access(User $user): ?bool
    {
        return $this->hasPermission($user);
    }
}
```

`AdminPolicy::access` seeds `accessAdmin`, and the frontend refers to it as `model: 'AdminPolicy'`.
`Gate::getPolicyFor()` reads `#[UsePolicy]` off whatever class it is handed, so pointing the policy at
itself is what lets the check endpoint resolve a check sent by policy name — without it that check
silently returns `false`. There is no record to load, so these are always class-level checks.

## Seeding permissions

```shell
php artisan permission:seed        # uses config('auth.defaults.guard')
php artisan permission:seed api    # seeds for a specific guard
```

The command:

1. creates the `superuser` role for the guard if it's missing,
2. inserts any policy-derived permission not yet in the database,
3. **deletes permissions that no longer match a policy method**,
4. resets the `spatie/laravel-permission` cache.

Step 3 is destructive: renaming or removing a policy method drops the old permission *and* every role
assignment pointing at it. Re-run the command on deploy after any policy change.

## Superuser bypass

The service provider registers a `Gate::after` callback that grants any user with the `superuser`
role every ability whose policy returned `null`. The role itself is created by `permission:seed`.
This is why policy methods should return `null` rather than `false` for a plain "not granted".

Note that the bypass only applies to abilities whose permission row exists — `hasPermission()` throws
if the permission was never seeded, and the check endpoint reports that as `false`. Keep the seed
command up to date.

## Checking permissions in Vue

Register the plugin, then use the directives. The element is removed from the DOM when the check
fails, and carries `v-cloak` until the answer arrives.

```vue
<!-- ability and model in one string -->
<button v-permission="'update-Invoice'">Edit</button>

<!-- object form, with a record-level check -->
<button v-permission="{ ability: 'update', model: 'Invoice', id: invoice.id }">Edit</button>

<!-- ability as the directive argument -->
<button v-permission:update="{ model: 'Invoice', id: invoice.id }">Edit</button>
<button v-permission:update-Invoice="invoice.id">Edit</button>
<button v-permission:create-Invoice>Create</button>
```

`v-role` checks the shared `auth.roles` prop instead of the `Gate`, so it never makes a request:

```vue
<button v-role:admin>Settings</button>
<button v-role="'admin'">Settings</button>
```

How the plugin behaves:

- `model` may be a bare model name (`Invoice`), a fully-qualified class (`\App\Models\Invoice`, or
  with `/` separators), or a policy name (`InvoicePolicy`, `AdminPolicy`).
- Passing an `id` loads that record and runs a model-level policy check. Without an `id`, the check is
  class-level.
- Class-level checks are answered straight from the shared `auth.permissions` prop when the permission
  is obviously absent, with no request at all.
- Everything else is deduplicated and batched: the plugin waits ~100 ms, posts all pending checks in
  one request, and caches each result for the lifetime of the page.

Because results are cached per key, a permission that changes mid-session isn't re-checked until the
page reloads.

## The check endpoint

You can call `permissions.check` directly if you aren't using the Vue plugin. Post a keyed batch:

```jsonc
{
    "update-Invoice-1": { "ability": "update", "model": "Invoice", "id": 1 },
    "update-Invoice-2": { "ability": "update", "model": "Invoice", "id": 2 },
    "viewAny-Invoice":  { "ability": "viewAny", "model": "Invoice" },
    "access-Admin":     { "ability": "access-AdminPolicy" }
}
```

The response is keyed identically, with a boolean per entry:

```json
{ "update-Invoice-1": true, "update-Invoice-2": false, "viewAny-Invoice": true, "access-Admin": false }
```

Notes:

- `ability` may carry the model as `ability-Model` when `model` is omitted.
- Anything that can't be resolved — unknown model, missing record, unseeded permission — comes back as
  `false` rather than failing the batch.
- Every entry that carries an `id` is grouped by model and loaded with a single `whereKey()` query per
  model, so a batch of 50 checks across 2 models costs 2 queries, not 50.

## Listing permissions in PHP

`Avant\Permissions\Permissions` is a container singleton, handy for building a role editor:

```php
use Avant\Permissions\Permissions;

app(Permissions::class)->byPolicy(); // ['Invoice' => [id => 'View Any', ...], ...]
app(Permissions::class)->all();      // [id => 'View Any Invoice', ...]
```

Both return human-readable labels keyed by the permission's database id. `byPolicy()` sorts policies
alphabetically by display name and lists each policy's permissions in the order the methods are
declared — so order your policy methods deliberately, that's the order the UI will render.

## Configuration

Config is merged under the `permission` key, alongside spatie's own. Override in
`config/permission.php`:

```php
'policy_path' => app_path(),          // where permission:seed scans for policies

'models_namespace'   => '\\App\\Models',
'policies_namespace' => '\\App\\Policies',

'modules' => [
    'namespace'          => '\\App\\Modules',
    'models_namespace'   => 'Models',
    'policies_namespace' => 'Policies',
],
```

`models_namespace`, `policies_namespace` and `modules.namespace` are the lookup order used to resolve
the `model` string sent from the frontend. Set `modules.namespace` only if your app uses a modular
layout; leave it `null` otherwise.

## Testing

```shell
composer test
```

The suite runs on Pest with `orchestra/testbench`.
