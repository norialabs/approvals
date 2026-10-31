<?php

declare(strict_types=1);

namespace Noria\Approvals\Events;

use Noria\Approvals\Models\Approval;
use Throwable;

final class ApprovalFailed
{
    public function __construct(
        public readonly Approval $approval,
        public readonly Throwable $exception,
    ) {}
}
