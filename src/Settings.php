<?php

declare(strict_types=1);

namespace Noria\Approvals;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Noria\Approvals\Models\Approval;
use Noria\Approvals\Models\ApprovalDelegation;
use Noria\Approvals\Models\ApprovalEvent;
use Noria\Approvals\Models\ApprovalPolicy;

final class Settings
{
    public const KEY_TYPES = ['int', 'ulid', 'uuid', 'string'];

    public static function enforced(): bool
    {
        return self::bool('enforced', true);
    }

    public static function throwsOnIntercept(): bool
    {
        return self::choice('intercept', ['halt', 'throw'], 'halt') === 'throw';
    }

    public static function withoutActor(): string
    {
        return self::choice('without_actor', ['hold', 'allow', 'reject'], 'hold');
    }

    public static function distinctReviewers(): bool
    {
        return self::bool('distinct_reviewers', true);
    }

    public static function overwritesConflicts(): bool
    {
        return self::choice('on_conflict', ['fail', 'overwrite'], 'fail') === 'overwrite';
    }

    public static function runsMigrations(): bool
    {
        return self::bool('run_migrations', true);
    }

    public static function usesDatabasePolicies(): bool
    {
        return self::bool('database_policies', false);
    }

    public static function keyType(): string
    {
        $type = self::choice('keys', self::KEY_TYPES, 'ulid');

        if ($type === 'string') {
            throw new InvalidArgumentException('approvals.keys must be int, ulid or uuid.');
        }

        return $type;
    }

    public static function subjectKeyType(): string
    {
        return self::choice('subjects.key', self::KEY_TYPES, 'string');
    }

    public static function table(string $name): string
    {
        return self::string('tables.'.$name, match ($name) {
            'approvals' => 'approvals',
            'events' => 'approval_events',
            'delegations' => 'approval_delegations',
            'policies' => 'approval_policies',
            default => throw new InvalidArgumentException("Unknown approvals table [{$name}]."),
        });
    }

    /** @return class-string<Approval> */
    public static function approvalModel(): string
    {
        return self::model('approval', Approval::class);
    }

    /** @return class-string<ApprovalEvent> */
    public static function eventModel(): string
    {
        return self::model('event', ApprovalEvent::class);
    }

    /** @return class-string<ApprovalDelegation> */
    public static function delegationModel(): string
    {
        return self::model('delegation', ApprovalDelegation::class);
    }

    /** @return class-string<ApprovalPolicy> */
    public static function policyModel(): string
    {
        return self::model('policy', ApprovalPolicy::class);
    }

    /** @return class-string<Model>|null */
    public static function actorModel(): ?string
    {
        $model = config('approvals.actors.model');

        return is_string($model) && is_a($model, Model::class, true) ? $model : null;
    }

    /** @return array{table: string, key: string, references: string, constrained: bool} */
    public static function actorColumns(): array
    {
        return [
            'table' => self::string('actors.table', 'users'),
            'key' => self::choice('actors.key', self::KEY_TYPES, 'int'),
            'references' => self::string('actors.references', 'id'),
            'constrained' => self::bool('actors.constrained', true),
        ];
    }

    public static function tenancyEnabled(): bool
    {
        return self::bool('tenancy.enabled', false);
    }

    public static function tenantColumn(): string
    {
        return self::string('tenancy.column', 'tenant_id');
    }

    /** @return array{column: string, table: string, key: string, references: string, constrained: bool} */
    public static function tenantColumns(): array
    {
        return [
            'column' => self::tenantColumn(),
            'table' => self::string('tenancy.table', 'tenants'),
            'key' => self::choice('tenancy.key', self::KEY_TYPES, 'int'),
            'references' => self::string('tenancy.references', 'id'),
            'constrained' => self::bool('tenancy.constrained', true),
        ];
    }

    public static function scopesTenantQueries(): bool
    {
        return self::tenancyEnabled() && self::bool('tenancy.scope_queries', true);
    }

    /** @return array<string, mixed> */
    public static function defaultPolicy(): array
    {
        return self::map('default');
    }

    /** @return array<string, mixed>|null */
    public static function subjectPolicy(string $subjectClass): ?array
    {
        $policies = config('approvals.subject_policies');

        if (! is_array($policies) || ! isset($policies[$subjectClass]) || ! is_array($policies[$subjectClass])) {
            return null;
        }

        return self::stringKeys($policies[$subjectClass]);
    }

    public static function reminderMinutes(): int
    {
        $minutes = config('approvals.reminders.after', 1440);

        return is_numeric($minutes) ? max(1, (int) $minutes) : 1440;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return T
     */
    public static function resolve(string $key, string $contract, string $fallback): object
    {
        $class = config('approvals.'.$key, $fallback);
        $class = is_string($class) && $class !== '' ? $class : $fallback;
        $instance = app($class);

        if (! $instance instanceof $contract) {
            throw new InvalidArgumentException("approvals.{$key} must implement {$contract}.");
        }

        return $instance;
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, mixed>
     */
    public static function stringKeys(array $values): array
    {
        $keyed = [];

        foreach ($values as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $fallback
     * @return class-string<T>
     */
    private static function model(string $name, string $fallback): string
    {
        $model = config('approvals.models.'.$name, $fallback);

        if (! is_string($model) || ! is_a($model, $fallback, true)) {
            throw new InvalidArgumentException("approvals.models.{$name} must extend {$fallback}.");
        }

        return $model;
    }

    /** @return array<string, mixed> */
    private static function map(string $key): array
    {
        $value = config('approvals.'.$key);

        return is_array($value) ? self::stringKeys($value) : [];
    }

    private static function bool(string $key, bool $default): bool
    {
        $value = config('approvals.'.$key, $default);

        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOL);
    }

    private static function string(string $key, string $default): string
    {
        $value = config('approvals.'.$key, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /** @param list<string> $choices */
    private static function choice(string $key, array $choices, string $default): string
    {
        $value = self::string($key, $default);

        if (! in_array($value, $choices, true)) {
            throw new InvalidArgumentException("approvals.{$key} must be one of: ".implode(', ', $choices).'.');
        }

        return $value;
    }
}
