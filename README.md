<!-- roundly-hero:start -->
<p align="center">
  <a href="https://roundly-consulting.com/open-source/docs/approvals-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=approvals-for-laravel">
    <img src="art/hero.png" alt="Approvals for Laravel — Roundly open source" width="100%">
  </a>
</p>
<!-- roundly-hero:end -->

<!-- roundly-badges:start -->
<p align="center">
  <a href="https://packagist.org/packages/roundly-consulting/approvals-for-laravel"><img src="https://img.shields.io/packagist/v/roundly-consulting/approvals-for-laravel?style=flat-square&label=release" alt="Latest release"></a>
  <a href="https://github.com/roundly-consulting/approvals-for-laravel/actions/workflows/run-tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/approvals-for-laravel/run-tests.yml?branch=main&style=flat-square&label=tests" alt="Tests"></a>
  <a href="https://github.com/roundly-consulting/approvals-for-laravel/actions/workflows/fix-php-code-style-issues.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/approvals-for-laravel/fix-php-code-style-issues.yml?branch=main&style=flat-square&label=code%20style" alt="Code style"></a>
  <a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/donate-support%20our%20open%20source-F24E29?style=flat-square&logo=stripe&logoColor=white" alt="Donate"></a>
  <a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/patreon-become%20a%20patron-F96854?style=flat-square&logo=patreon&logoColor=white" alt="Patreon"></a>
  <a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=approvals-for-laravel#crypto"><img src="https://img.shields.io/badge/crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=flat-square&logo=bitcoin&logoColor=white" alt="Crypto"></a>
</p>
<!-- roundly-badges:end -->

# Approvals for Laravel

Model approvals, rejections, and multi-approver sign-off between Eloquent models.

Any model can act as an **actor** that decides on things (a user, a team, a service account),
and any model can be **approvable** (a deployment, a document, a comment). Decisions carry an
explicit status (`pending`, `approved`, `rejected`, `cancelled`, `expired`), an optional reason,
and an optional expiry. A subject can also open an **approval request** that needs sign-off from
the approvers it names under a rule (unanimous, quorum, any-one, or **weighted**), resolving
automatically as their decisions come in — only the named approvers (or their delegates) can
decide it. Requests can run as **sequential, staged pipelines**, approvers can **delegate** their
authority for a time window, and common setups can be captured as named **workflow presets**.
Every transition fires an event you can hook into, including a single umbrella
`ApprovalStatusChanged` event.

The original lightweight "toggle" workflow still works as a one-liner.

## Requirements

- PHP 8.4
- Laravel 12 or 13

## Installation

```bash
composer require roundly-consulting/approvals-for-laravel
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag="approvals-migrations"
php artisan migrate
```

The migrations are **not** loaded automatically — publishing them is required. The six files
land in your `database/migrations` with timestamps that preserve their order, so `migrate`
creates the four tables and applies the two schema additions in the order they depend on.

Optionally publish the config file:

```bash
php artisan vendor:publish --tag="approvals-config"
```

## Configuration

The published `config/approvals.php`:

```php
return [
    'model' => RoundlyConsulting\Approvals\Models\Approval::class,
    'request_model' => RoundlyConsulting\Approvals\Models\ApprovalRequest::class,
    'stage_model' => RoundlyConsulting\Approvals\Models\ApprovalRequestStage::class,
    'delegation_model' => RoundlyConsulting\Approvals\Models\ApprovalDelegation::class,
    'key_type' => env('APPROVALS_KEY_TYPE', 'bigint'),
    'authorization' => [
        'enabled' => env('APPROVALS_AUTHORIZATION', false),
        'ability' => 'decide-approval',
    ],
    'expiry' => [
        'default' => null,
    ],
    'workflows' => [
        // see "Workflow presets" below
    ],
];
```

