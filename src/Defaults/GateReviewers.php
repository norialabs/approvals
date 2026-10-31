<?php

declare(strict_types=1);

namespace Noria\Approvals\Defaults;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Noria\Approvals\Contracts\Actors;
use Noria\Approvals\Contracts\Reviewers;
use Noria\Approvals\Group;
use Noria\Approvals\Models\Approval;

final readonly class GateReviewers implements Reviewers
{
    public function __construct(private Gate $gate, private Actors $actors) {}

    public function allows(Approval $approval, Group $group, int|string $actorId): bool
    {
        if ($group->users !== [] && ! in_array((string) $actorId, array_map(strval(...), $group->users), true)) {
            return false;
        }

        if ($group->ability === null && $group->roles === []) {
            return true;
        }

        $actor = $this->actors->find($actorId);

        if ($group->ability !== null && (! $actor instanceof Authenticatable || $this->gate->forUser($actor)->denies($group->ability, [$approval]))) {
            return false;
        }

        if ($group->roles === []) {
            return true;
        }

        if ($actor !== null && method_exists($actor, 'hasAnyRole')) {
            return $actor->hasAnyRole($group->roles) === true;
        }

        if ($actor !== null && method_exists($actor, 'hasRole')) {
            foreach ($group->roles as $role) {
                if ($actor->hasRole($role) === true) {
                    return true;
                }
            }
        }

        return false;
    }

    public function candidates(Approval $approval, Group $group): ?array
    {
        return $group->users === [] ? null : $group->users;
    }
}
