# Approvals for Laravel

Record polymorphic approvals between Eloquent models for Laravel.

Any model can act as an **actor** that gives approvals (a user, a team, a service account),
and any model can be **approvable** (a deployment, a document, a comment). Approvals are
stored polymorphically, are toggleable, and emit an event each time one is created or
removed so you can hook in your own workflow.

## Requirements

- PHP 8.3 or 8.4
- Laravel 12 or 13

## Installation

```bash
composer require roundly-consulting/approvals-for-laravel
```

Publish and run the migration:

```bash
php artisan vendor:publish --tag="approvals-migrations"
php artisan migrate
```

The migration is also auto-discovered, so the package works without publishing it. Publish
it only when you want to customise the schema.

Optionally publish the config file:

```bash
php artisan vendor:publish --tag="approvals-config"
```

## Configuration

The published `config/approvals.php` contains a single key:

```php
<?php

declare(strict_types=1);

use RoundlyConsulting\Approvals\Models\Approval;

return [
    // The Eloquent model used to store approvals. Override this to extend the
    // default model with your own behaviour. The replacement MUST extend
    // RoundlyConsulting\Approvals\Models\Approval.
    'model' => Approval::class,
];
```

| Key | Type | Default | Purpose |
|---|---|---|---|
| `model` | `class-string<Approval>` | `RoundlyConsulting\Approvals\Models\Approval::class` | The model used to persist approvals. Must extend the package's `Approval` model. |

## Usage

Add the `GivesApprovals` trait to the model that hands out approvals, and the `HasApprovals`
trait to the model that receives them:

```php
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Traits\GivesApprovals;
use RoundlyConsulting\Approvals\Traits\HasApprovals;

class User extends Model
{
    use GivesApprovals;
}

class Deployment extends Model
{
    use HasApprovals;
}
```

### Toggling an approval

`toggleApproval()` creates the approval if it doesn't exist and removes it (soft delete) if
it does. It returns `true` when the approval was created and `false` when it was removed:

```php
$user = auth()->user();
$deployment = Deployment::find(5);

$user->toggleApproval($deployment); // true  — approval created
$user->toggleApproval($deployment); // false — approval removed
$user->toggleApproval($deployment); // true  — approval created again
```

### Querying approvals

```php
// All approvals an actor has given (Eloquent collection).
$user->approvals;

// All approvals an entity has received.
$deployment->approvals;

// Has this actor approved the entity?
$user->hasApproved($deployment); // bool

// Has this entity been approved by the actor?
$deployment->hasBeenApprovedBy($user); // bool
```

### Reacting to approvals

Every call to `toggleApproval()` dispatches `RoundlyConsulting\Approvals\Events\ApprovalToggled`.
Listen for it to run your own logic when an approval is given or revoked:

```php
use RoundlyConsulting\Approvals\Events\ApprovalToggled;

class NotifyOnApproval
{
    public function handle(ApprovalToggled $event): void
    {
        // $event->actor           — the model that toggled the approval
        // $event->entity          — the model being approved
        // $event->hasBeenApproved — true if just approved, false if removed
    }
}
```

### The Approval model

Approvals are stored in the `approvals` table via `RoundlyConsulting\Approvals\Models\Approval`.
The model uses soft deletes and ships a factory:

```php
use RoundlyConsulting\Approvals\Models\Approval;

$approval = Approval::factory()->create();

$approval->actor;       // the morphTo actor
$approval->approvable;  // the morphTo approvable
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for what has changed recently.

## License

The MIT License (MIT). Please see [LICENSE](LICENSE.md) for more information.
