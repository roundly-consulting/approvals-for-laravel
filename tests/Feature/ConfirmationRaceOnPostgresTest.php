<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Approvals\Enums\ConfirmationStatus;
use RoundlyConsulting\Approvals\Events\ApprovalConfirmationConfirmed;
use RoundlyConsulting\Approvals\Events\ApprovalConfirmationDeclined;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Models\ApprovalConfirmation;
use RoundlyConsulting\Approvals\Tests\Fixtures\SecondSession;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * Two sessions answer one confirmation at once, on a real engine. The first answer runs in a
 * transaction the test keeps open — the row it moved stays locked — while a forked second
 * session sends the competing answer, blocks on that row, and decides only after the first
 * commits. Exactly one may win. SQLite serialises whole-database writes, so only Postgres
 * shows it; ConfirmationTest replays the same interleaving deterministically on every driver.
 */
$notPostgres = fn (): bool => DriverMatrix::driver() !== 'pgsql';

beforeEach(function (): void {
    config()->set('database.connections.probe', DriverMatrix::connectionConfig('pgsql'));
    DB::purge('probe');

    $this->person = ReviewerTestModel::create();
    $this->confirmation = Approvals::confirmation(ReleaseTestModel::create())
        ->payload('tool-call-hash')
        ->by($this->person)
        ->expiresIn(600)
        ->request();
});

function confirmationIsolation(?string $level): void
{
    if ($level !== null) {
        DB::statement("set session characteristics as transaction isolation level {$level}");
    }
}

/**
 * The second session's answer, on rows it reads itself.
 */
function racingAnswer(int $confirmationId, int $personId, string $verb): Closure
{
    return static function () use ($confirmationId, $personId, $verb): string {
        $confirmation = ApprovalConfirmation::query()->findOrFail($confirmationId);
        $person = ReviewerTestModel::query()->findOrFail($personId);

        $answered = $verb === 'confirm'
            ? Approvals::confirm($confirmation, $person, 'tool-call-hash')
            : Approvals::decline($confirmation, $person, 'racing');

        return $answered->status->value;
    };
}

it('lets exactly one of two concurrent confirms succeed', function (?string $level): void {
    confirmationIsolation($level);
    Event::fake([ApprovalConfirmationConfirmed::class]);
    $pid = 0;
    $file = '';

    DB::transaction(function () use (&$pid, &$file, $level): void {
        Approvals::confirm($this->confirmation, $this->person, 'tool-call-hash');

        [$pid, $file] = SecondSession::run(racingAnswer($this->confirmation->id, $this->person->id, 'confirm'), $level);

        SecondSession::waitUntilBlocked($file);
    });

    expect(SecondSession::outcome($pid, $file))->toBe('refused:used')
        ->and($this->confirmation->fresh()?->status)->toBe(ConfirmationStatus::Confirmed)
        ->and(ApprovalConfirmation::query()->where('status', 'confirmed')->count())->toBe(1);

    // Only the winner announced itself (the child's events died with it, so this counts the parent's).
    Event::assertDispatchedTimes(ApprovalConfirmationConfirmed::class, 1);
})->with([
    'read committed' => [null],
    'repeatable read' => ['repeatable read'],
    'serializable' => ['serializable'],
])->skip($notPostgres, 'needs a real engine (and pcntl + posix for the second process)');

it('refuses a confirm that races a decline, and a decline that races a confirm', function (string $first, string $second, string $expected, ConfirmationStatus $final): void {
    Event::fake([ApprovalConfirmationConfirmed::class, ApprovalConfirmationDeclined::class]);
    $pid = 0;
    $file = '';

    DB::transaction(function () use (&$pid, &$file, $first, $second): void {
        racingAnswer($this->confirmation->id, $this->person->id, $first)();

        [$pid, $file] = SecondSession::run(racingAnswer($this->confirmation->id, $this->person->id, $second));

        SecondSession::waitUntilBlocked($file);
    });

    expect(SecondSession::outcome($pid, $file))->toBe($expected)
        ->and($this->confirmation->fresh()?->status)->toBe($final);
})->with([
    'decline first' => ['decline', 'confirm', 'refused:declined', ConfirmationStatus::Declined],
    'confirm first' => ['confirm', 'decline', 'refused:used', ConfirmationStatus::Confirmed],
])->skip($notPostgres, 'needs a real engine (and pcntl + posix for the second process)');

it('lets a confirm win over a sweep that races it', function (): void {
    $pid = 0;
    $file = '';

    // The confirm lands first and holds the row; the sweep's conditional write then finds it
    // no longer pending and lapses nothing.
    DB::transaction(function () use (&$pid, &$file): void {
        Approvals::confirm($this->confirmation, $this->person, 'tool-call-hash');

        [$pid, $file] = SecondSession::run(static fn (): int => Approvals::expireConfirmations(now()->addHour()));

        SecondSession::waitUntilBlocked($file);
    });

    expect(SecondSession::outcome($pid, $file))->toBe('done:0')
        ->and($this->confirmation->fresh()?->status)->toBe(ConfirmationStatus::Confirmed);
})->skip($notPostgres, 'needs a real engine (and pcntl + posix for the second process)');