| Key | Type | Default | Purpose |
|---|---|---|---|
| `model` | `class-string<Approval>` | `Approval::class` | Model used to persist decisions. Must extend the package's `Approval`. |
| `request_model` | `class-string<ApprovalRequest>` | `ApprovalRequest::class` | Model used to persist multi-approver requests. Must extend the package's `ApprovalRequest`. |
| `stage_model` | `class-string<ApprovalRequestStage>` | `ApprovalRequestStage::class` | Model used to persist a staged request's stages. Must extend the package's `ApprovalRequestStage`. |
| `delegation_model` | `class-string<ApprovalDelegation>` | `ApprovalDelegation::class` | Model used to persist delegations. Must extend the package's `ApprovalDelegation`. |
| `key_type` | `string` | `'bigint'` (env `APPROVALS_KEY_TYPE`) | Key type of the polymorphic id columns (actor, approvable, subject, request, `decided_by`, delegator, delegate): `bigint`, `uuid` or `ulid`. Read by the migrations, so set it before you migrate; match your models' primary keys. Anything else throws `InvalidConfigurationException`. |
| `authorization.enabled` | `bool` | `false` (env `APPROVALS_AUTHORIZATION`) | When true, every decision path (approve, reject, toggle, ask, cancel) is gated through a Gate ability. Env strings `true`/`1`/`yes`/`on` enable it, `false`/`0`/`no`/`off` disable it, and anything else throws `InvalidConfigurationException`. |
| `authorization.ability` | `string` | `'decide-approval'` | The Gate ability checked against the approvable. |
| `expiry.default` | `int\|null` | `null` | Lifetime in seconds of an approval given without an explicit expiry; it stops counting once that passes. `null` means approvals never expire unless you set one per decision. |
| `workflows` | `array` | `[]` | Named workflow presets (see below). |

The package runs with zero host configuration.

## Usage

Everything goes through one API — the `Approvals` facade, the injectable
`ApprovalsManager` behind it, or the action classes it runs. All three execute the same code.

### The facade

```php
use RoundlyConsulting\Approvals\Facades\Approvals;

// One actor's decision on one approvable
Approvals::for($deployment)->as($user)->because('Looks good to me')->approve();
Approvals::for($deployment)->as($user)->because('Please add tests')->reject(); // withdraws the approval above
Approvals::for($deployment)->as($user)->ask();                 // ask the actor: a pending decision
Approvals::for($deployment)->as($user)->cancel();              // withdraw the actor's live decision
Approvals::for($deployment)->as($user)->toggle();              // simple on/off
Approvals::for($deployment)->as($user)->expiresIn(86400)->approve(); // valid for a day
Approvals::for($deployment)->as($user)->weight(3)->approve();  // override the decision's weight
Approvals::for($invoice)->as($user)->within($request)->approve(); // pin to one request

// Reads
Approvals::for($deployment)->as($user)->isApproved();  // bool
Approvals::for($deployment)->as($user)->isRejected();  // bool
Approvals::for($deployment)->hasPending();             // bool — any pending decision

// Multi-approver requests (flat, staged, or from a workflow preset)
Approvals::request($invoice)->from([$a, $b, $c])->quorum(2)->expiresIn(3600)->open();
Approvals::request($release)->stages([...])->continueOnRejection()->expiringAt($t)->open();
Approvals::request($budget)->workflow('payout')->open([$a, $b, $c]);

Approvals::status($invoice);        // ApprovalStatus of the latest request (Pending when none)
Approvals::progress($invoice);      // ?ApprovalProgress
Approvals::currentStage($release);  // ?ApprovalRequestStage
Approvals::preset('payout');        // WorkflowPreset from config

// Delegation
Approvals::delegations($boss)->to($deputy)->from($monday)->until($friday)->grant();
Approvals::delegations($boss)->revoke($deputy);   // or ->revoke() for all; returns int
Approvals::delegations($boss)->active();          // Collection<ApprovalDelegation>
Approvals::delegationFor($deputy);                // ?ApprovalDelegation in force now

// Housekeeping
Approvals::expire();                // lapse overdue asks, approvals and requests; returns int
Approvals::expire(subjectType: Invoice::class); // only those on invoices (class or morph alias)
```

