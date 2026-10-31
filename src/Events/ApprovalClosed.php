<?php

declare(strict_types=1);

namespace Noria\Approvals\Events;

use Noria\Approvals\Models\Approval;

final class ApprovalClosed
{
    public function __construct(public readonly Approval $approval) {}
}
