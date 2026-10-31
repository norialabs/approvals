<?php

declare(strict_types=1);

namespace Noria\Approvals\Contracts;

use Illuminate\Database\Eloquent\Model;

interface Actors
{
    public function id(): int|string|null;

    public function find(int|string $id): ?Model;
}
