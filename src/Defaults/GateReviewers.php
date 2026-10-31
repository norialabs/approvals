<?php

declare(strict_types=1);

namespace Noria\Approvals\Defaults;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Noria\Approvals\Contracts\Actors;
use Noria\Approvals\Contracts\Reviewers;
use Noria\Approvals\Models\Approval;
use Noria\Approvals\Step;

final readonly class GateReviewers implements Reviewers
{
    public function __construct(private Gate $gate, private Actors $actors) {}

    public function allows(Approval $approval, Step $step, int|string $actorId): bool
    {
        if ($step->users !== [] && ! in_array((string) $actorId, array_map(strval(...), $step->users), true)) {
            return false;
        }

        if ($step->ability === null && $step->roles === []) {
            return true;
        }

        $actor = $this->actors->find($actorId);

        if ($step->ability !== null && (! $actor instanceof Authenticatable || $this->gate->forUser($actor)->denies($step->ability, [$approval]))) {
            return false;
        }

        if ($step->roles === []) {
            return true;
        }

        if ($actor !== null && method_exists($actor, 'hasAnyRole')) {
            return $actor->hasAnyRole($step->roles) === true;
        }

        if ($actor !== null && method_exists($actor, 'hasRole')) {
            foreach ($step->roles as $role) {
                if ($actor->hasRole($role) === true) {
                    return true;
                }
            }
        }

        return false;
    }

    public function candidates(Approval $approval, Step $step): ?array
    {
        return $step->users === [] ? null : $step->users;
    }
}
