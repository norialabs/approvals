<?php

declare(strict_types=1);

namespace Noria\Approvals;

final readonly class Tally
{
    /** @param list<list<string>> $votes */
    public function __construct(private Step $step, private array $votes) {}

    public function isComplete(): bool
    {
        if ($this->step->mode === Step::ANY) {
            foreach ($this->step->groups as $group) {
                if ($this->eligibleCount($group->name) >= $group->approvals) {
                    return true;
                }
            }

            return false;
        }

        return array_sum($this->filled()) === $this->step->approvalsNeeded();
    }

    /** @param list<string> $groups */
    public function wouldCount(array $groups): bool
    {
        if ($groups === []) {
            return false;
        }

        if ($this->step->mode === Step::ANY) {
            return true;
        }

        $with = new self($this->step, [...$this->votes, $groups]);

        return array_sum($with->filled()) > array_sum($this->filled());
    }

    /** @return array<string, int> */
    public function filled(): array
    {
        if ($this->step->mode === Step::ANY) {
            $filled = [];

            foreach ($this->step->groups as $group) {
                $filled[$group->name] = min($group->approvals, $this->eligibleCount($group->name));
            }

            return $filled;
        }

        $slots = [];

        foreach ($this->step->groups as $group) {
            for ($seat = 0; $seat < $group->approvals; $seat++) {
                $slots[] = $group->name;
            }
        }

        $seated = array_fill(0, count($slots), null);

        foreach (array_keys($this->votes) as $vote) {
            $visited = [];
            $this->seat($vote, $slots, $seated, $visited);
        }

        $filled = array_fill_keys(array_map(fn (Group $group): string => $group->name, $this->step->groups), 0);

        foreach ($seated as $slot => $vote) {
            if ($vote !== null) {
                $filled[$slots[$slot]]++;
            }
        }

        return $filled;
    }

    /** @return list<Group> */
    public function unfilledGroups(): array
    {
        if ($this->isComplete()) {
            return [];
        }

        $filled = $this->filled();

        return array_values(array_filter($this->step->groups, fn (Group $group): bool => $filled[$group->name] < $group->approvals));
    }

    private function eligibleCount(string $group): int
    {
        return count(array_filter($this->votes, fn (array $groups): bool => in_array($group, $groups, true)));
    }

    /**
     * @param  list<string>  $slots
     * @param  array<int, int|null>  $seated
     * @param  array<int, true>  $visited
     */
    private function seat(int $vote, array $slots, array &$seated, array &$visited): bool
    {
        foreach ($slots as $slot => $group) {
            if (isset($visited[$slot]) || ! in_array($group, $this->votes[$vote], true)) {
                continue;
            }

            $visited[$slot] = true;
            $holder = $seated[$slot];

            if ($holder === null || $this->seat($holder, $slots, $seated, $visited)) {
                $seated[$slot] = $vote;

                return true;
            }
        }

        return false;
    }
}