| Method | Returns | Notes |
|---|---|---|
| `for($approvable)` / `as($actor)` | `PendingApproval` | set the other side with `as()` / `for()` |
| `->within(ApprovalRequest)` | `PendingApproval` | the request must belong to the approvable (else `InvalidApprovalRequestException`) and still be open (else `ClosedApprovalRequestException`) |
| `->because(?string)`, `->weight(int)`, `->expiresIn(int)`, `->expiringAt($t)` | `PendingApproval` | |
| `->approve()` / `->reject()` / `->ask()` | `Approval` | counts towards the pinned request, else the approvable's latest open request; an actor that request does not name gets `UnauthorizedApprovalException`; a closed request gets `ClosedApprovalRequestException` (see [Closed requests](#closed-requests)). `ask()` returns the actor's live decision unchanged when it already holds one |
| `->cancel()` | `?Approval` | withdraws the actor's live decision in the same round a decision would land in, or one it made as a delegate; `null` when there was nothing to withdraw; `ClosedApprovalRequestException` once that round is closed |
| `->toggle()` | `bool` | `true` approved (through `approve()`), `false` withdrawn |
| `->isApproved()` / `->isRejected()` / `->hasPending()` | `bool` | |
| `request($subject)` | `PendingApprovalRequest` | |
| `->from([...])`, `->rule(ApprovalRule, ?quorum)`, `->any()`, `->quorum(n)`, `->weighted(n)` | `PendingApprovalRequest` | flat request |
| `->stages([StageDefinition, ...])`, `->continueOnRejection()` | `PendingApprovalRequest` | staged request; `from()` and `stages()` together throw |
| `->expiresIn(int)` / `->expiringAt($t)` | `PendingApprovalRequest` | |
| `->open()` | `ApprovalRequest` | |
| `->workflow($name)->open([...])` | `ApprovalRequest` | flat list, or one list per stage |
| `status()` / `progress()` / `currentStage()` / `preset()` | see above | reads |
| `delegations($delegator)` | `DelegationsHandle` | `to()`, `revoke(?$delegate)`, `active(?$at)` |
| `->to($delegate)->from($t)->until($t)` or `->for($seconds)` | `PendingDelegation` | nothing is written until `grant()` |
| `->grant()` | `ApprovalDelegation` | validates the window, then fires `ApprovalDelegated` |
| `delegationFor($delegate, ?$at)` | `?ApprovalDelegation` | |
| `expire(?$now, ?$subjectType)` | `int` | the number of decisions and requests lapsed — only those on one subject type (model class or morph alias) when given, app-wide otherwise |

The `Approvals` facade is registered automatically; a global `approvals()` helper returns the
same manager.

### Without the facade

Inject the manager — the same API, no facade:

```php
use RoundlyConsulting\Approvals\ApprovalsManager;

final class ApproveInvoice
{
    public function __construct(private ApprovalsManager $approvals) {}

    public function __invoke(Invoice $invoice, User $user): void
    {
        $this->approvals->for($invoice)->as($user)->approve();
    }
}
```

Or call an action directly:

```php
use RoundlyConsulting\Approvals\Actions\ApproveAction;
use RoundlyConsulting\Approvals\Actions\OpenApprovalRequestAction;
use RoundlyConsulting\Approvals\DataTransferObjects\ApprovalRequestData;
use RoundlyConsulting\Approvals\DataTransferObjects\DecisionData;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;

app(OpenApprovalRequestAction::class)->execute(
    new ApprovalRequestData($invoice, [$a, $b, $c], ApprovalRule::Quorum, quorum: 2),
);

app(ApproveAction::class)->execute($user, $invoice, DecisionData::approved('LGTM'));
```

| Action | Facade path |
|---|---|
| `ApproveAction` | `for()->as()->approve()` |
| `RejectAction` | `for()->as()->reject()` |
| `RequestApprovalAction` | `for()->as()->ask()` |
| `CancelApprovalAction` | `for()->as()->cancel()` |
| `ToggleApprovalAction` | `for()->as()->toggle()` |
| `OpenApprovalRequestAction` | `request()->from()->open()` |
| `RequestStagedApprovalAction` | `request()->stages()->open()` |
| `OpenWorkflowRequestAction` | `request()->workflow()->open()` |
| `DelegateApprovalsAction` | `delegations()->to()->grant()` |
| `RevokeApprovalDelegationAction` | `delegations()->revoke()` |
| `ExpireApprovalsAction` | `expire()` |

### Faking in your tests

`Approvals::fake()` swaps in `ApprovalsFake`, a subtype of `ApprovalsManager`, for the facade
**and** for injected managers. Operations still run against your database; the fake records
each one — whether it came through the facade, an injected manager or a model trait
(`$user->approve($post)`) — so you can assert on it:

