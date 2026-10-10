# Changelog

All notable changes to `approvals-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

## 1.2.0 - 2026-10-10

### Added

- A round opened from a workflow preset can take its own deadline:
  `Approvals::request($subject)->workflow('payout')->expiresIn(3600)->open($approvers)`, or
  `->expiringAt($at)`. It replaces the preset's `expiry` for that round. A staged preset's round
  gets one deadline for all its stages, the way the preset's own `expiry` works. Without one, the
  round keeps the preset's `expiry`, as before. `OpenWorkflowRequestAction::execute()` takes the
  deadline as an optional fourth argument.

### Fixed

- `Approvals::request($subject)->expiresIn(...)->workflow('payout')->open()` (or `->expiringAt()`)
  no longer drops the expiry. It used to open the round with the preset's own `expiry`, or with no
  deadline at all; the round now gets the expiry you set.

## 1.1.1 - 2026-10-10

If `php artisan migrate` failed for you on MySQL 8 with SQLSTATE 1071, follow the recovery steps
in the [installation docs](https://roundly-consulting.com/open-source/docs/approvals-for-laravel/installation):
republish the migrations with `--force`, drop the empty `approvals` table the failed run left
behind, then migrate.

### Changed

- Maintenance: CI also runs the test suite against MySQL 8, alongside SQLite and PostgreSQL.

### Fixed

- `php artisan migrate` now runs on MySQL 8 with utf8mb4. The `0001_create_approvals_table`
  migration failed there with SQLSTATE 1071, because its `approvals_actor_approvable_status_index`
  came to 3076 bytes, over MySQL's 3072-byte index limit. The index now covers `actor_id`,
  `actor_type`, `approvable_id` and `approvable_type` (`status` keeps its own index). `0001` is
  fixed in place, since no utf8mb4 MySQL install could have run it. An install that already ran it
  (PostgreSQL, or MySQL on utf8mb3) keeps its index and needs nothing. On MySQL, republish the
  migrations with `--force`, drop the empty `approvals` table the failed run left behind, and
  migrate.

## 1.1.0 - 2026-10-10

### Added

- `Approvals::for($subject)->close()` closes a subject's open approval round from outside, for a
  host that cancels or expires the subject itself. It closes as cancelled by default, or pass
  `ApprovalStatus::Expired`; `->within($request)` closes only that round. The round closes the way
  the engine closes one it resolves: its outstanding asks are cancelled (`ApprovalCancelled` and
  `ApprovalStatusChanged` pending → cancelled for each), then `ApprovalRequestResolved` and
  `ApprovalStatusChanged` fire for the round. A round that is already closed, or that a decision
  resolved first, is left alone and nothing fires. A round past its expiry closes as expired. It
  returns the number of rounds it closed, and any other outcome throws
  `InvalidStatusTransitionException`. The same operation is available as
  `CloseApprovalRequestAction`.
- `Approvals::fake()` records closes as `ApprovalOperation::Close`, with `assertClosed($subject,
  ?$outcome)` and `assertNothingClosed()`. If you `match` over `ApprovalOperation` without a
  default arm, add the new case.

## 1.0.1 - 2026-10-05

### Changed

- New migration `0007_change_approval_request_id_to_bigint`. To upgrade, publish it
  (`php artisan vendor:publish --tag=approvals-migrations`; the migrations you already published
  stay as they are) and run `php artisan migrate`. It only acts on PostgreSQL with `key_type`
  `uuid`; anywhere else it does nothing.
- Opening a request or stage that could never be approved now throws
  `InvalidApprovalRequestException`: a request without named approvers under the default
  `unanimous` rule, `quorum` / `weighted` with neither a quorum nor `requiredApprovers`, or
  `requiredApprovers: 0`. Before, such a request opened and never resolved. To open one without
  names, use `->any()`, `->quorum(n)` or `requiredApprovers: n`.
- Toggling an approval off now also dispatches `ApprovalCancelled`, as `cancel()` does, and a
  retired ask dispatches it too. If you listen to both `ApprovalCancelled` and `ApprovalToggled`,
  check that a toggle-off is not handled twice.
- `ApprovalDelegation::revoke($at)` with a moment in the future now throws
  `InvalidDelegationException`. A revocation takes effect at once and cannot be scheduled; give
  the delegation an end date (`->until()`) instead.
- Documentation: the README banner loads from an absolute URL, so it also shows on Packagist.
- Maintenance: the `composer.json` `homepage` and `support.docs` point at the documentation site.

### Fixed

- Rejecting an ask no longer keeps the ask's reply-by deadline. Before, once that deadline passed
  the rejection stopped counting, and every later approve, reject, toggle or ask by that actor on
  the model failed with `InvalidStatusTransitionException`. Slots already stuck this way free
  themselves on the actor's next decision.
- PostgreSQL with `key_type` `uuid`: every decision on a request failed, because
  `approvals.approval_request_id` was a uuid column pointing at the bigint `approval_requests.id`.
  A new migration turns it into a bigint. See **Changed**.
- When a request resolves (approved, rejected or expired), the asks still outstanding in it are
  cancelled, and so are a stage's asks when the stage settles. Before, they stayed pending forever,
  and the asked actor could neither answer nor withdraw them.
- `currentStage()` (facade, trait and model) and `progress()->currentStage` return `null` once a
  staged request is closed or past its expiry. Before, they reported a stage of a finished request.
- Withdrawing a decision (`cancel()` / `cancelApproval()`) on a staged request only reaches the
  open stage. Before, it could cancel a decision of a stage that had already settled.
- `progress()` / `approvalProgress()` report `expired` for a request past its expiry, as
  `status()` already did.

### Security

- A delegate whose delegation was revoked or has ended could still withdraw the decisions it had
  made for the delegator. It no longer can; only the delegator can withdraw them now.

## 1.0.0 - 2026-10-03

Initial public release.

### Added

- Approvals between any two Eloquent models: an actor (user, team, service account) approves,
  rejects, requests or cancels a decision on any approvable model, with an optional reason.
- One public API in three layers: the `Approvals` facade, the injectable `ApprovalsManager`
  behind it, and the action classes it runs. `Approvals::for($model)->as($actor)` decides
  (`approve/reject/ask/cancel/toggle`, `within($request)`, `weight()`, reads `isApproved()`,
  `isRejected()`, `hasPending()`); `Approvals::request($subject)` opens flat, staged or
  workflow requests (`from/rule/any/quorum/weighted/stages/continueOnRejection/expiresIn/
  expiringAt/workflow`, terminal `open()`); `status()`, `progress()`, `currentStage()`,
  `preset()`; `delegations($boss)->to()->…->grant()`, `->revoke()`, `->active()`,
  `delegationFor()`; `expire()`.
- `Approvals::fake()` — a recording, still-performing `ApprovalsFake` (a manager subtype, so
  injected managers get it too) with `assertApproved/Rejected/Asked/Cancelled/Toggled/Opened/
  Delegated/Revoked/Expired` and an `assertNothing*` for each, covering calls made through the
  model traits.
- `OpenApprovalRequestAction` for flat requests (`RequiresApproval::requestApproval()` now
  delegates to it through the manager).
- The `GivesApprovals` / `HasApprovals` / `RequiresApproval` traits with helpers such as
  `approve()`, `reject()`, `hasApproved()` and `pendingApprovals()`; every state change goes
  through the manager.
- Explicit decision statuses (`pending`, `approved`, `rejected`, `cancelled`, `expired`) and
  optional expiry, swept by `Approvals::expire()` or the `approvals:expire` command.
- Multi-approver requests that resolve automatically under an `ApprovalRule`: unanimous, quorum,
  any-one or weighted thresholds.
- Sequential, staged approval pipelines with `requestStagedApproval()` and `approvalProgress()`.
- Time-boxed delegation of approval authority (`Approvals::delegations($boss)->to($deputy)`,
  `delegateApprovalsTo($deputy, from:, until:)`).
- Named workflow presets from config (`Approvals::request($x)->workflow('payout')->open()`).
- Gate-based authorisation of who may decide.
- Blade directives `@approved`, `@rejected` and `@pendingApproval`.
- Events for every transition, plus one umbrella `ApprovalStatusChanged` event.
- Pest expectations (`toBeApproved()`, `toBePendingApproval()`, `toBeRejected()`) and the
  `InteractsWithApprovals` test helpers.

### Changed

- The manager moved from `Support\ApprovalManager` to `RoundlyConsulting\Approvals\ApprovalsManager`
  and is no longer `final` (the fake extends it).
- `PendingApproval::request()` is now `ask()`; `PendingApproval::workflow()` moved to
  `Approvals::request($subject)->workflow($name)`, whose terminal is `open()` (was `request()`).
- `Approvals::delegate($from, $to)` is replaced by `Approvals::delegations($from)->to($to)`, and
  the builder's terminal is `grant()` (`save()` and `delegation()` are gone).
- `GivesApprovals::delegateApprovalsTo($delegate, ?$from, ?$until)` now grants immediately and
  returns the `ApprovalDelegation` instead of a builder.
- `RequiresApproval::requestStagedApproval()` accepts an optional `$expiresAt`.
- A decision pinned to a request (`within()`, or the actions' `$request` argument) whose subject
  is not the approvable throws `InvalidApprovalRequestException`.
- The actor-side relation of `GivesApprovals` is `givenApprovals()` (was `approvals()`), so one
  model can use both `GivesApprovals` and `HasApprovals`.
- The `approvals.default_status` config key is gone: a toggle always approves.
- `approvals:expire` / `Approvals::expire()` also lapse overdue approvals and requests, and
  return the number of decisions and requests lapsed. `Approvals::expire(subjectType: X::class)`
  (and `ExpireApprovalsAction::execute($now, $subjectType)`) lapses only decisions on that type
  of approvable and requests for that type of subject; the fake's `assertExpired()` takes the
  same `$subjectType`.
- A decision pinned to an expired request throws `ClosedApprovalRequestException` (was
  `InvalidApprovalRequestException`).

### Fixed

- A request's approvers are stored (per request, and per stage) and enforced: an actor it does
  not name — and who is not the delegate of one it names — gets `UnauthorizedApprovalException`
  from approve, reject, toggle and ask. Requests opened without named approvers still accept
  any approver.
- An actor holds one live decision per request, stage or standalone slot, guarded by a unique
  index: concurrent approvals by one actor count once, changing your mind withdraws the earlier
  decision, and approving an earlier request no longer blocks approving the next one or a later
  stage.
- Weighted and quorum requests compare their threshold in approver weight, so a light approver
  deciding first no longer rejects the request; unreachable thresholds are refused at open.
- A resolving request (and each stage) is finalized with a conditional update, so concurrent
  decisions resolve it once.
- Decision and request expiry is enforced: approvals and requests stop counting once their
  expiry passes and the sweep lapses them; `approvals.expiry.default` is applied.
- Every decision path (toggle, ask and cancel included) goes through the authorization gate,
  and `APPROVALS_AUTHORIZATION=1` / `yes` / `on` enable it.
- Revoking delegations also revokes scheduled (future) ones; a delegate can withdraw what it
  decided on the delegator's behalf; a toggle over a held rejection approves.
- `ApprovalStatusChanged` fires for toggles and superseded decisions, and a stage opened after a
  rejected one fires `ApprovalStageOpened`.
- A closed request (approved, rejected, cancelled or expired) records nothing more: a late
  approve, reject, toggle or ask on its subject or pinned to it, and a withdrawal of one of its
  decisions, throw `ClosedApprovalRequestException` — for delegates too, and also when the round
  closes mid-write (the request row is locked). Repeating a decision the actor already holds
  there stays a no-op; a model that never had a request still takes standalone decisions.
- Delegation built fluently fired `ApprovalDelegated` before its window was set and saved
  `from()` / `until()` straight onto the row, so a window ending before it started was never
  rejected. Delegations are now created by a lazy `grant()` that validates the whole window
  first and announces the final one.
