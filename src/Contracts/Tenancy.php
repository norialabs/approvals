<?php

declare(strict_types=1);

namespace Noria\Approvals\Contracts;

use Closure;
use Illuminate\Database\Eloquent\Model;

interface Tenancy
{
    public function of(Model $subject): int|string|null;

    public function current(): int|string|null;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function within(int|string|null $tenant, Closure $callback): mixed;
}
