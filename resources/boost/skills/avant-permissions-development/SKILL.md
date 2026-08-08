---
name: avant-permissions-development
description: Build authorization with avantcorp/permissions — policy-derived permissions, the permission:seed command, superuser bypass, and the Vue v-permission / v-role directives.
---

# Avant Permissions Development

## When to use this skill

Use this skill when working in a Laravel application that requires `avantcorp/permissions`, and the task involves:

- writing or changing a policy that should produce permissions,
- seeding or re-seeding the permissions table,
- checking permissions from Vue/Inertia components,
- configuring where policies and models are discovered.

## Overview

`avantcorp/permissions` is a thin layer on top of `spatie/laravel-permission`. Instead of
maintaining a list of permission names by hand, permission names are **derived from your policy
methods**: a public method `viewAny` on `InvoicePolicy` becomes the permission `viewAnyInvoice`.
The `permission:seed` command reflects over your policies and syncs the `permissions` table to match.

It also ships a Vue plugin that exposes `v-permission` and `v-role` directives, backed by a batching
endpoint that runs the real `Gate` on the server.

## Setup

The service provider is auto-discovered. Two things must be wired up by the application:

1. **Register the check route** (usually in `routes/web.php`). This registers `POST` at the given
   path, named `permissions.check`, with the `web` and `auth` middleware:

```php
use Avant\Permissions\Permissions;

Permissions::route('/permissions/check');
```

2. **Install the Vue plugin** in `resources/js/app.ts`:

```ts
import Permissions from '../../vendor/avantcorp/permissions/resources/js/Permissions'

createInertiaApp({
    withApp(app) {
        app.use(Permissions).use(AutoFocus).use(HotKey);
    },
});
```

The frontend expects the host application to share `auth.permissions` (array of permission names)
and `auth.roles` (array of role names) as Inertia page props, and to expose a Wayfinder route module
at `@/routes/permissions` exporting `check`.

## Writing policies

A policy only produces permissions if it implements the `Avant\Permissions\Policy` marker interface.
Use the `PolicyHelper` trait so each method resolves its own permission name:

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

Conventions to follow:

- **Name the class `{Model}Policy`.** The trailing `Policy` is stripped and the remainder is appended
  to the method name to form the permission (`InvoicePolicy::update` → `updateInvoice`).
- **Return `?bool`, and return `null` — not `false` — when the check does not pass.** `hasPermission()`
  already does this. A `null` result lets the superuser fallback (below) run; a hard `false` blocks it.
- **Do not pass the permission name to `hasPermission()`.** It reads the calling method name from the
  backtrace, so it must be called directly from the policy method it belongs to.
- **Only public methods count.** Every public method on the policy becomes a seeded permission, so
  keep helpers `protected` or `private`.
- Abstract policies and interfaces are skipped; only instantiable classes are reflected.

## Superuser bypass

The service provider registers a `Gate::after` callback: any user with the `superuser` role
(`Permissions::SUPERUSER`) is granted every ability whose policy returned `null`. The `superuser`
role is created by `permission:seed`. This is why policy methods must return `null` rather than
`false` for a plain "not granted" outcome.

## Seeding permissions

```shell
php artisan permission:seed        # uses config('auth.defaults.guard')
php artisan permission:seed api    # seeds for a specific guard
```

The command:

1. creates the `superuser` role for the guard if missing,
2. inserts any policy-derived permission that is not yet in the database,
3. **deletes permissions that no longer match a policy method**,
4. resets the `spatie/laravel-permission` cache.

Because step 3 is destructive, run it after renaming or removing a policy method, and expect role
assignments for removed permissions to disappear with them. Re-run it in deployment after any policy
change.

## Checking permissions in Vue

Register the plugin (see Setup), then use the directives. The element is removed from the DOM when
the check fails, and carries `v-cloak` until the answer arrives:

```vue
<!-- ability plus model in one string -->
<button v-permission="'update-Invoice'">Edit</button>

<!-- object form -->
<button v-permission="{ ability: 'update', model: 'Invoice', id: invoice.id }">Edit</button>

<!-- ability as the directive argument -->
<button v-permission:update="{ model: 'Invoice', id: invoice.id }">Edit</button>
<button v-permission:update-Invoice="invoice.id">Edit</button>
<button v-permission:create-Invoice>Create</button>
```

Notes:

- `model` may be a bare model name (`Invoice`), a fully-qualified class (`\App\Models\Invoice`, or
  with `/` separators), or a policy name (`InvoicePolicy`). It is resolved against
  `permission.models_namespace`, `permission.policies_namespace`, and the module namespaces.
- Passing an `id` makes the check load that record and run a model-level policy check. Without an
  `id`, the check is class-level.
- Checks are deduplicated and batched: the plugin waits ~100 ms, then posts all pending checks in one
  request to `permissions.check` and caches results per key for the page's lifetime.
- Class-level checks with no `id` are answered from the shared `auth.permissions` prop without a
  request when the permission is obviously absent.
- The `v-role` directive checks the shared `auth.roles` prop instead of the `Gate`, so it needs no
  request. It keeps the element when the user has the role and removes it otherwise:

```vue
<button v-role:admin>Settings</button>
<button v-role="'admin'">Settings</button>
```

Since results are cached per key, a permission that changes mid-session is not re-checked until the
page is reloaded.

## Listing permissions in PHP

`Avant\Permissions\Permissions` is a container singleton for building permission-management UIs:

```php
use Avant\Permissions\Permissions;

app(Permissions::class)->byPolicy(); // ['Invoice' => [id => 'View Any', ...], ...]
app(Permissions::class)->all();      // [id => 'View Any Invoice', ...]
```

Both return human-readable labels keyed by the permission's database id, suitable for a role editor.

## Configuration

Config is merged from the package under the `permission` key (alongside spatie's own). Publish or
override in `config/permission.php`:

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
the `model` string sent from the frontend. Add `modules.namespace` only if the application uses a
modular layout; leave it `null` otherwise.