<?php

declare(strict_types=1);

namespace Noria\Approvals;

enum Status: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Declined = 'declined';
    case ChangesRequested = 'changes_requested';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function isOpen(): bool
    {
        return $this === self::Pending;
    }

    public function label(): string
    {
        return match ($this) {
            self::ChangesRequested => 'Changes requested',
            default => ucfirst($this->value),
        };
    }
}
