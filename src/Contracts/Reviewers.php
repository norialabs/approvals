<?php

declare(strict_types=1);

namespace Noria\Approvals\Contracts;

use Noria\Approvals\Group;
use Noria\Approvals\Models\Approval;

interface Reviewers
{
    public function allows(Approval $approval, Group $group, int|string $actorId): bool;

    /** @return list<int|string>|null */
    public function candidates(Approval $approval, Group $group): ?array;

    public function mayVeto(Approval $approval, int|string $actorId): bool;
}
