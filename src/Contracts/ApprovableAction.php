<?php

declare(strict_types=1);

namespace Noria\Approvals\Contracts;

use Noria\Approvals\Models\Approval;

interface ApprovableAction
{
    /** @return array<string, mixed> */
    public function payload(): array;

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): static;

    public function handle(?Approval $approval): void;
}
