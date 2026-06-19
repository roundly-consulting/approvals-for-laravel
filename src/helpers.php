<?php

declare(strict_types=1);

use RoundlyConsulting\Approvals\Support\ApprovalManager;

if (! function_exists('approvals')) {
    /**
     * Resolve the approvals manager from the container.
     */
    function approvals(): ApprovalManager
    {
        return app(ApprovalManager::class);
    }
}