```php
use RoundlyConsulting\Approvals\Facades\Approvals;

$fake = Approvals::fake();

$this->post("/invoices/{$invoice->id}/approve");

$fake->assertApproved($invoice, by: $user);   // or Approvals::assertApproved(...)
$fake->assertNothingRejected();
```

| Assert | Negative |
|---|---|
| `assertApproved($approvable, ?$by)` | `assertNothingApproved()` |
| `assertRejected($approvable, ?$by)` | `assertNothingRejected()` |
| `assertAsked($approvable, ?$actor)` | `assertNothingAsked()` |
| `assertCancelled($approvable, ?$by)` | `assertNothingCancelled()` |
| `assertToggled($approvable, ?$by)` | `assertNothingToggled()` |
| `assertOpened($subject, ?$workflow)` | `assertNothingOpened()` |
| `assertDelegated($delegator, ?$to)` | `assertNothingDelegated()` |
| `assertRevoked($delegator, ?$delegate)` | `assertNothingRevoked()` |
| `assertExpired(?$count, ?$subjectType)` — a sweep ran (and lapsed `$count` decisions and requests in total); with `$subjectType`, only sweeps scoped to that type count | `assertNothingExpired()` — nothing lapsed |

`$fake->recorded(?ApprovalOperation $operation)` returns the raw
`RecordedApprovalOperation` list (operation, context models, result) for custom assertions. An
operation that throws is not recorded.

### Models

