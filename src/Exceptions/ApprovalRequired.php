<?php

declare(strict_types=1);

namespace Noria\Approvals\Exceptions;

use Noria\Approvals\Models\Approval;
use RuntimeException;

final class ApprovalRequired extends RuntimeException
{
    public function __construct(public readonly Approval $approval)
    {
        parent::__construct("{$approval->summary()} is waiting for approval.");
    }
}
