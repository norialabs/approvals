<?php

declare(strict_types=1);

namespace Noria\Approvals\Contracts;

use Noria\Approvals\PendingChange;
use Noria\Approvals\Requirement;

interface Policy
{
    public function requirementFor(PendingChange $change): Requirement;
}
