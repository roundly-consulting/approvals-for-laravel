<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Tests\Fixtures;

use Closure;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Approvals\Exceptions\ConfirmationRefusedException;
use RoundlyConsulting\Testing\Database\DriverMatrix;
use RuntimeException;
use Throwable;

/**
 * A real second Postgres session in a forked process, for the confirmation races (the
 * credits-for-laravel harness): the operation runs on a connection of its own (`racer`) and
 * its outcome lands in a file — `done:<value>`, `refused:<code>` or the error. The child
 * ends with SIGKILL so none of the parent's sessions are torn down by its shutdown.
 */
final class SecondSession
{
    /**
     * @param  Closure(): mixed  $operation
     * @return array{int, string} the child's pid and the result file
     */
    public static function run(Closure $operation, ?string $isolation = null): array
    {
        $resultFile = (string) tempnam(sys_get_temp_dir(), 'approvals-confirmation-race-');
        $pid = pcntl_fork();

        if ($pid !== 0) {
            return [$pid, $resultFile];
        }

        $outcome = 'error';

        try {
            config()->set('database.connections.racer', array_filter(
                [...DriverMatrix::connectionConfig('pgsql'), 'isolation_level' => $isolation],
                static fn (mixed $value): bool => $value !== null,
            ));
            DB::setDefaultConnection('racer');

            $outcome = 'done:'.var_export($operation(), true);
        } catch (ConfirmationRefusedException $refused) {
            $outcome = 'refused:'.$refused->errorCode();
        } catch (Throwable $exception) {
            $outcome = 'error: '.$exception->getMessage();
        }

        file_put_contents($resultFile, $outcome);

        posix_kill(posix_getpid(), SIGKILL);

        return [0, $resultFile];
    }

    /**
     * Until the racer is blocked on a lock, or has finished because nothing blocked it.
     */
    public static function waitUntilBlocked(string $resultFile): void
    {
        $probe = DB::connection('probe');

        for ($i = 0; $i < 100; $i++) {
            clearstatcache();

            if (filesize($resultFile) > 0) {
                return;
            }

            $waiting = $probe->selectOne(
                "select count(*) as n from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock'",
            );

            if ((int) $waiting->n > 0) {
                return;
            }

            usleep(50_000);
        }

        throw new RuntimeException('The racer neither blocked nor finished.');
    }

    /**
     * Reap the child and return what it decided.
     */
    public static function outcome(int $pid, string $resultFile): string
    {
        pcntl_waitpid($pid, $status);
        $outcome = (string) file_get_contents($resultFile);
        @unlink($resultFile);

        return $outcome;
    }
}
