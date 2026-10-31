<?php

declare(strict_types=1);

namespace Noria\Approvals\Events;

use Noria\Approvals\Models\Approval;

final class ApprovalRequested
{
    public function __construct(public readonly Approval $approval) {}
}
