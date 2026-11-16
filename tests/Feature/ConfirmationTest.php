<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Approvals\Actions\ConfirmAction;
use RoundlyConsulting\Approvals\Actions\DeclineAction;
use RoundlyConsulting\Approvals\Actions\RequestConfirmationAction;
use RoundlyConsulting\Approvals\ApprovalsManager;
use RoundlyConsulting\Approvals\Builders\PendingConfirmation;
use RoundlyConsulting\Approvals\Enums\ConfirmationRefusal;
use RoundlyConsulting\Approvals\Enums\ConfirmationStatus;
use RoundlyConsulting\Approvals\Events\ApprovalConfirmationConfirmed;
use RoundlyConsulting\Approvals\Events\ApprovalConfirmationDeclined;
use RoundlyConsulting\Approvals\Events\ApprovalConfirmationExpired;
use RoundlyConsulting\Approvals\Events\ApprovalConfirmationRequested;
use RoundlyConsulting\Approvals\Exceptions\ConfirmationRefusedException;
use RoundlyConsulting\Approvals\Exceptions\InvalidConfirmationException;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Models\ApprovalConfirmation;
use RoundlyConsulting\Approvals\Tests\ActorTestModel;
use RoundlyConsulting\Approvals\Tests\DeploymentTestModel;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/*
|--------------------------------------------------------------------------
| Single-use confirmations
|--------------------------------------------------------------------------
|
| One named person confirms one exact action — bound to its payload hash — once, before
| it expires. The real two-session race lives in ConfirmationRaceOnPostgresTest.
|
*/

const TOOL_HASH = 'sha256:5f2b9c0e7a14d3';

beforeEach(fn () => CarbonImmutable::setTestNow('2026-11-16 12:00:00'));

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * A pending confirmation about a fresh release, asked of a fresh reviewer.
 *
 * @return array{ApprovalConfirmation, ReviewerTestModel, ReleaseTestModel}
 */
function askForConfirmation(?Closure $configure = null): array
{
    $release = ReleaseTestModel::create();
    $person = ReviewerTestModel::create();

    $pending = Approvals::confirmation($release)->payload(TOOL_HASH)->by($person)->singleUse();

    $confirmation = ($configure === null ? $pending : $configure($pending))->request();

    return [$confirmation, $person, $release];
}

/**
 * The refusal an answer ran into, or null when it was not refused.
 */
function refusalOf(Closure $answer): ?ConfirmationRefusedException
{
    try {
        $answer();
    } catch (ConfirmationRefusedException $refused) {
        return $refused;
    }

    return null;
}

