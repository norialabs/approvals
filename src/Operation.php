<?php

declare(strict_types=1);

namespace Noria\Approvals;

enum Operation: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case Restore = 'restore';
    case ForceDelete = 'force_delete';
    case Relation = 'relation';
    case Action = 'action';

    public function label(): string
    {
        return match ($this) {
            self::ForceDelete => 'Permanently delete',
            default => ucfirst($this->value),
        };
    }
}
