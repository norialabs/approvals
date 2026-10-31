<?php

declare(strict_types=1);

namespace Noria\Approvals\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Noria\Approvals\Contracts\Tenancy;
use Noria\Approvals\Settings;

/** @phpstan-require-extends Model */
trait Concerns
{
    public static function bootConcerns(): void
    {
        static::addGlobalScope('approvals.tenant', function (Builder $query): void {
            if (! Settings::scopesTenantQueries()) {
                return;
            }

            $tenant = app(Tenancy::class)->current();

            if ($tenant !== null) {
                $query->where($query->qualifyColumn(Settings::tenantColumn()), $tenant);
            }
        });

        static::creating(function (Model $model): void {
            if (! Settings::tenancyEnabled()) {
                return;
            }

            $column = Settings::tenantColumn();

            if ($model->getAttribute($column) === null) {
                $model->setAttribute($column, app(Tenancy::class)->current());
            }
        });
    }

    /** @return bool */
    public function getIncrementing()
    {
        return Settings::keyType() === 'int';
    }

    /** @return string */
    public function getKeyType()
    {
        return Settings::keyType() === 'int' ? 'int' : 'string';
    }

    /** @return bool */
    public function usesUniqueIds()
    {
        return Settings::keyType() !== 'int';
    }

    /** @return list<string> */
    public function uniqueIds()
    {
        return [$this->getKeyName()];
    }

    /** @return string */
    public function newUniqueId()
    {
        return Settings::keyType() === 'uuid' ? (string) Str::orderedUuid() : strtolower((string) Str::ulid());
    }

    /** @param Builder<static> $query */
    public function scopeForTenant(Builder $query, int|string|null $tenant): void
    {
        if (! Settings::tenancyEnabled()) {
            return;
        }

        $query->withoutGlobalScope('approvals.tenant')->where($query->qualifyColumn(Settings::tenantColumn()), $tenant);
    }

    public function tenantKey(): int|string|null
    {
        if (! Settings::tenancyEnabled()) {
            return null;
        }

        $tenant = $this->getAttribute(Settings::tenantColumn());

        return is_int($tenant) || (is_string($tenant) && $tenant !== '') ? $tenant : null;
    }
}
