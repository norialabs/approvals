<?php

declare(strict_types=1);

namespace Noria\Approvals;

use InvalidArgumentException;

final readonly class Step
{
    /**
     * @param  list<int|string>  $users
     * @param  list<string>  $roles
     */
    public function __construct(
        public string $name,
        public int $approvals = 1,
        public array $users = [],
        public ?string $ability = null,
        public array $roles = [],
        public ?int $escalateAfter = null,
        public ?Step $escalateTo = null,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('An approval step needs a name.');
        }

        if ($approvals < 1) {
            throw new InvalidArgumentException("Approval step [{$name}] needs at least one approval.");
        }

        if ($escalateAfter !== null && $escalateAfter < 1) {
            throw new InvalidArgumentException("Approval step [{$name}] must escalate after at least one minute.");
        }
    }

    public function isOpenToAnyone(): bool
    {
        return $this->users === [] && $this->ability === null && $this->roles === [];
    }

    /** @param array<mixed> $definition */
    public static function fromArray(array $definition): self
    {
        $name = $definition['name'] ?? 'review';
        $approvals = $definition['approvals'] ?? 1;
        $ability = $definition['ability'] ?? null;
        $escalateAfter = $definition['escalate_after'] ?? null;
        $escalateTo = $definition['escalate_to'] ?? null;

        if (! is_string($name) || ! is_numeric($approvals)) {
            throw new InvalidArgumentException('An approval step needs a string name and a numeric approvals count.');
        }

        if ($ability !== null && ! is_string($ability)) {
            throw new InvalidArgumentException("Approval step [{$name}] ability must be a string.");
        }

        if ($escalateAfter !== null && ! is_numeric($escalateAfter)) {
            throw new InvalidArgumentException("Approval step [{$name}] escalate_after must be a number of minutes.");
        }

        if ($escalateTo !== null && ! is_array($escalateTo)) {
            throw new InvalidArgumentException("Approval step [{$name}] escalate_to must be a step definition.");
        }

        return new self(
            name: $name,
            approvals: (int) $approvals,
            users: self::keys($definition['users'] ?? [], $name),
            ability: $ability,
            roles: self::strings($definition['roles'] ?? [], $name),
            escalateAfter: $escalateAfter === null ? null : (int) $escalateAfter,
            escalateTo: $escalateTo === null ? null : self::fromArray($escalateTo),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'approvals' => $this->approvals,
            'users' => $this->users,
            'ability' => $this->ability,
            'roles' => $this->roles,
            'escalate_after' => $this->escalateAfter,
            'escalate_to' => $this->escalateTo?->toArray(),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /** @return list<int|string> */
    private static function keys(mixed $values, string $name): array
    {
        if (! is_array($values)) {
            throw new InvalidArgumentException("Approval step [{$name}] users must be a list of keys.");
        }

        $keys = [];

        foreach ($values as $value) {
            if (! is_int($value) && ! is_string($value)) {
                throw new InvalidArgumentException("Approval step [{$name}] users must be a list of keys.");
            }

            $keys[] = $value;
        }

        return $keys;
    }

    /** @return list<string> */
    private static function strings(mixed $values, string $name): array
    {
        if (! is_array($values)) {
            throw new InvalidArgumentException("Approval step [{$name}] roles must be a list of names.");
        }

        $strings = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                throw new InvalidArgumentException("Approval step [{$name}] roles must be a list of names.");
            }

            $strings[] = $value;
        }

        return $strings;
    }
}
