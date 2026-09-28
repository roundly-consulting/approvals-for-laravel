# Changelog

All notable changes to `approvals-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

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
  return the number of decisions and requests lapsed.

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
- Delegation built fluently fired `ApprovalDelegated` before its window was set and saved
  `from()` / `until()` straight onto the row, so a window ending before it started was never
  rejected. Delegations are now created by a lazy `grant()` that validates the whole window
  first and announces the final one.
