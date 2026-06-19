<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Approvals\Actions\ExpireApprovalsAction;

final class ExpireApprovalsCommand extends Command
{
    protected $signature = 'approvals:expire';

    protected $description = 'Lapse pending approvals whose expiry has passed';

    public function handle(ExpireApprovalsAction $action): int
    {
        $count = $action->execute();

        $this->info("Expired {$count} approval(s).");

        return self::SUCCESS;
    }
}
