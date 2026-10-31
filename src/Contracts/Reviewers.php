<?php

declare(strict_types=1);

namespace Noria\Approvals\Contracts;

use Noria\Approvals\Models\Approval;
use Noria\Approvals\Step;

interface Reviewers
{
    public function allows(Approval $approval, Step $step, int|string $actorId): bool;

    /** @return list<int|string>|null */
    public function candidates(Approval $approval, Step $step): ?array;
}
