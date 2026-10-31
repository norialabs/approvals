<?php

declare(strict_types=1);

namespace Noria\Approvals;

use InvalidArgumentException;

final readonly class Requirement
{
    /** @param list<Step> $steps */
    public function __construct(
        public array $steps,
        public ?int $expiresAfter = null,
        public ?string $automatic = null,
        public ?string $reason = null,
    ) {
        if ($expiresAfter !== null && $expiresAfter < 1) {
            throw new InvalidArgumentException('An approval must expire after at least one minute.');
        }

        if ($automatic !== null && ($automatic === '' || $steps !== [])) {
            throw new InvalidArgumentException('An automatic approval needs a reason and no steps.');
        }
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function automatic(string $reason): self
    {
        return new self([], automatic: $reason);
    }

    public function because(?string $reason): self
    {
        return new self($this->steps, $this->expiresAfter, $this->automatic, $reason);
    }

    public function explanation(): ?string
    {
        return $this->automatic ?? $this->reason;
    }

    public function isAutomatic(): bool
    {
        return $this->automatic !== null;
    }

    public static function of(Step ...$steps): self
    {
        return new self(array_values($steps));
    }

    public function expiringAfter(?int $minutes): self
    {
        return new self($this->steps, $minutes, $this->automatic, $this->reason);
    }

    public function isNeeded(): bool
    {
        return $this->steps !== [];
    }

    public function step(int $index): ?Step
    {
        return $this->steps[$index] ?? null;
    }

    public function replacing(int $index, Step $step): self
    {
        $steps = $this->steps;
        $steps[$index] = $step;

        return new self(array_values($steps), $this->expiresAfter, $this->automatic, $this->reason);
    }

    /** @param array<mixed> $definition */
    public static function fromArray(array $definition): self
    {
        if (($definition['approve'] ?? true) === false) {
            return self::none();
        }

        $automatic = $definition['automatic'] ?? null;

        if ($automatic !== null) {
            if (! is_string($automatic)) {
                throw new InvalidArgumentException('An automatic approval needs a reason.');
            }

            return self::automatic($automatic);
        }

        $steps = $definition['steps'] ?? [];
        $expiresAfter = $definition['expires_after'] ?? null;
        $reason = $definition['reason'] ?? null;

        if ($reason !== null && ! is_string($reason)) {
            throw new InvalidArgumentException('A requirement reason must be text.');
        }

        if (! is_array($steps)) {
            throw new InvalidArgumentException('Approval steps must be a list of step definitions.');
        }

        if ($expiresAfter !== null && ! is_numeric($expiresAfter)) {
            throw new InvalidArgumentException('expires_after must be a number of minutes.');
        }

        $parsed = [];

        foreach ($steps as $step) {
            if (! is_array($step)) {
                throw new InvalidArgumentException('Approval steps must be a list of step definitions.');
            }

            $parsed[] = Step::fromArray($step);
        }

        return new self($parsed, $expiresAfter === null ? null : (int) $expiresAfter, reason: $reason);
    }

    /** @return array{steps: list<array<string, mixed>>, expires_after?: int, automatic?: string, reason?: string} */
    public function toArray(): array
    {
        $definition = ['steps' => array_map(fn (Step $step): array => $step->toArray(), $this->steps)];

        if ($this->automatic !== null) {
            $definition['automatic'] = $this->automatic;
        }

        if ($this->expiresAfter !== null) {
            $definition['expires_after'] = $this->expiresAfter;
        }

        if ($this->reason !== null) {
            $definition['reason'] = $this->reason;
        }

        return $definition;
    }
}
