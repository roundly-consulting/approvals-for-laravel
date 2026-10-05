<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * `approvals.approval_request_id` points at the package's own `approval_requests.id`, a
 * bigint on every key type. The create migration lets it follow `approvals.key_type`, so
 * a Postgres host on `uuid` keys gets a uuid column there, and every decision on a request
 * fails to insert. This turns that column into a bigint, on fresh installs and on hosts
 * that migrated 1.0.0 alike.
 *
 * It only acts on Postgres and only on a uuid column: no decision on a request could be
 * written on such a host, so the column holds nothing but NULLs. Every other shape (a
 * ulid `char(26)` on Postgres included) takes the request id as it is and is left
 * untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'pgsql'
            || Schema::getColumnType('approvals', 'approval_request_id') !== 'uuid') {
            return;
        }

        $grammar = $connection->getQueryGrammar();
        $column = $grammar->wrap('approval_request_id');

        // Through text: Postgres has no uuid -> bigint cast. A stray non-NULL uuid stops
        // the migration instead of being thrown away.
        $connection->statement(
            'alter table '.$grammar->wrapTable('approvals')
            .' alter column '.$column.' type bigint using '.$column.'::text::bigint',
        );
    }
};