describe('asking for a confirmation', function (): void {
    it('stores a pending confirmation bound to the subject, the person and the hash', function (): void {
        Event::fake([ApprovalConfirmationRequested::class]);

        [$confirmation, $person, $release] = askForConfirmation(
            fn (PendingConfirmation $pending): PendingConfirmation => $pending->expiresIn(CarbonInterval::minutes(5)),
        );

        $stored = ApprovalConfirmation::query()->findOrFail($confirmation->getKey());

        expect($stored->status)->toBe(ConfirmationStatus::Pending)
            ->and($stored->isFor($release))->toBeTrue()
            ->and($stored->isAddressedTo($person))->toBeTrue()
            ->and($stored->payload_hash)->toBe(TOOL_HASH)
            ->and($stored->expires_at?->toDateTimeString())->toBe('2026-11-16 12:05:00')
            ->and($stored->resolved_at)->toBeNull()
            ->and($stored->subject?->is($release))->toBeTrue()
            ->and($stored->person?->is($person))->toBeTrue()
            // The hash binds the action; nothing outside the package needs it rendered.
            ->and($stored->toArray())->not->toHaveKey('payload_hash');

        Event::assertDispatched(ApprovalConfirmationRequested::class, fn (ApprovalConfirmationRequested $event): bool => $event->confirmation->is($confirmation));
    });

    it('takes the expiry as seconds or as a moment', function (Closure $configure, string $expected): void {
        [$confirmation] = askForConfirmation($configure);

        expect($confirmation->expires_at?->toDateTimeString())->toBe($expected);
    })->with([
        'seconds' => [fn (PendingConfirmation $p): PendingConfirmation => $p->expiresIn(90), '2026-11-16 12:01:30'],
        'a moment' => [fn (PendingConfirmation $p): PendingConfirmation => $p->expiringAt(CarbonImmutable::parse('2026-11-17 08:00:00')), '2026-11-17 08:00:00'],
    ]);

    it('expires after approvals.confirmations.expiry when no expiry is given', function (): void {
        [$confirmation] = askForConfirmation();

        // The shipped default: fifteen minutes.
        expect($confirmation->expires_at?->toDateTimeString())->toBe('2026-11-16 12:15:00');

        config()->set('approvals.confirmations.expiry', '60');
        [$configured] = askForConfirmation();

        expect($configured->expires_at?->toDateTimeString())->toBe('2026-11-16 12:01:00');
    });

    it('never expires when approvals.confirmations.expiry is not set', function (mixed $unset): void {
        config()->set('approvals.confirmations.expiry', $unset);

        [$confirmation] = askForConfirmation();

        expect($confirmation->expires_at)->toBeNull()
            ->and($confirmation->isExpired(CarbonImmutable::now()->addYears(10)))->toBeFalse();
    })->with(['null' => [null], 'blank' => ['  ']]);

    it('refuses a malformed approvals.confirmations.expiry', function (mixed $configured): void {
        config()->set('approvals.confirmations.expiry', $configured);

        expect(fn () => askForConfirmation())->toThrow(InvalidConfigurationException::class)
            ->and(ApprovalConfirmation::query()->count())->toBe(0);
    })->with(['zero' => [0], 'a word' => ['soon'], 'negative' => [-5]]);

    it('refuses an incomplete or malformed confirmation and records nothing', function (Closure $request, string $message): void {
        expect($request)->toThrow(InvalidConfirmationException::class, $message)
            ->and(ApprovalConfirmation::query()->count())->toBe(0);
    })->with([
        'no person' => [fn () => Approvals::confirmation(ReleaseTestModel::create())->payload(TOOL_HASH)->request(), 'Call by() first'],
        'no payload' => [fn () => Approvals::confirmation(ReleaseTestModel::create())->by(ReviewerTestModel::create())->request(), 'Call payload() first'],
        'a blank hash' => [fn () => Approvals::confirmation(ReleaseTestModel::create())->payload('  ')->by(ReviewerTestModel::create())->request(), 'non-blank string'],
        'an over-long hash' => [fn () => Approvals::confirmation(ReleaseTestModel::create())->payload(str_repeat('a', 256))->by(ReviewerTestModel::create())->request(), 'at most 255'],
        'an unsaved subject' => [fn () => Approvals::confirmation(new ReleaseTestModel)->payload(TOOL_HASH)->by(ReviewerTestModel::create())->request(), 'subject must be a saved model'],
        'an unsaved person' => [fn () => Approvals::confirmation(ReleaseTestModel::create())->payload(TOOL_HASH)->by(new ReviewerTestModel)->request(), 'person must be a saved model'],
        'an expiry in the past' => [fn () => Approvals::confirmation(ReleaseTestModel::create())->payload(TOOL_HASH)->by(ReviewerTestModel::create())->expiringAt(CarbonImmutable::now()->subSecond())->request(), 'must be in the future'],
        'a zero expiry' => [fn () => Approvals::confirmation(ReleaseTestModel::create())->payload(TOOL_HASH)->by(ReviewerTestModel::create())->expiresIn(0)->request(), 'must be in the future'],
    ]);

    it('takes a 255-character hash', function (): void {
        $hash = str_repeat('f', 255);

        [$confirmation, $person] = askForConfirmation(fn (PendingConfirmation $p): PendingConfirmation => $p->payload($hash));

        expect(Approvals::confirm($confirmation, $person, $hash)->status)->toBe(ConfirmationStatus::Confirmed);
    });

    it('treats singleUse() as a marker: every confirmation is single use', function (): void {
        $release = ReleaseTestModel::create();
        $person = ReviewerTestModel::create();

        $marked = Approvals::confirmation($release)->payload(TOOL_HASH)->by($person)->singleUse()->request();
        $unmarked = Approvals::confirmation($release)->payload(TOOL_HASH)->by($person)->request();

        foreach ([$marked, $unmarked] as $confirmation) {
            Approvals::confirm($confirmation, $person, TOOL_HASH);

            expect(refusalOf(fn () => Approvals::confirm($confirmation, $person, TOOL_HASH))?->refusal)->toBe(ConfirmationRefusal::Used);
        }
    });
});