Add `GivesApprovals` to the model that decides, and `HasApprovals` to the model that is decided
on. Add `RequiresApproval` to a subject that needs multi-approver sign-off. One model may use all
three (a team that approves others' work and needs sign-off itself): the actor side's relation is
`givenApprovals()`, the approvable side's is `approvals()`. The traits are sugar: every state
change they make goes through `ApprovalsManager`, so the fake sees it.

```php
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Traits\GivesApprovals;
use RoundlyConsulting\Approvals\Traits\HasApprovals;
use RoundlyConsulting\Approvals\Traits\RequiresApproval;

class User extends Model
{
    use GivesApprovals;
}

class Deployment extends Model
{
    use HasApprovals;
}

class Release extends Model
{
    use HasApprovals;
    use RequiresApproval;
}
```

### Trait sugar

```php
$user->approve($deployment, 'LGTM');     // Approval
$user->reject($deployment, 'needs work'); // Approval
$user->cancelApproval($deployment);       // ?Approval
$user->toggleApproval($deployment);       // bool (simple on/off)

$user->hasApproved($deployment);          // bool — holds an approval still in force
$user->hasRejected($deployment);          // bool
$user->approvalFor($deployment);          // ?Approval (latest)
$user->givenApprovals;                    // Collection<Approval> — every decision the user recorded

$deployment->hasBeenApprovedBy($user);    // bool
$deployment->hasBeenRejectedBy($user);    // bool
$deployment->isApprovedBy($user);         // bool
$deployment->approvalCount();             // int — approvals still in force
$deployment->pendingApprovals();          // Collection<Approval> — asks not yet past their deadline
$deployment->approvals;                   // Collection<Approval> — every decision on the deployment
```

Each actor holds **one live decision** per slot — a standalone decision on the approvable, or its
decision on one request (or one stage of a staged request). Deciding again changes that decision
rather than adding a second one: approving twice is a no-op, and rejecting after approving
withdraws the approval (its status becomes `cancelled`) so only the rejection counts — and vice
versa. A database unique index guards the slot, so two concurrent approvals by the same actor
are counted once. A new request, or the next stage, is a fresh slot: approving an earlier
request never blocks you from approving the next.

### Multi-approver requests & quorum

```php
use RoundlyConsulting\Approvals\Enums\ApprovalRule;

// Unanimous: every approver must approve; one rejection rejects the request.
$release->requestApproval([$lead, $qa, $pm], ApprovalRule::Unanimous);

// Quorum: 2 of 3 approvals resolve it; it rejects once 2 approvals are no longer reachable.
$release->requestApproval([$lead, $qa, $pm], ApprovalRule::Quorum, quorum: 2);

// Any: the first approval resolves it.
$release->requestApproval([$lead, $qa, $pm], ApprovalRule::Any);

$lead->approve($release);   // decisions flow into the open request automatically
$qa->approve($release);

$release->isApproved();              // bool
$release->isPendingApproval();       // bool
$release->currentApprovalStatus();   // ApprovalStatus
```

**Only the named approvers decide.** The approvers you pass (`requestApproval([...])`,
`Approvals::request($subject)->from([...])`, a `StageDefinition`'s list, or a workflow's
`open([...])`) are stored on the request — per stage for a staged request — and only they, or a
delegate acting for one of them, can decide it. Anyone else gets
`RoundlyConsulting\Approvals\Exceptions\UnauthorizedApprovalException` from `approve()`,
`reject()`, `toggle()` and `ask()`, and nothing is recorded:

```php
$release->requestApproval([$lead, $qa, $pm], ApprovalRule::Quorum, quorum: 2);

$intern->approve($release);   // throws UnauthorizedApprovalException — not a named approver
$release->approvalRequests()->latest('id')->first()->namedApprovers(); // list<NamedApprover>
```

A request opened **without** named approvers (`from([])`, or an `ApprovalRequest` you create
yourself with only `required_approvers`) keeps open semantics: any approver's decision counts,
until `required_approvers` of them have decided. Each approver is stored once, the request can't
require more approvals than it names (`InvalidApprovalRequestException`), and a quorum/weighted
threshold its approvers could never reach is refused when the request opens.

#### Closed requests

Once a request is **closed** — approved, rejected, cancelled or expired — its round is over and
nothing more is recorded on it. A late `approve()`, `reject()`, `toggle()` or `ask()` on its
subject (or pinned to it with `within()`), and a `cancel()` that would withdraw one of its
decisions, throw `RoundlyConsulting\Approvals\Exceptions\ClosedApprovalRequestException`
(its `$request` property is the closed request) — for a delegate too. Repeating the decision an
actor already holds there stays a harmless no-op that returns it:

```php
$release->requestApproval([$lead]);
$lead->approve($release);           // resolves the request as approved

$lead->approve($release);           // no-op: returns the same approval
$lead->reject($release);            // throws ClosedApprovalRequestException
$lead->cancelApproval($release);    // throws ClosedApprovalRequestException

$release->requestApproval([$lead]); // a new round: decisions count towards it again
```

A model that never had a request keeps taking standalone decisions.

### Weighted thresholds

`ApprovalRule::Weighted` (and `Quorum`) resolve on the **summed weight** of approvals rather
than a headcount. The `quorum` value is the weight threshold. Plain quorum keeps working
unchanged because every decision defaults to weight `1`.

Give an actor a weight by implementing `ProvidesApprovalWeight`:

```php
use RoundlyConsulting\Approvals\Interfaces\ProvidesApprovalWeight;

class User extends Model implements ProvidesApprovalWeight
{
    use GivesApprovals;

    public function approvalWeight(?Model $approvable = null): int
    {
        return $this->is_director ? 3 : 1;
    }
}

$release->requestApproval([$director, $analyst], ApprovalRule::Weighted, quorum: 3);
$analyst->approve($release);    // weight 1 — still pending: the director's 3 can still reach it
$director->approve($release);   // weight 3 meets the threshold -> approved
```

A threshold request rejects once the threshold is out of reach: the approval weight in, plus the
weight the undecided named approvers carried when the request opened, falls short of it. Without
named approvers each outstanding slot counts as weight 1 under `Quorum`; an unnamed `Weighted`
request can't know what its approvers weigh, so it only rejects once every slot has decided.
`Unanimous` and `Any` count heads, not weights.

You can also override the weight per decision:
`Approvals::for($release)->as($analyst)->weight(2)->approve()`. Overrides are not anticipated
when checking reachability — give approvers their weight through `ProvidesApprovalWeight` when a
request depends on it.

### Sequential / staged pipelines

A request can run as an ordered set of stages. Stage *N* only opens once stage *N-1* has
cleared; by default a rejection in any stage rejects the whole request.

```php
use RoundlyConsulting\Approvals\DataTransferObjects\StageDefinition;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;

$release->requestStagedApproval([
    new StageDefinition([$eng1, $eng2], ApprovalRule::Unanimous, name: 'engineering'),
    new StageDefinition([$product],     ApprovalRule::Any,       name: 'product'),
]);

$release->currentStage();       // ?ApprovalRequestStage — the open stage
$release->approvalProgress();   // ?ApprovalProgress — counts, current/total stages, percentage()

// The same through the facade:
Approvals::request($release)->stages([...])->continueOnRejection()->expiringAt($deadline)->open();
```

Only the open stage's approvers can decide it — a later stage's approver, or an outsider, gets
`UnauthorizedApprovalException` until that stage opens. A stage needs one approval per named
approver unless its `StageDefinition` says otherwise (`requiredApprovers:`), and it may name
nobody (`new StageDefinition([], ApprovalRule::Any, requiredApprovers: 1)`) to let any approver
decide it.

Pass `rejectOnStageRejection: false` (facade: `continueOnRejection()`) to let the pipeline
continue past a rejected stage, and `expiresAt:` (facade: `expiringAt()`) to stamp an expiry.
Staged requests dispatch `ApprovalStageOpened` whenever a stage opens (including the one after a
rejected stage the pipeline continues past) and `ApprovalStageCleared` when a stage is approved.

### Delegation (proxy authority)

An approver can hand their authority to another model for a window. While the delegation is
active, the delegate's decisions count **as the delegator** — the approval records both the
delegator (as `actor`) and the delegate (as `decided_by`). On a request that names its approvers,
a delegate may decide for a named delegator; a model that is itself a named approver always
decides as itself, even when it also stands in for someone.

```php
use Carbon\CarbonImmutable;

Approvals::delegations($manager)->to($assistant)->until(CarbonImmutable::now()->addWeek())->grant();
// or ->from($start), ->for($seconds) (measured from the start), or no window at all

$manager->delegateApprovalsTo($assistant);                                    // open-ended
$manager->delegateApprovalsTo($assistant, until: CarbonImmutable::now()->addWeek());

$assistant->approve($release);          // counts as $manager
$approval->wasDelegated();              // true
$approval->actor;                       // $manager
$approval->decidedBy;                   // $assistant

$assistant->cancelApproval($release);   // the delegate can withdraw what it decided for $manager

$manager->revokeApprovalDelegation();           // revoke all — active and scheduled (future) ones
$manager->revokeApprovalDelegation($assistant); // revoke one
$manager->approvalDelegations;                  // MorphMany<ApprovalDelegation>
Approvals::delegations($manager)->active();     // the ones in force now
```

Nothing is written until `grant()`: self-delegation and a window that ends before it starts
throw `RoundlyConsulting\Approvals\Exceptions\InvalidDelegationException` and leave no row
behind. `ApprovalDelegated` fires once, with the final window; `ApprovalDelegationRevoked`
fires per revoked delegation.

### Workflow presets

Capture a reusable rule/quorum/stage/expiry setup in `config('approvals.workflows')` so call
sites stay short:

```php
'workflows' => [
    'payout' => [
        'rule' => ApprovalRule::Quorum->value,
        'quorum' => 2,
        'required_approvers' => 3,
        'expiry' => 86400,
    ],
    'release' => [
        'reject_on_stage_rejection' => true,
        'stages' => [
            ['rule' => ApprovalRule::Unanimous->value, 'required_approvers' => 2, 'name' => 'engineering'],
            ['rule' => ApprovalRule::Any->value,       'required_approvers' => 1, 'name' => 'product'],
        ],
    ],
],
```

```php
// Flat preset: a single approver list.
Approvals::request($budget)->workflow('payout')->open([$a, $b, $c]);

// Staged preset: one approver group per stage, in order.
Approvals::request($release)->workflow('release')->open([[$eng1, $eng2], [$product]]);
```

An unknown or malformed preset throws
`RoundlyConsulting\Approvals\Exceptions\UnknownWorkflowException`. A stage's
`required_approvers` is the number of approvals it needs, so its group must name at least that
many approvers (a flat preset's `required_approvers` likewise); otherwise opening throws
`InvalidApprovalRequestException`. A preset's `expiry` lapses the request like `expiresIn()`.

### Authorization

Set `approvals.authorization.enabled` to `true` (or `APPROVALS_AUTHORIZATION=true` / `1` / `yes` /
`on`) to gate every decision path — `approve()`, `reject()`, `toggle()`, `ask()` (checked for the
asked actor) and `cancel()` — through a Gate ability, checked for the model that acts (a
delegate, not its delegator). The package never defines the gate — your app does:

```php
Gate::define('decide-approval', fn ($user, $approvable) => $user->can('review', $approvable));
```

A denied gate throws `RoundlyConsulting\Approvals\Exceptions\UnauthorizedApprovalException`. The
named-approver check on requests is separate and always on.

### Expiry

```php
Approvals::for($budget)->as($cfo)->expiresIn(86400)->approve(); // valid for a day
Approvals::for($budget)->as($cfo)->expiresIn(3600)->ask();      // reply within an hour
Approvals::request($budget)->from([$cfo, $ceo])->expiresIn(604800)->open(); // decide within a week

// Lapse everything overdue (schedule this):
Approvals::expire();

// …or only what belongs to one subject type (a model class or its morph alias):
Approvals::expire(subjectType: Budget::class);
```

```bash
php artisan approvals:expire
```

An approval, ask or request stops counting the moment its expiry passes — reads such as
`isApproved()` / `hasApproved()` / `approvalCount()` and a request's tally ignore it, and a request
past its expiry accepts no more decisions: the next one resolves it as `expired` and is refused
with `ClosedApprovalRequestException` (see [Closed requests](#closed-requests)). The sweep then
records the lapse: decisions move to `expired` (`ApprovalExpired`), requests resolve as `expired`
(`ApprovalRequestResolved`), and `expire()` returns how many of both it lapsed. Given a
`subjectType`, it lapses only decisions on that type of approvable and requests for that type of
subject, so a package sweeping its own approvals leaves the rest of the app alone. An expired
approval frees its actor to approve again. Set `approvals.expiry.default` to give every approval a
lifetime unless the decision sets its own; answering an ask never inherits the ask's deadline.

### Blade directives

```blade
@approved($deployment, $user) Approved by you @endapproved
@rejected($deployment, $user) You rejected this @endrejected
@pendingApproval($deployment) Awaiting a decision @endpendingApproval
```

### Events

Each transition dispatches an event carrying the relevant model:

| Event | Fired when |
|---|---|
| `ApprovalRequested` | a pending decision is asked for (`ask()`) |
| `ApprovalApproved` | a decision is approved |
| `ApprovalRejected` | a decision is rejected |
| `ApprovalCancelled` | a decision is withdrawn (`cancel()`) |
| `ApprovalExpired` | a pending or approved decision lapses |
| `ApprovalRequestResolved` | a request reaches approved, rejected or expired |
| `ApprovalToggled` | `toggle()` / `toggleApproval()` runs |
| `ApprovalStageOpened` | a staged request opens a stage |
| `ApprovalStageCleared` | a staged request clears (approves) a stage |
| `ApprovalDelegated` | an approver delegates authority |
| `ApprovalDelegationRevoked` | a delegation is revoked |
| `ApprovalStatusChanged` | **umbrella** — fired for every status transition alongside the granular events |

Subscribe to `ApprovalStatusChanged` once to observe all transitions: a decision approved,
rejected, withdrawn (including by a toggle or by a change of mind that supersedes it) or
expired, and a request resolved or expired. An `ask()` creates a pending decision rather than
changing one, so it fires only `ApprovalRequested`. The event carries the `subject` (approval
or request), `from`/`to` `ApprovalStatus`, and the `actor`:

```php
use RoundlyConsulting\Approvals\Events\ApprovalStatusChanged;

class AuditApprovalChanges
{
    public function handle(ApprovalStatusChanged $event): void
    {
        // $event->from, $event->to, $event->subject, $event->actor
    }
}
```

```php
use RoundlyConsulting\Approvals\Events\ApprovalApproved;

class NotifyOnApproval
{
    public function handle(ApprovalApproved $event): void
    {
        // $event->approval — the Approval model
    }
}
```

### The simple toggle

```php
$user->toggleApproval($deployment); // true  — approved (status: approved)
$user->toggleApproval($deployment); // false — withdrawn (status: cancelled, soft-deleted)
```

`toggleApproval()` is the one-click on/off form. Toggling on is an `approve()` — the gate, the
request's named approvers, delegation and the open request all apply, and a rejection you hold is
superseded. Toggling off withdraws your live approval and soft-deletes it. Both fire
`ApprovalToggled` and `ApprovalStatusChanged`.

### Test helpers (for host apps)

Opt-in ergonomics for testing your own app, next to `Approvals::fake()` (see
[Faking in your tests](#faking-in-your-tests)). They live under
`RoundlyConsulting\Approvals\Testing` and pull in **no runtime dependency** — Pest is only
touched when you call the registrar.

Pest custom expectations — register once in your `tests/Pest.php`:

```php
use RoundlyConsulting\Approvals\Testing\ApprovalExpectations;

ApprovalExpectations::register();

// then in tests:
expect($release)->toBeApproved();
expect($release)->toBePendingApproval();
expect($release)->toBeRejected();
```

A test-case trait for acting as an approver:

```php
use RoundlyConsulting\Approvals\Testing\InteractsWithApprovals;

uses(InteractsWithApprovals::class);

$this->actingAsApprover($reviewer)->approveAs($release);
$this->rejectAs($release, 'needs work', $otherReviewer);
```

Both helpers go through `ApprovalsManager`, so they are recorded under `Approvals::fake()`.

Factories ship states for the new models too: `ApprovalFactory::weight()/delegated()/forStage()`,
`ApprovalRequestFactory::weighted()/staged()`, plus `ApprovalDelegationFactory` and
`ApprovalRequestStageFactory`.

## Integrates with

### [`enums-for-laravel`](https://github.com/roundly-consulting/enums-for-laravel)

Both package enums — `ApprovalStatus` and `ApprovalRule` — use the shared
`RoundlyConsulting\Enums\Helpers` trait, so they expose the full fleet helper surface on top of
their domain methods (`isPending()`/`isDecided()`/`isFinal()`/`canTransitionTo()`;
`isWeighted()`), with **no lang file to maintain**:

```php
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;

ApprovalStatus::values();          // Collection: ['pending','approved','rejected','cancelled','expired']
ApprovalStatus::labels();          // Collection: ['Pending','Approved','Rejected','Cancelled','Expired']
ApprovalStatus::options();         // Collection of {value, label, name} EnumOption DTOs for selects
ApprovalStatus::validationRule();  // 'in:pending,approved,rejected,cancelled,expired'
ApprovalStatus::tryFromName('Approved');   // ApprovalStatus::Approved

ApprovalStatus::Approved->readable();      // 'Approved' (translated, headline-cased)
ApprovalStatus::Approved->isIn([ApprovalStatus::Approved, ApprovalStatus::Rejected]); // true

ApprovalRule::options();           // Collection<EnumOption> — a ready-made rule picker
ApprovalRule::validationRule();    // 'in:unanimous,quorum,any,weighted'
$rule->readable();                 // 'Unanimous', 'Quorum', 'Any', 'Weighted'
```

Drop `ApprovalStatus::validationRule()` / `ApprovalRule::validationRule()` straight into host
request rules, and `::options()` into a select or JSON payload — both stay in sync with the cases
automatically.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for what has changed recently.

<!-- roundly-support:start -->
## Support our work

This package is free and open source, built and maintained by
[Roundly Consulting](https://roundly-consulting.com/open-source?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=approvals-for-laravel).
If it saves you time, please consider supporting our open-source work — a one-time donation, a
monthly pledge on Patreon or a crypto donation helps fund maintenance, new features and new
packages.

<a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/Donate-Support%20Roundly%20open%20source-F24E29?style=for-the-badge&logo=stripe&logoColor=white" alt="Donate to Roundly open source"></a>
<a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/Patreon-Become%20a%20patron-F96854?style=for-the-badge&logo=patreon&logoColor=white" alt="Become a patron on Patreon"></a>
<a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=approvals-for-laravel#crypto"><img src="https://img.shields.io/badge/Crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=for-the-badge&logo=bitcoin&logoColor=white" alt="Donate crypto: BTC, ETH, BNB or SOL"></a>
<!-- roundly-support:end -->

## License

The MIT License (MIT). Please see [LICENSE](LICENSE.md) for more information.
