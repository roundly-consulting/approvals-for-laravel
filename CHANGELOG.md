# Changelog

All notable changes to `approvals-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

Initial public release.

### Added

- Approvals between any two Eloquent models: an actor (user, team, service account) approves,
  rejects, requests or cancels a decision on any approvable model, with an optional reason.
- Fluent `Approvals::for($model)->as($actor)` builder and the `GivesApprovals` / `HasApprovals`
  traits with helpers such as `approve()`, `reject()`, `hasApproved()` and `pendingApprovals()`.
- Explicit decision statuses (`pending`, `approved`, `rejected`, `cancelled`, `expired`) and
  optional expiry, swept by `Approvals::expire()` or the `approvals:expire` command.
- Multi-approver requests that resolve automatically under an `ApprovalRule`: unanimous, quorum,
  any-one or weighted thresholds.
- Sequential, staged approval pipelines with `requestStagedApproval()` and `approvalProgress()`.
- Time-boxed delegation of approval authority (`delegateApprovalsTo()`).
- Named workflow presets from config (`workflow('payout')`).
- Gate-based authorisation of who may decide.
- Blade directives `@approved`, `@rejected` and `@pendingApproval`.
- Events for every transition, plus one umbrella `ApprovalStatusChanged` event.
- Pest expectations (`toBeApproved()`, `toBePendingApproval()`, `toBeRejected()`) and the
  `InteractsWithApprovals` test helpers.