describe('confirming', function (): void {
    it('confirms for the named person and the matching hash, once', function (): void {
        Event::fake([ApprovalConfirmationConfirmed::class]);

        [$confirmation, $person] = askForConfirmation();

        $confirmed = Approvals::confirm($confirmation, $person, TOOL_HASH);

        expect($confirmed)->toBe($confirmation)
            ->and($confirmed->status)->toBe(ConfirmationStatus::Confirmed)
            ->and($confirmed->resolved_at?->toDateTimeString())->toBe('2026-11-16 12:00:00')
            ->and($confirmed->fresh()?->status)->toBe(ConfirmationStatus::Confirmed)
            ->and($confirmed->isPending())->toBeFalse();

        Event::assertDispatchedTimes(ApprovalConfirmationConfirmed::class, 1);
        Event::assertDispatched(
            ApprovalConfirmationConfirmed::class,
            fn (ApprovalConfirmationConfirmed $event): bool => $event->confirmation->is($confirmation) && $event->person->is($person),
        );
    });

    it('confirms a stale instance by reading the row first', function (): void {
        [$confirmation, $person] = askForConfirmation();

        $stale = ApprovalConfirmation::query()->findOrFail($confirmation->getKey());
        Approvals::confirm($confirmation, $person, TOOL_HASH);

        expect(refusalOf(fn () => Approvals::confirm($stale, $person, TOOL_HASH))?->errorCode())->toBe('used')
            ->and($stale->status)->toBe(ConfirmationStatus::Confirmed);
    });

    it('refuses a different hash as a mismatch and stays pending', function (): void {
        Event::fake([ApprovalConfirmationConfirmed::class]);

        [$confirmation, $person] = askForConfirmation();

        $refused = refusalOf(fn () => Approvals::confirm($confirmation, $person, TOOL_HASH.'-tampered'));

        expect($refused?->refusal)->toBe(ConfirmationRefusal::Mismatch)
            ->and($refused?->errorCode())->toBe('mismatch')
            ->and($refused?->getMessage())->toBe('This confirmation was given for a different action.')
            ->and($refused?->confirmation->is($confirmation))->toBeTrue()
            ->and($confirmation->fresh()?->status)->toBe(ConfirmationStatus::Pending)
            // The right hash still works afterwards.
            ->and(Approvals::confirm($confirmation, $person, TOOL_HASH)->status)->toBe(ConfirmationStatus::Confirmed);

        Event::assertDispatchedTimes(ApprovalConfirmationConfirmed::class, 1);
    });

    it('compares the hash exactly', function (string $given): void {
        [$confirmation, $person] = askForConfirmation();

        expect(refusalOf(fn () => Approvals::confirm($confirmation, $person, $given))?->refusal)->toBe(ConfirmationRefusal::Mismatch);
    })->with([
        'a prefix' => [substr(TOOL_HASH, 0, -1)],
        'other case' => [strtoupper(TOOL_HASH)],
        'padded' => [TOOL_HASH.' '],
        'empty' => [''],
    ]);

    it('refuses a second use', function (): void {
        [$confirmation, $person] = askForConfirmation();

        Approvals::confirm($confirmation, $person, TOOL_HASH);

        $refused = refusalOf(fn () => Approvals::confirm($confirmation, $person, TOOL_HASH));

        expect($refused?->refusal)->toBe(ConfirmationRefusal::Used)
            ->and($refused?->getMessage())->toBe('This confirmation has already been used.');
    });

    it('refuses after expiry, lapsing it once', function (): void {
        Event::fake([ApprovalConfirmationExpired::class, ApprovalConfirmationConfirmed::class]);

        [$confirmation, $person] = askForConfirmation(fn (PendingConfirmation $p): PendingConfirmation => $p->expiresIn(60));

        // Exactly at the expiry it no longer takes an answer.
        CarbonImmutable::setTestNow('2026-11-16 12:01:00');

        expect($confirmation->isExpired())->toBeTrue()
            ->and($confirmation->effectiveStatus())->toBe(ConfirmationStatus::Expired)
            ->and($confirmation->status)->toBe(ConfirmationStatus::Pending);

        $first = refusalOf(fn () => Approvals::confirm($confirmation, $person, TOOL_HASH));
        $second = refusalOf(fn () => Approvals::confirm($confirmation, $person, TOOL_HASH));

        expect($first?->refusal)->toBe(ConfirmationRefusal::Expired)
            ->and($first?->getMessage())->toBe('This confirmation has expired.')
            ->and($second?->refusal)->toBe(ConfirmationRefusal::Expired)
            ->and($confirmation->fresh()?->status)->toBe(ConfirmationStatus::Expired);

        Event::assertDispatchedTimes(ApprovalConfirmationExpired::class, 1);
        Event::assertNotDispatched(ApprovalConfirmationConfirmed::class);
    });

    it('confirms one second before the expiry', function (): void {
        [$confirmation, $person] = askForConfirmation(fn (PendingConfirmation $p): PendingConfirmation => $p->expiresIn(60));

        CarbonImmutable::setTestNow('2026-11-16 12:00:59');

        expect(Approvals::confirm($confirmation, $person, TOOL_HASH)->status)->toBe(ConfirmationStatus::Confirmed);
    });

    it('refuses another person, even with the right hash', function (Closure $stranger): void {
        [$confirmation] = askForConfirmation();

        $refused = refusalOf(fn () => Approvals::confirm($confirmation, $stranger($confirmation), TOOL_HASH));

        expect($refused?->refusal)->toBe(ConfirmationRefusal::NotThePerson)
            ->and($refused?->errorCode())->toBe('not_the_person')
            ->and($refused?->getMessage())->toBe('Only the person this confirmation was requested from can answer it.')
            ->and($confirmation->fresh()?->status)->toBe(ConfirmationStatus::Pending);
    })->with([
        'another reviewer' => [fn (): ReviewerTestModel => ReviewerTestModel::create()],
        // Same table and key, another morph type: still not the person.
        'the same key of another type' => [fn (ApprovalConfirmation $c): ActorTestModel => ActorTestModel::query()->findOrFail($c->person_id)],
    ]);

    it('tells a stranger nothing else: not_the_person comes before every other refusal', function (): void {
        [$confirmation, $person] = askForConfirmation();
        $stranger = ReviewerTestModel::create();

        Approvals::confirm($confirmation, $person, TOOL_HASH);

        expect(refusalOf(fn () => Approvals::confirm($confirmation, $stranger, 'wrong'))?->refusal)->toBe(ConfirmationRefusal::NotThePerson)
            ->and(refusalOf(fn () => Approvals::decline($confirmation, $stranger))?->refusal)->toBe(ConfirmationRefusal::NotThePerson);
    });

    it('refuses to confirm a declined confirmation', function (): void {
        [$confirmation, $person] = askForConfirmation();

        Approvals::decline($confirmation, $person);

        $refused = refusalOf(fn () => Approvals::confirm($confirmation, $person, TOOL_HASH));

        expect($refused?->refusal)->toBe(ConfirmationRefusal::Declined)
            ->and($refused?->getMessage())->toBe('This confirmation has already been declined.');
    });

    it('loses to an answer that lands between its read and its write', function (Closure $interloper, ConfirmationRefusal $expected): void {
        [$confirmation, $person] = askForConfirmation(fn (PendingConfirmation $p): PendingConfirmation => $p->expiresIn(60));

        // The first read of the row is the action's own refresh; the interloper moves the
        // row right after it, before the claim.
        $done = false;
        Event::listen('eloquent.retrieved: '.ApprovalConfirmation::class, function (ApprovalConfirmation $read) use ($interloper, &$done): void {
            if (! $done) {
                $done = true;
                $interloper($read);
            }
        });

        expect(refusalOf(fn () => Approvals::confirm($confirmation, $person, TOOL_HASH))?->refusal)->toBe($expected)
            ->and($done)->toBeTrue()
            // The interloper's write stands; this confirm wrote nothing (it would stamp resolved_at).
            ->and($confirmation->resolved_at)->toBeNull();
    })->with([
        'a concurrent confirm' => [fn (ApprovalConfirmation $c) => $c->newQuery()->whereKey($c->getKey())->update(['status' => 'confirmed']), ConfirmationRefusal::Used],
        'a concurrent decline' => [fn (ApprovalConfirmation $c) => $c->newQuery()->whereKey($c->getKey())->update(['status' => 'declined']), ConfirmationRefusal::Declined],
        'the sweep' => [fn (ApprovalConfirmation $c) => $c->newQuery()->whereKey($c->getKey())->update(['status' => 'expired']), ConfirmationRefusal::Expired],
    ]);
});

