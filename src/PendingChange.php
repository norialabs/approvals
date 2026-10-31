<?php

declare(strict_types=1);

namespace Noria\Approvals;

use Illuminate\Database\Eloquent\Model;

final readonly class PendingChange
{
    /**
     * @param  class-string  $subjectClass
     * @param  array<string, mixed>|null  $currentState
     * @param  array<string, mixed>|null  $newState
     * @param  array<string, mixed>|null  $payload
     * @param  list<string>  $confirmations
     */
    public function __construct(
        public Operation $operation,
        public string $subjectClass,
        public string $subjectType,
        public int|string|null $subjectKey,
        public ?Model $subject,
        public ?array $currentState,
        public ?array $newState,
        public ?array $payload,
        public int|string|null $actorId,
        public int|string|null $tenantId,
        public array $confirmations = [],
    ) {}

    public function confirmed(string $confirmation): bool
    {
        return in_array($confirmation, $this->confirmations, true);
    }

    /** @return array<string, mixed> */
    public function extras(): array
    {
        $extras = $this->payload['extras'] ?? [];

        return is_array($extras) ? Settings::stringKeys($extras) : [];
    }

    public function value(string $field): mixed
    {
        return data_get($this->newState ?? $this->currentState ?? $this->payload ?? [], $field);
    }

    public function original(string $field): mixed
    {
        return data_get($this->currentState ?? [], $field);
    }

    public function changed(string $field): bool
    {
        return in_array($field, $this->changedKeys(), true);
    }

    /** @return list<string> */
    public function changedKeys(): array
    {
        if ($this->newState === null) {
            return [];
        }

        $current = $this->currentState ?? [];
        $keys = [];

        foreach ($this->newState as $key => $value) {
            if (! array_key_exists($key, $current) || ! Snapshot::same($current[$key], $value)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
