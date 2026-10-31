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
    ) {
        if ($expiresAfter !== null && $expiresAfter < 1) {
            throw new InvalidArgumentException('An approval must expire after at least one minute.');
        }
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(Step ...$steps): self
    {
        return new self(array_values($steps));
    }

    public function expiringAfter(?int $minutes): self
    {
        return new self($this->steps, $minutes);
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

        return new self(array_values($steps), $this->expiresAfter);
    }

    /** @param array<mixed> $definition */
    public static function fromArray(array $definition): self
    {
        if (($definition['approve'] ?? true) === false) {
            return self::none();
        }

        $steps = $definition['steps'] ?? [];
        $expiresAfter = $definition['expires_after'] ?? null;

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

        return new self($parsed, $expiresAfter === null ? null : (int) $expiresAfter);
    }

    /** @return array{steps: list<array<string, mixed>>, expires_after?: int} */
    public function toArray(): array
    {
        $definition = ['steps' => array_map(fn (Step $step): array => $step->toArray(), $this->steps)];

        if ($this->expiresAfter !== null) {
            $definition['expires_after'] = $this->expiresAfter;
        }

        return $definition;
    }
}