describe('declining', function (): void {
    it('declines with a reason, using the confirmation up', function (): void {
        Event::fake([ApprovalConfirmationDeclined::class]);

        [$confirmation, $person] = askForConfirmation();

        $declined = Approvals::decline($confirmation, $person, 'not this repository');

        expect($declined->status)->toBe(ConfirmationStatus::Declined)
            ->and($declined->reason)->toBe('not this repository')
            ->and($declined->resolved_at?->toDateTimeString())->toBe('2026-11-16 12:00:00');

        Event::assertDispatched(
            ApprovalConfirmationDeclined::class,
            fn (ApprovalConfirmationDeclined $event): bool => $event->confirmation->is($confirmation) && $event->person->is($person),
        );
    });

    it('declines without a reason', function (): void {
        [$confirmation, $person] = askForConfirmation();

        expect(Approvals::decline($confirmation, $person)->reason)->toBeNull();
    });

    it('refuses a decline it cannot take', function (Closure $setUp, ConfirmationRefusal $expected): void {
        [$confirmation, $person] = askForConfirmation(fn (PendingConfirmation $p): PendingConfirmation => $p->expiresIn(60));

        $answerer = $setUp($confirmation, $person) ?? $person;

        Event::fake([ApprovalConfirmationDeclined::class]);

        expect(refusalOf(fn () => Approvals::decline($confirmation, $answerer, 'no'))?->refusal)->toBe($expected);

        Event::assertNotDispatched(ApprovalConfirmationDeclined::class);
    })->with([
        'another person' => [fn (): ReviewerTestModel => ReviewerTestModel::create(), ConfirmationRefusal::NotThePerson],
        'already confirmed' => [fn (ApprovalConfirmation $c, ReviewerTestModel $p) => tap(null, fn () => app(ConfirmAction::class)->execute($c, $p, TOOL_HASH)), ConfirmationRefusal::Used],
        'already declined' => [fn (ApprovalConfirmation $c, ReviewerTestModel $p) => tap(null, fn () => app(DeclineAction::class)->execute($c, $p)), ConfirmationRefusal::Declined],
        'expired' => [fn () => tap(null, fn () => CarbonImmutable::setTestNow('2026-11-16 12:05:00')), ConfirmationRefusal::Expired],
    ]);
});

