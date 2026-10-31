<?php

declare(strict_types=1);

namespace Noria\Approvals\Defaults;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Noria\Approvals\Contracts\Tenancy;
use Noria\Approvals\Settings;

final class ConfiguredTenancy implements Tenancy
{
    public function of(Model $subject): int|string|null
    {
        if (! Settings::tenancyEnabled()) {
            return null;
        }

        $tenant = method_exists($subject, 'approvalTenant')
            ? $subject->approvalTenant()
            : $subject->getAttribute(Settings::tenantColumn());

        return self::key($tenant) ?? $this->current();
    }

    public function current(): int|string|null
    {
        if (! Settings::tenancyEnabled()) {
            return null;
        }

        $user = Auth::user();

        return $user instanceof Model ? self::key($user->getAttribute(Settings::tenantColumn())) : null;
    }

    public function within(int|string|null $tenant, Closure $callback): mixed
    {
        return $callback();
    }

    private static function key(mixed $value): int|string|null
    {
        return is_int($value) || (is_string($value) && $value !== '') ? $value : null;
    }
}
