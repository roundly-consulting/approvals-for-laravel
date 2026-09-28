<?php

declare(strict_types=1);

use RoundlyConsulting\Approvals\ApprovalsManager;

if (! function_exists('approvals')) {
    /**
     * Resolve the approvals manager from the container.
     */
    function approvals(): ApprovalsManager
    {
        return app(ApprovalsManager::class);
    }
}
