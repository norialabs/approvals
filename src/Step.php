<?php

declare(strict_types=1);

namespace Noria\Approvals;

use InvalidArgumentException;

final readonly class Step
{
    public const ALL = 'all';

    public const ANY = 'any';

    /** @var list<Group> */
    public array $groups;

    /**
     * @param  list<int|string>  $users
     * @param  list<string>  $roles
     * @param  list<Group>  $groups
     */
    public function __construct(
        public string $name,
        int $approvals = 1,
        array $users = [],
        ?string $ability = null,
        array $roles = [],
        public ?int $escalateAfter = null,
        public ?Step $escalateTo = null,
        array $groups = [],
        public string $mode = self::ALL,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('An approval step needs a name.');
        }

        if ($escalateAfter !== null && $escalateAfter < 1) {
            throw new InvalidArgumentException("Approval step [{$name}] must escalate after at least one minute.");
        }

        if (! in_array($mode, [self::ALL, self::ANY], true)) {
            throw new InvalidArgumentException("Approval step [{$name}] mode must be all or any.");
        }

        if ($groups !== [] && ($users !== [] || $ability !== null || $roles !== [] || $approvals !== 1)) {
            throw new InvalidArgumentException("Approval step [{$name}] sets its approvers either on the step or in groups, not both.");
        }

        $names = array_map(fn (Group $group): string => $group->name, $groups);

        if (count($names) !== count(array_unique($names))) {
            throw new InvalidArgumentException("Approval step [{$name}] has two groups with the same name.");
        }

        $this->groups = $groups === [] ? [new Group($name, $approvals, $users, $ability, $roles)] : $groups;
    }

    public function hasGroups(): bool
    {
        return count($this->groups) > 1 || $this->groups[0]->name !== $this->name;
    }

    public function group(string $name): ?Group
    {
        foreach ($this->groups as $group) {
            if ($group->name === $name) {
                return $group;
            }
        }

        return null;
    }

    public function approvalsNeeded(): int
    {
        $counts = array_map(fn (Group $group): int => $group->approvals, $this->groups);

        return $this->mode === self::ANY ? min($counts) : array_sum($counts);
    }

    public function isOpenToAnyone(): bool
    {
        foreach ($this->groups as $group) {
            if (! $group->isOpenToAnyone()) {
                return false;
            }
        }

        return true;
    }

    /** @param array<mixed> $definition */
    public static function fromArray(array $definition): self
    {
        $name = $definition['name'] ?? 'review';
        $escalateAfter = $definition['escalate_after'] ?? null;
        $escalateTo = $definition['escalate_to'] ?? null;
        $groups = $definition['groups'] ?? [];
        $mode = $definition['mode'] ?? self::ALL;

        if (! is_string($name)) {
            throw new InvalidArgumentException('An approval step needs a string name and a numeric approvals count.');
        }

        if ($escalateAfter !== null && ! is_numeric($escalateAfter)) {
            throw new InvalidArgumentException("Approval step [{$name}] escalate_after must be a number of minutes.");
        }

        if ($escalateTo !== null && ! is_array($escalateTo)) {
            throw new InvalidArgumentException("Approval step [{$name}] escalate_to must be a step definition.");
        }

        if (! is_array($groups) || ! is_string($mode)) {
            throw new InvalidArgumentException("Approval step [{$name}] groups must be a list of group definitions.");
        }

        $parsed = [];

        foreach ($groups as $group) {
            if (! is_array($group) || ! isset($group['name'])) {
                throw new InvalidArgumentException("Approval step [{$name}] groups must be a list of named group definitions.");
            }

            $parsed[] = Group::fromArray($group, $name);
        }

        if ($parsed !== [] && array_intersect_key($definition, array_flip(['approvals', 'users', 'ability', 'roles'])) !== []) {
            throw new InvalidArgumentException("Approval step [{$name}] sets its approvers either on the step or in groups, not both.");
        }

        $own = $parsed === [] ? Group::fromArray($definition, $name) : null;

        return new self(
            name: $name,
            approvals: $own === null ? 1 : $own->approvals,
            users: $own === null ? [] : $own->users,
            ability: $own?->ability,
            roles: $own === null ? [] : $own->roles,
            escalateAfter: $escalateAfter === null ? null : (int) $escalateAfter,
            escalateTo: $escalateTo === null ? null : self::fromArray($escalateTo),
            groups: $parsed,
            mode: $mode,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $approvers = $this->hasGroups()
            ? ['groups' => array_map(fn (Group $group): array => $group->toArray(), $this->groups), 'mode' => $this->mode === self::ANY ? self::ANY : null]
            : array_diff_key($this->groups[0]->toArray(), ['name' => true]);

        return array_filter([
            'name' => $this->name,
            ...$approvers,
            'escalate_after' => $this->escalateAfter,
            'escalate_to' => $this->escalateTo?->toArray(),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
