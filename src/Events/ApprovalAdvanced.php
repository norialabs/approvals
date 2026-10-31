<?php

declare(strict_types=1);

namespace Noria\Approvals\Events;

use Noria\Approvals\Models\Approval;

final class ApprovalAdvanced
{
    public const STEP_COMPLETED = 'step_completed';

    public const ESCALATED = 'escalated';

    public function __construct(
        public readonly Approval $approval,
        public readonly string $reason,
        public readonly string $fromStep,
    ) {}
}