describe('listing', function (): void {
    it('lists the confirmations pending for a person', function (): void {
        $person = ReviewerTestModel::create();
        $other = ReviewerTestModel::create();
        $release = ReleaseTestModel::create();

        $open = Approvals::confirmation($release)->payload('a')->by($person)->request();
        $forever = Approvals::confirmation(DeploymentTestModel::create())->payload('b')->by($person)->expiringAt(CarbonImmutable::now()->addYear())->request();
        $answered = Approvals::confirmation($release)->payload('c')->by($person)->request();
        $overdue = Approvals::confirmation($release)->payload('d')->by($person)->expiresIn(30)->request();
        Approvals::confirmation($release)->payload('e')->by($other)->request();

        Approvals::confirm($answered, $person, 'c');

        CarbonImmutable::setTestNow('2026-11-16 12:00:30');

        expect(Approvals::pendingConfirmationsFor($person)->orderBy('id')->pluck('id')->all())
            ->toBe([$open->getKey(), $forever->getKey()])
            ->and($overdue->fresh()?->isExpired())->toBeTrue()
            ->and(ApprovalConfirmation::query()->pendingFor($other)->count())->toBe(1);
    });

    it('lists every confirmation for a subject, and the pending ones', function (): void {
        $person = ReviewerTestModel::create();
        $release = ReleaseTestModel::create();
        $otherRelease = ReleaseTestModel::create();

        $open = Approvals::confirmation($release)->payload('a')->by($person)->request();
        $declined = Approvals::confirmation($release)->payload('b')->by($person)->request();
        Approvals::confirmation($otherRelease)->payload('c')->by($person)->request();
        // Same key, another morph type: another subject.
        Approvals::confirmation(DeploymentTestModel::create(['id' => $release->getKey()]))->payload('d')->by($person)->request();

        Approvals::decline($declined, $person);

        expect(Approvals::confirmationsFor($release)->orderBy('id')->pluck('id')->all())->toBe([$open->getKey(), $declined->getKey()])
            ->and(Approvals::confirmationsFor($release)->pending()->pluck('id')->all())->toBe([$open->getKey()]);
    });
});

