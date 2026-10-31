<?php

declare(strict_types=1);

namespace Noria\Approvals\Events;

use Noria\Approvals\Models\Approval;

final class ApprovalReminderDue
{
    /** @param list<int|string>|null $reviewerIds */
    public function __construct(
        public readonly Approval $approval,
        public readonly ?array $reviewerIds,
    ) {}
}
