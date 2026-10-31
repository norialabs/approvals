<?php

declare(strict_types=1);

namespace Noria\Approvals\Defaults;

use InvalidArgumentException;
use Noria\Approvals\Contracts\Policy;
use Noria\Approvals\PendingChange;
use Noria\Approvals\Requirement;
use Noria\Approvals\Settings;
use Noria\Approvals\Snapshot;

final class ConfiguredPolicy implements Policy
{
    private const OPERATORS = ['=', '!=', '>', '>=', '<', '<=', 'in', 'not_in', 'between', 'null', 'not_null', 'changed', 'contains', 'starts_with', 'ends_with'];

    public function requirementFor(PendingChange $change): Requirement
    {
        if ($change->subject !== null && method_exists($change->subject, 'approvalRequirement')) {
            $requirement = $change->subject->approvalRequirement($change);

            if ($requirement instanceof Requirement) {
                return $requirement;
            }
        }

        return $this->evaluate($this->definitionFor($change), $change);
    }

    /** @return array<string, mixed> */
    public function definitionFor(PendingChange $change): array
    {
        if (Settings::usesDatabasePolicies()) {
            $stored = Settings::policyModel()::definitionFor($change->subjectType, $change->tenantId);

            if ($stored !== null) {
                return $stored;
            }
        }

        return Settings::subjectPolicy($change->subjectClass) ?? Settings::defaultPolicy();
    }

    /** @param array<string, mixed> $definition */
    public function evaluate(array $definition, PendingChange $change): Requirement
    {
        $operations = $definition['operations'] ?? null;

        if (is_array($operations) && ! in_array($change->operation->value, $operations, true)) {
            return Requirement::none();
        }

        $rules = $definition['rules'] ?? [];

        if (! is_array($rules)) {
            throw new InvalidArgumentException('Approval rules must be a list.');
        }

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                throw new InvalidArgumentException('Each approval rule must be an array.');
            }

            $conditions = $rule['when'] ?? [];

            if (! is_array($conditions)) {
                throw new InvalidArgumentException('An approval rule needs a list of conditions under [when].');
            }

            if ($this->matchesAll($conditions, $change)) {
                unset($rule['when']);

                return Requirement::fromArray([...$definition, ...$rule]);
            }
        }

        return Requirement::fromArray($definition);
    }

    /** @param array<mixed> $conditions */
    public function matchesAll(array $conditions, PendingChange $change): bool
    {
        foreach ($conditions as $condition) {
            if (! is_array($condition) || ! $this->matches($condition, $change)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<mixed> $condition */
    public function matches(array $condition, PendingChange $change): bool
    {
        if (isset($condition['all'])) {
            return is_array($condition['all']) && $this->matchesAll($condition['all'], $change);
        }

        if (isset($condition['any'])) {
            if (! is_array($condition['any'])) {
                return false;
            }

            foreach ($condition['any'] as $nested) {
                if (is_array($nested) && $this->matches($nested, $change)) {
                    return true;
                }
            }

            return false;
        }

        $field = $condition[0] ?? null;
        $operator = $condition[1] ?? null;
        $expected = $condition[2] ?? null;

        if (! is_string($field) || ! is_string($operator) || ! in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException('An approval condition is [field, operator, value] with one of: '.implode(', ', self::OPERATORS).'.');
        }

        if ($operator === 'changed') {
            return $change->changed($field) === ($expected ?? true);
        }

        return $this->compare($this->field($field, $change), $operator, $expected);
    }

    private function field(string $field, PendingChange $change): mixed
    {
        return match (true) {
            $field === 'operation' => $change->operation->value,
            $field === 'actor' => $change->actorId,
            $field === 'tenant' => $change->tenantId,
            str_starts_with($field, 'old.') => $change->original(substr($field, 4)),
            str_starts_with($field, 'payload.') => data_get($change->payload ?? [], substr($field, 8)),
            default => $change->value($field),
        };
    }

    private function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            '=' => Snapshot::same($actual, $expected),
            '!=' => ! Snapshot::same($actual, $expected),
            '>' => self::ordered($actual, $expected) > 0,
            '>=' => self::ordered($actual, $expected) >= 0,
            '<' => self::ordered($actual, $expected) < 0 && $actual !== null,
            '<=' => self::ordered($actual, $expected) <= 0 && $actual !== null,
            'in' => is_array($expected) && self::contains($expected, $actual),
            'not_in' => is_array($expected) && ! self::contains($expected, $actual),
            'between' => is_array($expected) && count($expected) === 2
                && self::ordered($actual, array_values($expected)[0]) >= 0
                && self::ordered($actual, array_values($expected)[1]) <= 0
                && $actual !== null,
            'null' => $actual === null,
            'not_null' => $actual !== null,
            'contains' => is_array($actual) ? self::contains($actual, $expected) : (is_scalar($actual) && is_scalar($expected) && str_contains((string) $actual, (string) $expected)),
            'starts_with' => is_scalar($actual) && is_scalar($expected) && str_starts_with((string) $actual, (string) $expected),
            'ends_with' => is_scalar($actual) && is_scalar($expected) && str_ends_with((string) $actual, (string) $expected),
            default => false,
        };
    }

    private static function ordered(mixed $actual, mixed $expected): int
    {
        if ($actual === null) {
            return -1;
        }

        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual <=> (float) $expected;
        }

        if (is_scalar($actual) && is_scalar($expected)) {
            return strcmp((string) $actual, (string) $expected);
        }

        return -1;
    }

    /** @param array<mixed> $haystack */
    private static function contains(array $haystack, mixed $needle): bool
    {
        foreach ($haystack as $value) {
            if (Snapshot::same($value, $needle)) {
                return true;
            }
        }

        return false;
    }
}
