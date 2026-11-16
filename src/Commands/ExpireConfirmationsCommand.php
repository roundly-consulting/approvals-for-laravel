<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Approvals\Facades\Approvals;

final class ExpireConfirmationsCommand extends Command
{
    protected $signature = 'approvals:expire-confirmations';

    protected $description = 'Lapse single-use confirmations whose expiry has passed';

    public function handle(): int
    {
        $count = Approvals::expireConfirmations();

        $this->info("Expired {$count} confirmation(s).");

        return self::SUCCESS;
    }
}
