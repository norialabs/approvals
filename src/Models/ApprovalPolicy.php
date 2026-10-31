<?php

declare(strict_types=1);

namespace Noria\Approvals\Models;

use Illuminate\Database\Eloquent\Model;
use Noria\Approvals\Requirement;
use Noria\Approvals\Settings;

/**
 * @property int|string $id
 * @property string $subject_type
 * @property array<string, mixed> $definition
 * @property bool $active
 */
class ApprovalPolicy extends Model
{
    use Concerns;

    protected $guarded = [];

    public function getTable(): string
    {
        return Settings::table('policies');
    }

    protected static function booted(): void
    {
        static::saving(function (self $policy): void {
            Requirement::fromArray($policy->definition);
        });
    }

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'active' => 'boolean',
        ];
    }

    /** @return array<string, mixed>|null */
    public static function definitionFor(string $subjectType, int|string|null $tenant): ?array
    {
        $query = fn () => static::query()->withoutGlobalScopes()->where('subject_type', $subjectType)->where('active', true)->latest('updated_at');

        if (! Settings::tenancyEnabled()) {
            return $query()->first()?->definition;
        }

        $column = Settings::tenantColumn();
        $own = $tenant === null ? null : $query()->where($column, $tenant)->first();

        return ($own ?? $query()->whereNull($column)->first())?->definition;
    }
}