describe('the expiry sweep', function (): void {
    it('lapses overdue pending confirmations once, firing expired for each', function (): void {
        Event::fake([ApprovalConfirmationExpired::class]);

        [$overdue] = askForConfirmation(fn (PendingConfirmation $p): PendingConfirmation => $p->expiresIn(60));
        [$alsoOverdue] = askForConfirmation(fn (PendingConfirmation $p): PendingConfirmation => $p->expiresIn(120));
        [$open] = askForConfirmation(fn (PendingConfirmation $p): PendingConfirmation => $p->expiresIn(3600));
        [$answered, $person] = askForConfirmation(fn (PendingConfirmation $p): PendingConfirmation => $p->expiresIn(60));

        Approvals::confirm($answered, $person, TOOL_HASH);

        CarbonImmutable::setTestNow('2026-11-16 12:02:00');

        expect(Approvals::expireConfirmations())->toBe(2)
            ->and(Approvals::expireConfirmations())->toBe(0)
            ->and($overdue->fresh()?->status)->toBe(ConfirmationStatus::Expired)
            ->and($overdue->fresh()?->resolved_at?->toDateTimeString())->toBe('2026-11-16 12:02:00')
            ->and($alsoOverdue->fresh()?->status)->toBe(ConfirmationStatus::Expired)
            ->and($open->fresh()?->status)->toBe(ConfirmationStatus::Pending)
            ->and($answered->fresh()?->status)->toBe(ConfirmationStatus::Confirmed);

        Event::assertDispatchedTimes(ApprovalConfirmationExpired::class, 2);
    });

    it('sweeps at a given moment and for one subject type', function (): void {
        $person = ReviewerTestModel::create();
        $release = Approvals::confirmation(ReleaseTestModel::create())->payload('a')->by($person)->expiresIn(60)->request();
        $deployment = Approvals::confirmation(DeploymentTestModel::create())->payload('b')->by($person)->expiresIn(60)->request();

        expect(Approvals::expireConfirmations(CarbonImmutable::now()->addSeconds(59)))->toBe(0)
            ->and(Approvals::expireConfirmations(CarbonImmutable::now()->addMinute(), DeploymentTestModel::class))->toBe(1)
            ->and($deployment->fresh()?->status)->toBe(ConfirmationStatus::Expired)
            ->and($release->fresh()?->status)->toBe(ConfirmationStatus::Pending);
    });

    it('runs from the console', function (): void {
        askForConfirmation(fn (PendingConfirmation $p): PendingConfirmation => $p->expiresIn(60));

        CarbonImmutable::setTestNow('2026-11-16 13:00:00');

        expect(Artisan::call('approvals:expire-confirmations'))->toBe(0)
            ->and(Artisan::output())->toContain('Expired 1 confirmation(s).')
            ->and(ApprovalConfirmation::query()->where('status', 'expired')->count())->toBe(1);
    });

    it('does not lapse a confirmation answered after the sweep read it', function (): void {
        [$confirmation] = askForConfirmation(fn (PendingConfirmation $p): PendingConfirmation => $p->expiresIn(60));

        CarbonImmutable::setTestNow('2026-11-16 12:05:00');

        // Answered (or lapsed) between the sweep's read and its write.
        $confirmation->newQuery()->whereKey($confirmation->getKey())->update(['status' => 'confirmed']);

        expect($confirmation->lapseIfOverdue())->toBeFalse()
            ->and($confirmation->status)->toBe(ConfirmationStatus::Confirmed);
    });
});

