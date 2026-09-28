<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Models\ApprovalRequestStage;
use RoundlyConsulting\Approvals\Support\DecisionScope;

/**
 * Where a decision lands: whose authority it counts as, who physically made it, on
 * which approvable, and in which slot (standalone, a request, or a request's stage).
 *
 * @internal
 */
final readonly class DecisionTarget
{
    public function __construct(
        public Model $actor,
        public ?Model $decidedBy,
        public Model $approvable,
        public ?ApprovalRequest $request = null,
        public ?ApprovalRequestStage $stage = null,
    ) {}

    public function scope(): string
    {
        return DecisionScope::key($this->request?->getKey(), $this->stage?->getKey());
    }
}