describe('the API layers', function (): void {
    it('works injected, without the facade', function (): void {
        $manager = app(ApprovalsManager::class);
        $person = ReviewerTestModel::create();
        $release = ReleaseTestModel::create();

        $confirmation = $manager->confirmation($release)->payload(TOOL_HASH)->by($person)->request();

        expect($manager->pendingConfirmationsFor($person)->count())->toBe(1)
            ->and($manager->confirm($confirmation, $person, TOOL_HASH)->status)->toBe(ConfirmationStatus::Confirmed)
            ->and($manager->confirmationsFor($release)->count())->toBe(1)
            ->and($manager->expireConfirmations())->toBe(0);
    });

    it('works through the raw actions', function (): void {
        $person = ReviewerTestModel::create();

        $confirmation = app(RequestConfirmationAction::class)->execute(ReleaseTestModel::create(), $person, TOOL_HASH, CarbonImmutable::now()->addMinute());

        expect(app(DeclineAction::class)->execute($confirmation, $person, 'no')->status)->toBe(ConfirmationStatus::Declined);
    });
});

it('reads its status and binding without a query', function (): void {
    [$confirmation, $person, $release] = askForConfirmation();

    expect(ConfirmationStatus::Pending->isFinal())->toBeFalse()
        ->and(ConfirmationStatus::Confirmed->isFinal())->toBeTrue()
        ->and(ConfirmationStatus::Declined->isFinal())->toBeTrue()
        ->and(ConfirmationStatus::Expired->isFinal())->toBeTrue()
        ->and($confirmation->isPending())->toBeTrue()
        ->and($confirmation->effectiveStatus())->toBe(ConfirmationStatus::Pending)
        // An unsaved model has no key: it is nobody the confirmation names.
        ->and($confirmation->isAddressedTo(new ReviewerTestModel))->toBeFalse()
        ->and($confirmation->isFor(new ReleaseTestModel))->toBeFalse()
        ->and($confirmation->isFor($release))->toBeTrue()
        ->and($confirmation->isAddressedTo($person))->toBeTrue()
        ->and($confirmation->matchesPayload(TOOL_HASH))->toBeTrue();
});

it('serves the refusal messages in slovak', function (): void {
    [$confirmation, $person] = askForConfirmation();

    app()->setLocale('sk');

    try {
        $refused = refusalOf(fn () => Approvals::confirm($confirmation, $person, 'wrong'));

        expect($refused?->getMessage())->toBe('Toto potvrdenie bolo udelené pre inú akciu.')
            ->and($refused?->errorCode())->toBe('mismatch')
            ->and(ConfirmationRefusal::NotThePerson->message())->toBe('Na toto potvrdenie môže odpovedať iba osoba, ktorej bolo určené.');
    } finally {
        app()->setLocale('en');
    }
});
